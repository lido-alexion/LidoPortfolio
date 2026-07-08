<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\SyncLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class UniversePriceSyncService
{
    public const KEY_CURSOR_STOCK_ID = 'universe_price_sync_cursor_stock_id';

    public const KEY_LAST_CYCLE_COMPLETED_AT = 'universe_price_sync_last_cycle_completed_at';

    public const KEY_IN_PROGRESS = 'universe_price_sync_in_progress';

    public const KEY_IN_PROGRESS_AT = 'universe_price_sync_in_progress_at';

    public const KEY_LAST_RUN_JSON = 'universe_price_sync_last_run_json';

    public function __construct(
        protected UniverseStockResolverService $resolver,
        protected PriceFetchService $priceFetch,
        protected BenchmarkPriceSyncService $benchmarkSync,
        protected SyncLogService $syncLog,
        protected PortfolioLoggerService $logger,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('portfolio.universe_price_sync.enabled', true);
    }

    public function isSyncInProgress(): bool
    {
        if (Setting::getValue(self::KEY_IN_PROGRESS, '0') !== '1') {
            return false;
        }

        $startedAt = Setting::getValue(self::KEY_IN_PROGRESS_AT);
        if ($startedAt && Carbon::parse($startedAt)->lt(now()->subMinutes(30))) {
            $this->clearInProgress();

            return false;
        }

        return true;
    }

    public function markInProgress(): void
    {
        Setting::setValue(self::KEY_IN_PROGRESS, '1');
        Setting::setValue(self::KEY_IN_PROGRESS_AT, now()->toIso8601String());
    }

    public function clearInProgress(): void
    {
        Setting::setValue(self::KEY_IN_PROGRESS, '0');
        Setting::setValue(self::KEY_IN_PROGRESS_AT, null);
    }

    public function maintenanceIntervalMinutes(): int
    {
        return max(1, min(60, (int) config('portfolio.universe_price_sync.maintenance_interval_minutes', 5)));
    }

    public function maintenanceStartHour(): int
    {
        return max(0, min(23, (int) config('portfolio.universe_price_sync.maintenance_start_hour', 19)));
    }

    public function maintenanceEndHour(): int
    {
        return max(0, min(23, (int) config('portfolio.universe_price_sync.maintenance_end_hour', 23)));
    }

    public function maintenanceEndMinute(): int
    {
        return max(0, min(59, (int) config('portfolio.universe_price_sync.maintenance_end_minute', 45)));
    }

    public function isMaintenanceWindowDue(?Carbon $at = null): bool
    {
        $timezone = app(SettingsService::class)->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';
        $now = ($at ?? now())->timezone($timezone);
        $hour = (int) $now->format('G');
        $minute = (int) $now->format('i');

        if ($hour < $this->maintenanceStartHour() || $hour > $this->maintenanceEndHour()) {
            return false;
        }

        if ($hour === $this->maintenanceEndHour() && $minute > $this->maintenanceEndMinute()) {
            return false;
        }

        return $minute % $this->maintenanceIntervalMinutes() === 0;
    }

    public function isMaintenanceWindowStart(?Carbon $at = null): bool
    {
        if (! $this->isMaintenanceWindowDue($at)) {
            return false;
        }

        $timezone = app(SettingsService::class)->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';
        $now = ($at ?? now())->timezone($timezone);

        return (int) $now->format('G') === $this->maintenanceStartHour()
            && (int) $now->format('i') === 0;
    }

    public function maintenanceRunsPerNight(): int
    {
        $interval = $this->maintenanceIntervalMinutes();
        $count = 0;

        for ($hour = $this->maintenanceStartHour(); $hour <= $this->maintenanceEndHour(); $hour++) {
            $minuteLimit = $hour === $this->maintenanceEndHour()
                ? $this->maintenanceEndMinute()
                : 59;

            for ($minute = 0; $minute <= $minuteLimit; $minute += $interval) {
                $count++;
            }
        }

        return $count;
    }

    public function maintenanceNightlyCapacity(?int $batchSize = null): int
    {
        $batchSize ??= (int) config('portfolio.universe_price_sync.batch_size', 125);

        return $this->maintenanceRunsPerNight() * max(1, $batchSize);
    }

    /**
     * @return array{
     *   window_label: string,
     *   interval_minutes: int,
     *   runs_per_night: int,
     *   batch_size: int,
     *   nightly_stock_capacity: int,
     *   nights_for_full_cycle: int
     * }
     */
    public function maintenanceThroughput(int $universeCount): array
    {
        $batchSize = (int) config('portfolio.universe_price_sync.batch_size', 125);
        $runsPerNight = $this->maintenanceRunsPerNight();
        $nightlyCapacity = $runsPerNight * $batchSize;
        $timezone = app(SettingsService::class)->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';

        return [
            'timezone' => $timezone,
            'window_label' => sprintf(
                '%02d:00–%02d:%02d %s',
                $this->maintenanceStartHour(),
                $this->maintenanceEndHour(),
                $this->maintenanceEndMinute(),
                $timezone,
            ),
            'interval_minutes' => $this->maintenanceIntervalMinutes(),
            'runs_per_night' => $runsPerNight,
            'batch_size' => $batchSize,
            'nightly_stock_capacity' => $nightlyCapacity,
            'nights_for_full_cycle' => $universeCount > 0
                ? (int) ceil($universeCount / max(1, $nightlyCapacity))
                : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(?string $scope = null): array
    {
        $scope = $this->resolver->normalizeScope($scope ?? $this->resolver->defaultScope());
        $universeCount = $this->resolver->count($scope);
        $cursorId = (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0');
        $cursorStock = $cursorId > 0 ? Stock::query()->find($cursorId) : null;

        $processedThrough = $universeCount > 0 && $cursorId > 0
            ? $this->resolver->stockQuery($scope)->where('id', '<=', $cursorId)->count()
            : 0;

        $progressPercent = $universeCount > 0
            ? round(min(100, ($processedThrough / $universeCount) * 100), 1)
            : 0.0;

        $lastRun = $this->lastRunStats();
        $latestSyncRun = $this->syncLog->latestRunSummary(SyncLogService::JOB_UNIVERSE_PRICE_SYNC);
        $recentErrors = $this->recentProviderIssues(25);

        return [
            'enabled' => $this->isEnabled(),
            'in_progress' => $this->isSyncInProgress(),
            'scope' => $scope,
            'config' => [
                'history_days' => (int) config('portfolio.universe_price_sync.history_days', 365),
                'daily_lookback_days' => (int) config('portfolio.universe_price_sync.daily_lookback_days', 10),
                'delay_ms_between_stocks' => (int) config('portfolio.universe_price_sync.delay_ms_between_stocks', 400),
                'batch_size' => (int) config('portfolio.universe_price_sync.batch_size', 125),
                'default_scope' => $this->resolver->defaultScope(),
                'maintenance_interval_minutes' => $this->maintenanceIntervalMinutes(),
            ],
            'maintenance' => $this->maintenanceThroughput($universeCount),
            'universe_count' => $universeCount,
            'stocks_with_prices' => $this->countStocksWithPrices($scope),
            'cursor_stock_id' => $cursorId,
            'cursor_symbol' => $cursorStock?->symbol,
            'progress_percent' => $progressPercent,
            'last_cycle_completed_at' => Setting::getValue(self::KEY_LAST_CYCLE_COMPLETED_AT),
            'last_run' => $lastRun,
            'latest_sync_run' => $latestSyncRun,
            'rate_limits' => [
                'last_run_hits' => (int) ($lastRun['rate_limit_hits'] ?? 0),
                'last_run_failure_rate_percent' => $lastRun['failure_rate_percent'] ?? null,
                'likely_rate_limited' => $this->isLikelyRateLimited($lastRun, $recentErrors),
                'recent_issues' => $recentErrors,
            ],
        ];
    }

    /**
     * Prefer the freshest of settings JSON vs portfolio_sync_runs (UI cards were staying on
     * a stale settings row while Sync Logs showed newer batches).
     *
     * @return array<string, mixed>|null
     */
    public function lastRunStats(): ?array
    {
        $fromSettings = $this->lastRunStatsFromSettings();
        $fromSyncRun = $this->lastRunStatsFromSyncLog();

        if ($fromSettings === null) {
            return $fromSyncRun;
        }
        if ($fromSyncRun === null) {
            return $fromSettings;
        }

        $settingsAt = $this->parseTimestamp($fromSettings['completed_at'] ?? null);
        $syncAt = $this->parseTimestamp($fromSyncRun['completed_at'] ?? null);

        if ($syncAt !== null && ($settingsAt === null || $syncAt->gt($settingsAt))) {
            // Heal settings so ops alerts / next reads stay aligned with sync logs.
            Setting::setValue(self::KEY_LAST_RUN_JSON, json_encode($fromSyncRun));
            $this->logger->scheduler('debug', 'Healed stale last_run settings from sync log', [
                'event' => 'universe_last_run_healed',
                'settings_completed_at' => $fromSettings['completed_at'] ?? null,
                'sync_log_completed_at' => $fromSyncRun['completed_at'] ?? null,
                'sync_run_id' => $fromSyncRun['sync_run_id'] ?? null,
            ]);

            return $fromSyncRun;
        }

        return $fromSettings;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function lastRunStatsFromSettings(): ?array
    {
        Cache::forget('setting.'.self::KEY_LAST_RUN_JSON);
        $raw = Setting::getValue(self::KEY_LAST_RUN_JSON);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function lastRunStatsFromSyncLog(): ?array
    {
        $run = $this->syncLog->latestRun(SyncLogService::JOB_UNIVERSE_PRICE_SYNC);
        if ($run === null) {
            return null;
        }

        $completedAt = $run->finished_at ?? $run->started_at;
        $summary = (string) ($run->summary ?? '');
        $parsed = $this->parseUniverseSummary($summary);

        return [
            'scope' => $parsed['scope'] ?? $this->resolver->defaultScope(),
            'mode' => $parsed['mode'] ?? 'daily',
            'universe_count' => $parsed['universe_count'] ?? null,
            'processed' => $parsed['processed'] ?? (int) ($run->stocks_processed ?? 0),
            'succeeded' => $parsed['succeeded'] ?? max(
                0,
                (int) ($run->stocks_processed ?? 0) - (int) ($run->failures ?? 0),
            ),
            'failed' => $parsed['failed'] ?? (int) ($run->failures ?? 0),
            'skipped' => $parsed['skipped'] ?? (int) ($run->skipped ?? 0),
            'stored_rows' => $parsed['stored_rows'] ?? 0,
            'cache_hits' => $parsed['cache_hits'] ?? 0,
            'rate_limit_hits' => $parsed['rate_limit_hits'] ?? 0,
            'cycle_completed' => $parsed['cycle_completed'] ?? false,
            'cursor_stock_id' => $parsed['cursor_stock_id'] ?? null,
            'errors' => [],
            'completed_at' => $completedAt?->toIso8601String(),
            'failure_rate_percent' => $parsed['failure_rate_percent'] ?? null,
            'source' => 'sync_log',
            'sync_run_id' => $run->id,
            'sync_run_status' => $run->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseUniverseSummary(string $summary): array
    {
        $out = [];
        if (preg_match('/Universe price sync \(([^\/]+)\/([^)]+)\):/i', $summary, $m)) {
            $out['mode'] = $m[1];
            $out['scope'] = $m[2];
        }
        foreach ([
            'processed' => 'processed',
            'ok' => 'succeeded',
            'fail' => 'failed',
            'stored' => 'stored_rows',
            'cache_hits' => 'cache_hits',
        ] as $needle => $key) {
            if (preg_match('/\b'.preg_quote($needle, '/').'=(\d+)/i', $summary, $m)) {
                $out[$key] = (int) $m[1];
            }
        }

        if (isset($out['processed'], $out['failed']) && $out['processed'] > 0) {
            $out['failure_rate_percent'] = round(($out['failed'] / $out['processed']) * 100, 1);
        }

        return $out;
    }

    protected function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function looksLikeRateLimit(string $message): bool
    {
        $normalized = strtolower($message);
        $needles = [
            '403',
            '429',
            'rate limit',
            'too many',
            'throttl',
            'blocked',
            'cookie',
            'information',
            'api call frequency',
            'exceeded',
            'temporarily unavailable',
        ];

        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{logged_at: ?string, level: string, message: string, symbol: ?string, context: ?array}>
     */
    public function recentProviderIssues(int $limit = 25): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('portfolio_sync_logs')) {
            return [];
        }

        return SyncLog::query()
            ->where('job_name', SyncLogService::JOB_UNIVERSE_PRICE_SYNC)
            ->whereIn('level', ['warning', 'error'])
            ->orderByDesc('logged_at')
            ->limit($limit)
            ->get()
            ->map(function (SyncLog $log) {
                $context = is_array($log->context) ? $log->context : null;

                return [
                    'logged_at' => $log->logged_at?->toIso8601String(),
                    'level' => $log->level,
                    'message' => $log->message,
                    'symbol' => $context['symbol'] ?? null,
                    'context' => $context,
                    'likely_rate_limit' => self::looksLikeRateLimit(
                        $log->message.' '.json_encode($context ?? []),
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $lastRun
     * @param  list<array<string, mixed>>  $recentIssues
     */
    /**
     * @param  array<string, mixed>|null  $lastRun
     * @param  list<array<string, mixed>>  $recentIssues
     */
    public function isLikelyRateLimitedPublic(?array $lastRun, array $recentIssues): bool
    {
        return $this->isLikelyRateLimited($lastRun, $recentIssues);
    }

    /**
     * @param  array<string, mixed>|null  $lastRun
     * @param  list<array<string, mixed>>  $recentIssues
     */
    protected function isLikelyRateLimited(?array $lastRun, array $recentIssues): bool
    {
        if (($lastRun['rate_limit_hits'] ?? 0) > 0) {
            return true;
        }

        $recentRateHits = collect($recentIssues)
            ->filter(fn (array $row) => ! empty($row['likely_rate_limit']))
            ->count();

        if ($recentRateHits >= 3) {
            return true;
        }

        $processed = (int) ($lastRun['processed'] ?? 0);
        $failed = (int) ($lastRun['failed'] ?? 0);
        if ($processed >= 10 && $failed / $processed >= 0.5) {
            return true;
        }

        return false;
    }

    protected function countStocksWithPrices(string $scope): int
    {
        $stockIds = $this->resolver->stockQuery($scope)->select('id');

        return (int) StockPrice::query()
            ->whereIn('stock_id', $stockIds)
            ->distinct()
            ->count('stock_id');
    }

    /**
     * @return array{
     *   scope: string,
     *   mode: string,
     *   universe_count: int,
     *   processed: int,
     *   succeeded: int,
     *   failed: int,
     *   skipped: int,
     *   stored_rows: int,
     *   cache_hits: int,
     *   rate_limit_hits: int,
     *   cycle_completed: bool,
     *   cursor_stock_id: int,
     *   errors: list<string>
     * }
     */
    public function sync(
        string $mode = 'daily',
        ?string $scope = null,
        ?int $batchSize = null,
        bool $processAll = false,
        bool $resetCursor = false,
    ): array {
        if (! $this->isEnabled()) {
            $this->logger->scheduler('debug', 'Universe sync skipped: disabled', [
                'event' => 'universe_sync_skip',
                'reason' => 'universe_disabled',
                'mode' => $mode,
            ]);

            return $this->emptyResult($scope ?? $this->resolver->defaultScope(), $mode, skipped: 1);
        }

        if ($this->isSyncInProgress()) {
            $this->logger->scheduler('warning', 'Universe sync skipped: already in progress', [
                'event' => 'universe_sync_skip',
                'reason' => 'sync_in_progress_flag',
                'mode' => $mode,
                'in_progress_at' => Setting::getValue(self::KEY_IN_PROGRESS_AT),
            ]);

            return $this->emptyResult($scope ?? $this->resolver->defaultScope(), $mode, skipped: 1);
        }

        $this->markInProgress();

        try {
            return $this->runSync($mode, $scope, $batchSize, $processAll, $resetCursor);
        } finally {
            $this->clearInProgress();
        }
    }

    /**
     * @return array{
     *   scope: string,
     *   mode: string,
     *   universe_count: int,
     *   processed: int,
     *   succeeded: int,
     *   failed: int,
     *   skipped: int,
     *   stored_rows: int,
     *   cache_hits: int,
     *   rate_limit_hits: int,
     *   cycle_completed: bool,
     *   cursor_stock_id: int,
     *   errors: list<string>
     * }
     */
    protected function runSync(
        string $mode = 'daily',
        ?string $scope = null,
        ?int $batchSize = null,
        bool $processAll = false,
        bool $resetCursor = false,
    ): array {
        $scope = $this->resolver->normalizeScope($scope ?? $this->resolver->defaultScope());
        $batchSize = $batchSize ?? (int) config('portfolio.universe_price_sync.batch_size', 125);
        $delayMs = (int) config('portfolio.universe_price_sync.delay_ms_between_stocks', 400);

        $from = $this->rangeFrom($mode);
        $to = now()->startOfDay();

        $this->logger->scheduler('debug', 'Universe sync batch starting', [
            'event' => 'universe_sync_start',
            'mode' => $mode,
            'scope' => $scope,
            'batch_size' => $batchSize,
            'process_all' => $processAll,
            'reset_cursor' => $resetCursor,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'cursor_before' => (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0'),
            'delay_ms' => $delayMs,
        ]);

        $benchmarkResult = $this->benchmarkSync->syncIfNeeded();
        if (! $benchmarkResult['skipped']) {
            $this->logger->scheduler('info', 'NIFTY50 benchmark synced before universe batch', [
                'category' => 'UniversePriceSync',
                'benchmark' => $benchmarkResult,
            ]);
        }

        $universeCount = $this->resolver->count($scope);
        if ($universeCount === 0) {
            $this->logger->scheduler('warning', 'Universe price sync skipped — no stocks in scope', [
                'category' => 'UniversePriceSync',
                'scope' => $scope,
                'mode' => $mode,
            ]);

            return $this->emptyResult($scope, $mode, universeCount: 0);
        }

        if ($resetCursor) {
            $this->setCursor(0);
        }

        $stocks = $processAll
            ? $this->resolver->stockQuery($scope)->get()
            : $this->nextBatch($scope, $batchSize);

        $jobName = SyncLogService::JOB_UNIVERSE_PRICE_SYNC;
        $runId = $this->syncLog->beginRun($jobName);

        $stats = [
            'scope' => $scope,
            'mode' => $mode,
            'universe_count' => $universeCount,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'stored_rows' => 0,
            'cache_hits' => 0,
            'rate_limit_hits' => 0,
            'cycle_completed' => false,
            'cursor_stock_id' => (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0'),
            'errors' => [],
        ];

        $this->syncLog->log($runId, $jobName, 'info', 'Universe price sync started', [
            'scope' => $scope,
            'mode' => $mode,
            'batch_size' => $stocks->count(),
            'process_all' => $processAll,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'universe_count' => $universeCount,
        ]);

        $lastStockId = 0;

        PriceSyncNotificationContext::withoutTelegram(function () use (
            $stocks,
            $from,
            $to,
            $delayMs,
            $runId,
            $jobName,
            &$stats,
            &$lastStockId,
        ) {
            foreach ($stocks as $index => $stock) {
                $lastStockId = $stock->id;
                $stats['processed']++;

                try {
                    $result = $this->priceFetch->syncStock(
                        $stock,
                        $from,
                        $to,
                        notifyTelegramOnFailure: false,
                    );

                    if ($result['success']) {
                        $stats['succeeded']++;
                        $stats['stored_rows'] += (int) ($result['stored_rows'] ?? 0);
                        if (! empty($result['cache_hit'])) {
                            $stats['cache_hits']++;
                        }
                    } else {
                        $stats['failed']++;
                        $error = $stock->symbol.': '.implode('; ', $result['errors'] ?? ['sync failed']);
                        if (self::looksLikeRateLimit($error)) {
                            $stats['rate_limit_hits']++;
                        }
                        if (count($stats['errors']) < 20) {
                            $stats['errors'][] = $error;
                        }
                        $this->syncLog->log($runId, $jobName, 'warning', 'Universe stock sync returned no rows', [
                            'symbol' => $stock->symbol,
                            'errors' => $result['errors'] ?? [],
                        ]);
                    }
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    if (self::looksLikeRateLimit($e->getMessage())) {
                        $stats['rate_limit_hits']++;
                    }
                    if (count($stats['errors']) < 20) {
                        $stats['errors'][] = $stock->symbol.': '.$e->getMessage();
                    }
                    $this->syncLog->log($runId, $jobName, 'error', 'Universe stock sync failed', [
                        'symbol' => $stock->symbol,
                        'failure_reason' => $e->getMessage(),
                    ]);
                }

                if ($delayMs > 0 && $index < $stocks->count() - 1) {
                    usleep($delayMs * 1000);
                }
            }
        });

        if ($lastStockId > 0) {
            $this->setCursor($lastStockId);
            $stats['cursor_stock_id'] = $lastStockId;
        }

        $stats['cycle_completed'] = $this->markCycleIfComplete($scope, $lastStockId, $processAll);
        $stats['cursor_stock_id'] = (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0');
        $stats['completed_at'] = now()->toIso8601String();
        $stats['failure_rate_percent'] = $stats['processed'] > 0
            ? round(($stats['failed'] / $stats['processed']) * 100, 1)
            : 0.0;

        Setting::setValue(self::KEY_LAST_RUN_JSON, json_encode($stats));

        $status = $stats['failed'] === 0 ? 'success' : ($stats['succeeded'] > 0 ? 'partial' : 'failed');
        $summary = sprintf(
            'Universe price sync (%s/%s): processed=%d ok=%d fail=%d stored=%d cache_hits=%d',
            $mode,
            $scope,
            $stats['processed'],
            $stats['succeeded'],
            $stats['failed'],
            $stats['stored_rows'],
            $stats['cache_hits'],
        );

        $this->syncLog->log($runId, $jobName, 'info', 'Universe price sync completed', $stats);
        $this->syncLog->completeRun($runId, $status, [
            'processed' => $stats['processed'],
            'failures' => $stats['failed'],
            'stored_rows' => $stats['stored_rows'],
        ], $summary);

        $this->logger->scheduler('info', $summary, array_merge(['category' => 'UniversePriceSync'], $stats));

        return $stats;
    }

    protected function rangeFrom(string $mode): Carbon
    {
        if ($mode === 'backfill') {
            $days = (int) config('portfolio.universe_price_sync.history_days', 365);

            return now()->subDays($days)->startOfDay();
        }

        $days = (int) config('portfolio.universe_price_sync.daily_lookback_days', 10);

        return now()->subDays($days)->startOfDay();
    }

    /**
     * @return Collection<int, Stock>
     */
    protected function nextBatch(string $scope, int $batchSize): Collection
    {
        $cursor = (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0');
        $query = $this->resolver->stockQuery($scope);

        $stocks = (clone $query)->where('id', '>', $cursor)->limit($batchSize)->get();

        if ($stocks->isEmpty() && $cursor > 0) {
            $stocks = $query->limit($batchSize)->get();
        }

        return $stocks;
    }

    protected function markCycleIfComplete(string $scope, int $lastProcessedId, bool $processAll): bool
    {
        if ($processAll) {
            $this->setCursor(0);
            Setting::setValue(self::KEY_LAST_CYCLE_COMPLETED_AT, now()->toIso8601String());

            return true;
        }

        $maxId = (int) ($this->resolver->stockQuery($scope)->max('id') ?? 0);
        if ($maxId > 0 && $lastProcessedId >= $maxId) {
            $this->setCursor(0);
            Setting::setValue(self::KEY_LAST_CYCLE_COMPLETED_AT, now()->toIso8601String());

            return true;
        }

        return false;
    }

    protected function setCursor(int $stockId): void
    {
        Setting::setValue(self::KEY_CURSOR_STOCK_ID, (string) max(0, $stockId));
    }

    /**
     * @return array{
     *   scope: string,
     *   mode: string,
     *   universe_count: int,
     *   processed: int,
     *   succeeded: int,
     *   failed: int,
     *   skipped: int,
     *   stored_rows: int,
     *   cache_hits: int,
     *   rate_limit_hits: int,
     *   cycle_completed: bool,
     *   cursor_stock_id: int,
     *   errors: list<string>
     * }
     */
    protected function emptyResult(string $scope, string $mode, int $universeCount = 0, int $skipped = 0): array
    {
        return [
            'scope' => $scope,
            'mode' => $mode,
            'universe_count' => $universeCount,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => $skipped,
            'stored_rows' => 0,
            'cache_hits' => 0,
            'rate_limit_hits' => 0,
            'cycle_completed' => false,
            'cursor_stock_id' => (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0'),
            'errors' => [],
            'failure_rate_percent' => 0.0,
        ];
    }
}
