<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\SyncLog;
use App\Models\SyncRun;
use App\Services\UniversePrice\UniversePriceBatchExecutor;
use App\Services\UniversePrice\UniverseDailyBhavcopySyncService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class UniversePriceSyncService
{
    public const KEY_CURSOR_STOCK_ID = 'universe_price_sync_cursor_stock_id';

    public const KEY_CURSOR_PRIORITY = 'universe_price_sync_cursor_priority';

    public const KEY_LAST_CYCLE_COMPLETED_AT = 'universe_price_sync_last_cycle_completed_at';

    public const KEY_IN_PROGRESS = 'universe_price_sync_in_progress';

    public const KEY_IN_PROGRESS_AT = 'universe_price_sync_in_progress_at';

    public const KEY_LAST_RUN_JSON = 'universe_price_sync_last_run_json';

    protected UniversePriceBatchExecutor $executor;

    public function __construct(
        protected UniverseStockResolverService $resolver,
        protected PriceFetchService $priceFetch,
        protected BenchmarkPriceSyncService $benchmarkSync,
        protected SyncLogService $syncLog,
        protected PortfolioLoggerService $logger,
        ?UniversePriceBatchExecutor $executor = null,
        protected ?UniverseDailyBhavcopySyncService $dailyBulk = null,
    ) {
        $this->executor = $executor ?? new UniversePriceBatchExecutor($this->priceFetch, $this->syncLog);
    }

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
        if ($startedAt && Carbon::parse($startedAt)->lt(now()->subMinutes($this->staleLockMinutes()))) {
            $this->abandonHungSyncRuns();
            $this->clearInProgress();

            return false;
        }

        return true;
    }

    public function staleLockMinutes(): int
    {
        return max(5, min(120, (int) config('portfolio.universe_price_sync.stale_lock_minutes', 30)));
    }

    /**
     * Fail orphan SyncRun rows still marked running (process kill / lock TTL).
     *
     * @return int Number of runs abandoned
     */
    public function abandonHungSyncRuns(?Carbon $olderThan = null): int
    {
        $cutoff = $olderThan ?? now()->subMinutes($this->staleLockMinutes());
        $failed = $this->syncLog->failStaleRunningRuns(
            SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            $cutoff,
        );

        if ($failed > 0) {
            $this->logger->scheduler('warning', 'Abandoned stale universe sync runs', [
                'event' => 'universe_sync_runs_abandoned',
                'count' => $failed,
                'older_than' => $cutoff->toIso8601String(),
            ]);
        }

        return $failed;
    }

    /**
     * Clear in-progress flag and fail any currently running universe sync runs.
     */
    public function clearInProgressAndAbandonRuns(): void
    {
        $this->abandonHungSyncRuns(now());
        $this->clearInProgress();
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

    public function skipsWeekends(): bool
    {
        return (bool) config('portfolio.universe_price_sync.skip_weekends', true);
    }

    public function weekendRetryOnPriorFailures(): bool
    {
        return (bool) config('portfolio.universe_price_sync.weekend_retry_on_prior_session_failures', true);
    }

    public function cronTimezone(): string
    {
        return app(SettingsService::class)->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';
    }

    public function isWithinMaintenanceHours(?Carbon $at = null): bool
    {
        $now = ($at ?? now())->timezone($this->cronTimezone());
        $hour = (int) $now->format('G');
        $minute = (int) $now->format('i');

        if ($hour < $this->maintenanceStartHour() || $hour > $this->maintenanceEndHour()) {
            return false;
        }

        if ($hour === $this->maintenanceEndHour() && $minute > $this->maintenanceEndMinute()) {
            return false;
        }

        return true;
    }

    /**
     * Weekday nights always allowed. Weekends skipped unless prior equity session
     * (typically Friday) had failed/partial universe batches and retry is enabled.
     */
    public function allowsMaintenanceOnCalendarDay(?Carbon $at = null): bool
    {
        $now = ($at ?? now())->timezone($this->cronTimezone());

        if (TradingCalendar::isEquitySessionDate($now)) {
            return true;
        }

        if (! $this->skipsWeekends()) {
            return true;
        }

        if (! $this->weekendRetryOnPriorFailures()) {
            return false;
        }

        return $this->priorEquitySessionHadFailures($now);
    }

    /**
     * True when the last finished universe batch in the prior equity session's
     * maintenance window ended failed or partial (earlier partials that later
     * healed do not trigger weekend retry).
     */
    public function priorEquitySessionHadFailures(?Carbon $at = null): bool
    {
        if (! Schema::hasTable('portfolio_sync_runs')) {
            return false;
        }

        $now = ($at ?? now())->timezone($this->cronTimezone());
        $priorSession = TradingCalendar::normalizeToSessionDate($now->copy()->subDay());

        $windowStart = $priorSession->copy()
            ->setTime($this->maintenanceStartHour(), 0, 0)
            ->utc();
        $windowEnd = $priorSession->copy()
            ->setTime($this->maintenanceEndHour(), $this->maintenanceEndMinute(), 59)
            ->utc();

        $lastRun = SyncRun::query()
            ->where('job_name', SyncLogService::JOB_UNIVERSE_PRICE_SYNC)
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $windowStart)
            ->where('finished_at', '<=', $windowEnd)
            ->orderByDesc('finished_at')
            ->first();

        if ($lastRun === null || ! in_array($lastRun->status, ['failed', 'partial'], true)) {
            return false;
        }

        if ($lastRun->operational_outcome === 'SUCCESS'
            && $this->hasCompleteOperationalSourceEvidence($lastRun)) {
            return false;
        }

        // Keep the externally visible run partial while avoiding a holiday retry
        // when its only failures are stock coverage gaps and every expected
        // exchange file is independently proven healthy for this run.
        return true;
    }

    private function hasCompleteOperationalSourceEvidence(SyncRun $run): bool
    {
        $log = SyncLog::query()->where('run_id', $run->id)
            ->where('message', 'Universe price sync completed')->latest('id')->first();
        $stats = $log?->context;
        $bulk = is_array($stats) ? ($stats['bulk'] ?? null) : null;
        if ($run->job_name !== SyncLogService::JOB_UNIVERSE_PRICE_SYNC
            || ! in_array($run->status, ['success', 'partial', 'failed'], true)
            || ! is_array($bulk)
            || ! is_numeric($stats['processed'] ?? null)
            || ! is_numeric($stats['failed'] ?? null)
            || (int) $stats['processed'] !== (int) $run->stocks_processed
            || (int) $stats['failed'] !== (int) $run->failures
            || (int) ($stats['failed'] ?? -1) !== (int) ($bulk['unavailable_stocks'] ?? -2)
            || ($run->status !== 'success' && (int) ($bulk['unavailable_stocks'] ?? 0) < 1)
            || (int) ($bulk['file_errors'] ?? -1) !== 0
            || (int) ($bulk['identity_conflicts'] ?? -1) !== 0
            || ! is_array($bulk['expected_files'] ?? null)
            || ! is_array($bulk['files'] ?? null)
            || ! is_string($bulk['from_date'] ?? null) || ! is_string($bulk['to_date'] ?? null)
            || ! is_array($bulk['expected_exchanges'] ?? null) || $bulk['expected_exchanges'] === []
            || ! is_array($bulk['outcomes'] ?? null)
            || ! is_string($stats['completed_at'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $stats['completed_at']) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $bulk['from_date']) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $bulk['to_date']) !== 1
            || ! checkdate((int) substr($bulk['from_date'], 5, 2), (int) substr($bulk['from_date'], 8, 2), (int) substr($bulk['from_date'], 0, 4))
            || ! checkdate((int) substr($bulk['to_date'], 5, 2), (int) substr($bulk['to_date'], 8, 2), (int) substr($bulk['to_date'], 0, 4))
            || ! $run->finished_at) {
            return false;
        }

        try {
            if (abs(Carbon::parse($stats['completed_at'])->getTimestamp() - $run->finished_at->getTimestamp()) > 300) return false;
            $from = Carbon::parse($bulk['from_date'], 'Asia/Kolkata')->startOfDay();
            $to = Carbon::parse($bulk['to_date'], 'Asia/Kolkata')->startOfDay();
            if ($to->lt($from) || $from->diffInDays($to, true) > 31) return false;
        } catch (\Throwable) {
            return false;
        }

        $exchanges = [];
        foreach ($bulk['expected_exchanges'] as $exchange) {
            if (! in_array($exchange, ['NSE', 'BSE'], true) || isset($exchanges[$exchange])) return false;
            $exchanges[$exchange] = true;
        }
        if ($exchanges === []) return false;
        $expected = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if (! TradingCalendar::isEquitySessionDate($day)) continue;
            foreach (array_keys($exchanges) as $exchange) $expected[$exchange.':'.$day->toDateString()] = true;
        }
        $declared = [];
        foreach ($bulk['expected_files'] as $file) {
            if (! is_array($file) || ! in_array($file['exchange'] ?? null, ['NSE', 'BSE'], true)
                || ! is_string($file['date'] ?? null)) return false;
            $key = $file['exchange'].':'.$file['date'];
            if (isset($declared[$key])) return false;
            $declared[$key] = true;
        }
        if ($expected === [] || $declared !== $expected) return false;

        $observed = [];
        foreach ($bulk['files'] as $file) {
            if (! is_array($file) || ! in_array($file['exchange'] ?? null, ['NSE', 'BSE'], true)
                || ! is_string($file['date'] ?? null)) return false;
            $key = $file['exchange'].':'.$file['date'];
            if (! isset($expected[$key]) || isset($observed[$key])
                || ! in_array($file['status'] ?? null, ['applied', 'receipt_cached'], true)
                || ! is_string($file['source_revision_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $file['source_revision_sha256']) !== 1
                || ! is_string($file['coverage_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $file['coverage_hash']) !== 1
                || ! is_string($file['checked_at'] ?? null)
                || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $file['checked_at']) !== 1) return false;
            try {
                $checkedAt = Carbon::parse($file['checked_at']);
                if ($checkedAt->gt($run->finished_at->copy()->addMinutes(5))
                    || $checkedAt->lt(Carbon::parse($file['date'], 'Asia/Kolkata')->startOfDay())) return false;
            } catch (\Throwable) {
                return false;
            }
            $observed[$key] = true;
        }

        if (count($observed) !== count($expected)) return false;

        // Operational success requires a receipt-backed per-stock terminal
        // outcome for every stock/session pair. No-data remains a coverage gap;
        // provider and systemic failures remain actionable and visible.
        $outcomes = $bulk['outcomes'];
        foreach (['covered', 'no_data', 'skipped', 'ignored', 'provider_error', 'systemic_error', 'not_expected'] as $key) {
            if (! isset($outcomes[$key]) || ! is_int($outcomes[$key]) || $outcomes[$key] < 0) return false;
        }
        $sessionCount = intdiv(count($expected), count($exchanges));
        if ($sessionCount < 1 || $sessionCount * count($exchanges) !== count($expected)) return false;
        $expectedOutcomeRows = (int) $stats['processed'] * $sessionCount;
        $actualOutcomeRows = array_sum(array_intersect_key($outcomes, array_flip([
            'covered', 'no_data', 'skipped', 'ignored', 'provider_error', 'systemic_error', 'not_expected',
        ])));
        if ($expectedOutcomeRows < 1 || $actualOutcomeRows !== $expectedOutcomeRows
            || $outcomes['provider_error'] !== 0 || $outcomes['systemic_error'] !== 0
            || ! Schema::hasTable('portfolio_universe_price_session_outcomes')) return false;

        $expectedDates = array_values(array_unique(array_map(
            fn (string $key): string => substr($key, strpos($key, ':') + 1),
            array_keys($expected),
        )));
        $rows = \Illuminate\Support\Facades\DB::table('portfolio_universe_price_session_outcomes')
            ->where('run_id', $run->id)->whereIn('session_date', $expectedDates)
            ->get(['stock_id', 'session_date', 'exchange', 'outcome', 'source_revision_sha256']);
        if ($rows->count() !== $expectedOutcomeRows) return false;

        $stockIdsByDate = [];
        $persistedCounts = array_fill_keys(['covered', 'no_data', 'skipped', 'ignored', 'provider_error', 'systemic_error', 'not_expected'], 0);
        $sourceHashes = [];
        foreach ($bulk['files'] as $file) {
            $sourceHashes[$file['exchange'].':'.$file['date']] = $file['source_revision_sha256'];
        }
        foreach ($rows as $row) {
            if (! in_array($row->exchange, array_keys($exchanges), true)
                || ! in_array($row->session_date, $expectedDates, true)
                || ! array_key_exists($row->outcome, $persistedCounts)
                || ! isset($sourceHashes[$row->exchange.':'.$row->session_date])
                || ! is_string($row->source_revision_sha256)
                || ! hash_equals($sourceHashes[$row->exchange.':'.$row->session_date], $row->source_revision_sha256)) return false;
            $stockIdsByDate[$row->session_date][] = (int) $row->stock_id;
            $persistedCounts[$row->outcome]++;
        }
        $stockIdsByDate = array_map(function (array $ids): array {
            sort($ids, SORT_NUMERIC);

            return $ids;
        }, $stockIdsByDate);
        $firstCohort = reset($stockIdsByDate);
        foreach ($expectedDates as $date) {
            if (! isset($stockIdsByDate[$date]) || count($stockIdsByDate[$date]) !== (int) $stats['processed']
                || $stockIdsByDate[$date] !== $firstCohort) return false;
        }

        $loggedCounts = [];
        foreach (array_keys($persistedCounts) as $key) $loggedCounts[$key] = (int) $outcomes[$key];

        return $persistedCounts === $loggedCounts;
    }

    public function isMaintenanceWindowDue(?Carbon $at = null): bool
    {
        if (! $this->isWithinMaintenanceHours($at)) {
            return false;
        }

        if (! $this->allowsMaintenanceOnCalendarDay($at)) {
            return false;
        }

        $now = ($at ?? now())->timezone($this->cronTimezone());
        $minute = (int) $now->format('i');

        return $minute % $this->maintenanceIntervalMinutes() === 0;
    }

    public function isMaintenanceWindowStart(?Carbon $at = null): bool
    {
        if (! $this->isMaintenanceWindowDue($at)) {
            return false;
        }

        $now = ($at ?? now())->timezone($this->cronTimezone());

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
        $nightlyCapacity = $universeCount;
        $timezone = $this->cronTimezone();
        $windowLabel = sprintf(
            '%02d:00–%02d:%02d %s',
            $this->maintenanceStartHour(),
            $this->maintenanceEndHour(),
            $this->maintenanceEndMinute(),
            $timezone,
        );
        if ($this->skipsWeekends()) {
            $windowLabel .= $this->weekendRetryOnPriorFailures()
                ? ' (weekdays; weekend retry when prior session has actionable failures)'
                : ' (weekdays only)';
        }

        return [
            'timezone' => $timezone,
            'window_label' => $windowLabel,
            'interval_minutes' => $this->maintenanceIntervalMinutes(),
            'runs_per_night' => $runsPerNight,
            'batch_size' => $batchSize,
            'daily_collection_method' => UniverseDailyBhavcopySyncService::METHOD,
            'batch_size_applies_to' => 'historical backfill',
            'nightly_stock_capacity' => $nightlyCapacity,
            'nights_for_full_cycle' => $universeCount > 0
                ? 1
                : 0,
            'skip_weekends' => $this->skipsWeekends(),
            'weekend_retry_on_prior_session_failures' => $this->weekendRetryOnPriorFailures(),
            'weekend_retry_active' => ! TradingCalendar::isEquitySessionDate(now()->timezone($timezone))
                && $this->allowsMaintenanceOnCalendarDay(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(?string $scope = null): array
    {
        $scope = $this->resolver->normalizeScope($scope ?? $this->resolver->defaultScope());
        $universeCount = $this->resolver->count($scope);

        $lastRun = $this->lastRunStats();
        $cursorId = $this->resolveCursorStockId($lastRun);
        $cursorPriority = $this->resolveCursorPriority($cursorId);
        $cursorStock = $cursorId > 0 ? Stock::query()->find($cursorId) : null;

        $processedThrough = $universeCount > 0 && $cursorId > 0
            ? $this->resolver->countThroughCursor($scope, $cursorId, $cursorPriority)
            : 0;

        $progressPercent = $universeCount > 0
            ? round(min(100, ($processedThrough / $universeCount) * 100), 1)
            : 0.0;
        if (($lastRun['collection_method'] ?? null) === UniverseDailyBhavcopySyncService::METHOD
            && ($lastRun['scope'] ?? null) === $scope) {
            $processedThrough = min($universeCount, (int) ($lastRun['processed'] ?? 0));
            $progressPercent = $universeCount > 0 ? round(100 * $processedThrough / $universeCount, 1) : 0.0;
        }

        $finishedRun = $this->syncLog->latestFinishedRun(SyncLogService::JOB_UNIVERSE_PRICE_SYNC);
        $latestSyncRun = $finishedRun !== null
            ? $this->syncLog->formatRun($finishedRun)
            : $this->syncLog->latestRunSummary(SyncLogService::JOB_UNIVERSE_PRICE_SYNC);
        $recentErrors = $this->recentProviderIssues(25);
        $lastCycleCompletedAt = $this->resolveLastCycleCompletedAt($lastRun);

        return [
            'enabled' => $this->isEnabled(),
            'in_progress' => $this->isSyncInProgress(),
            'scope' => $scope,
            'config' => [
                'history_days' => (int) config('portfolio.universe_price_sync.history_days', 365),
                'daily_lookback_days' => (int) config('portfolio.universe_price_sync.daily_lookback_days', 10),
                'delay_ms_between_stocks' => (int) config('portfolio.universe_price_sync.delay_ms_between_stocks', 400),
                'batch_size' => (int) config('portfolio.universe_price_sync.batch_size', 125),
                'daily_collection_method' => UniverseDailyBhavcopySyncService::METHOD,
                'default_scope' => $this->resolver->defaultScope(),
                'maintenance_interval_minutes' => $this->maintenanceIntervalMinutes(),
            ],
            'maintenance' => $this->maintenanceThroughput($universeCount),
            'universe_count' => $universeCount,
            'stocks_with_prices' => $this->countStocksWithPrices($scope),
            'cursor_stock_id' => $cursorId,
            'cursor_symbol' => $cursorStock?->symbol,
            'progress_percent' => $progressPercent,
            'processed_through' => $processedThrough,
            'remaining_in_cycle' => max(0, $universeCount - $processedThrough),
            'last_cycle_completed_at' => $lastCycleCompletedAt,
            'last_run' => $lastRun,
            'daily_outcomes' => app(\App\Services\UniversePrice\UniversePriceOutcomeService::class)->summary(),
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
            $this->healCursorFromLastRun($fromSyncRun);
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
        $run = $this->syncLog->latestFinishedRun(SyncLogService::JOB_UNIVERSE_PRICE_SYNC);
        if ($run === null) {
            return null;
        }

        $completedAt = $run->finished_at ?? $run->started_at;
        $summary = (string) ($run->summary ?? '');
        $parsed = $this->parseUniverseSummary($summary);
        $fromContext = $this->completionStatsFromSyncLog($run->id);

        $stats = [
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

        if ($fromContext !== null) {
            $stats = array_merge($stats, array_filter(
                $fromContext,
                static fn ($value) => $value !== null && $value !== [],
            ));
            if (! empty($fromContext['errors'])) {
                $stats['errors'] = $fromContext['errors'];
            }
        }

        return $stats;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function completionStatsFromSyncLog(string $runId): ?array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('portfolio_sync_logs')) {
            return null;
        }

        $row = SyncLog::query()
            ->where('run_id', $runId)
            ->where('job_name', SyncLogService::JOB_UNIVERSE_PRICE_SYNC)
            ->where('message', 'Universe price sync completed')
            ->orderByDesc('logged_at')
            ->first();

        if ($row === null || ! is_array($row->context)) {
            return null;
        }

        $context = $row->context;

        return [
            'scope' => $context['scope'] ?? null,
            'mode' => $context['mode'] ?? null,
            'universe_count' => isset($context['universe_count']) ? (int) $context['universe_count'] : null,
            'processed' => isset($context['processed']) ? (int) $context['processed'] : null,
            'succeeded' => isset($context['succeeded']) ? (int) $context['succeeded'] : null,
            'failed' => isset($context['failed']) ? (int) $context['failed'] : null,
            'skipped' => isset($context['skipped']) ? (int) $context['skipped'] : null,
            'stored_rows' => isset($context['stored_rows']) ? (int) $context['stored_rows'] : null,
            'cache_hits' => isset($context['cache_hits']) ? (int) $context['cache_hits'] : null,
            'rate_limit_hits' => isset($context['rate_limit_hits']) ? (int) $context['rate_limit_hits'] : null,
            'cycle_completed' => (bool) ($context['cycle_completed'] ?? false),
            'cursor_stock_id' => isset($context['cursor_stock_id']) ? (int) $context['cursor_stock_id'] : null,
            'completed_at' => is_string($context['completed_at'] ?? null) ? $context['completed_at'] : null,
            'failure_rate_percent' => isset($context['failure_rate_percent']) ? (float) $context['failure_rate_percent'] : null,
            'errors' => is_array($context['errors'] ?? null) ? $context['errors'] : [],
            'collection_method' => $context['collection_method'] ?? null,
            'bulk' => is_array($context['bulk'] ?? null) ? $context['bulk'] : null,
        ];
    }

    protected function resolveCursorStockId(?array $lastRun): int
    {
        if (($lastRun['collection_method'] ?? null) === UniverseDailyBhavcopySyncService::METHOD) {
            return 0;
        }
        Cache::forget('setting.'.self::KEY_CURSOR_STOCK_ID);
        $cursorId = (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0');

        if ($lastRun === null || ! isset($lastRun['cursor_stock_id'])) {
            return $cursorId;
        }

        $fromRun = (int) $lastRun['cursor_stock_id'];
        if ($fromRun !== $cursorId) {
            $this->healCursorFromLastRun($lastRun);

            return $fromRun;
        }

        return $cursorId;
    }

    /**
     * @param  array<string, mixed>  $lastRun
     */
    protected function healCursorFromLastRun(array $lastRun): void
    {
        if (($lastRun['collection_method'] ?? null) === UniverseDailyBhavcopySyncService::METHOD) {
            return;
        }
        if (! isset($lastRun['cursor_stock_id'])) {
            return;
        }

        $cursorId = (int) $lastRun['cursor_stock_id'];
        Cache::forget('setting.'.self::KEY_CURSOR_STOCK_ID);
        $current = (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0');
        if ($cursorId === $current) {
            return;
        }

        $this->setCursor($cursorId);
        $this->logger->scheduler('debug', 'Healed stale universe cursor from sync log', [
            'event' => 'universe_cursor_healed',
            'settings_cursor_stock_id' => $current,
            'resolved_cursor_stock_id' => $cursorId,
            'sync_run_id' => $lastRun['sync_run_id'] ?? null,
        ]);
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

    /**
     * Keep "last cycle completed at" aligned with the freshest known completion signal.
     * We trust, in order: setting key, cycle_completed=true in last_run payload, and sync-log context.
     */
    protected function resolveLastCycleCompletedAt(?array $lastRun = null): ?string
    {
        $settingAt = $this->parseTimestamp(Setting::getValue(self::KEY_LAST_CYCLE_COMPLETED_AT));
        $lastRunAt = null;
        if (($lastRun['cycle_completed'] ?? false) === true) {
            $lastRunAt = $this->parseTimestamp($lastRun['completed_at'] ?? null);
        }
        $syncLogAt = $this->latestCycleCompletedAtFromSyncLog();

        $candidates = collect([$settingAt, $lastRunAt, $syncLogAt])->filter();
        /** @var Carbon|null $resolved */
        $resolved = $candidates->sortByDesc(fn (Carbon $at) => $at->getTimestamp())->first();
        if ($resolved === null) {
            return null;
        }

        if ($settingAt === null || $resolved->gt($settingAt)) {
            Setting::setValue(self::KEY_LAST_CYCLE_COMPLETED_AT, $resolved->toIso8601String());
            $this->logger->scheduler('debug', 'Healed stale last_cycle_completed_at from sync logs', [
                'event' => 'universe_last_cycle_healed',
                'settings_last_cycle_completed_at' => $settingAt?->toIso8601String(),
                'resolved_last_cycle_completed_at' => $resolved->toIso8601String(),
            ]);
        }

        return $resolved->toIso8601String();
    }

    protected function latestCycleCompletedAtFromSyncLog(): ?Carbon
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('portfolio_sync_logs')) {
            return null;
        }

        $row = SyncLog::query()
            ->where('job_name', SyncLogService::JOB_UNIVERSE_PRICE_SYNC)
            ->whereJsonContains('context->cycle_completed', true)
            ->orderByDesc('logged_at')
            ->first();

        if ($row === null) {
            return null;
        }

        $context = is_array($row->context) ? $row->context : null;
        $completedAt = $this->parseTimestamp($context['completed_at'] ?? null);

        return $completedAt ?? $row->logged_at;
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

        // Require explicit rate-limit-shaped errors — do not treat empty provider
        // responses ("returned 0 rows") or generic high failure rates as throttling.
        return $recentRateHits >= 3;
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
            return $this->syncWithExistingFlag($mode, $scope, $batchSize, $processAll, $resetCursor);
        }
        $result = Cache::store('database')->lock('portfolio-universe-price-sync', ($this->staleLockMinutes() + 5) * 60)
            ->get(fn () => $this->syncWithExistingFlag($mode, $scope, $batchSize, $processAll, $resetCursor));

        return is_array($result) ? $result
            : $this->emptyResult($scope ?? $this->resolver->defaultScope(), $mode, skipped: 1);
    }

    protected function syncWithExistingFlag(
        string $mode, ?string $scope, ?int $batchSize, bool $processAll, bool $resetCursor,
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
        $daily = $mode === 'daily';
        $batchSize = $batchSize ?? (int) config('portfolio.universe_price_sync.batch_size', 125);
        $delayMs = (int) config('portfolio.universe_price_sync.delay_ms_between_stocks', 400);

        // A queue worker may have cached a date before an admin marked it closed.
        TradingCalendar::clearHolidayCache();
        $from = $this->rangeFrom($mode);
        $to = TradingCalendar::normalizeToSessionDate(now('Asia/Kolkata'));

        $this->logger->scheduler('debug', 'Universe sync batch starting', [
            'event' => 'universe_sync_start',
            'mode' => $mode,
            'scope' => $scope,
            'batch_size' => $batchSize,
            'process_all' => $daily || $processAll,
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

        if ($resetCursor && ! $daily) {
            $this->setCursor(0);
        }

        $stocks = $daily || $processAll
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
            'process_all' => $daily || $processAll,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'universe_count' => $universeCount,
        ]);

        try {
            $batchResult = $daily
                ? ($this->dailyBulk ?? app(UniverseDailyBhavcopySyncService::class))
                    ->run($stocks, $from, $to, $runId, $jobName, $stats, $resetCursor)
                : $this->executor->run($stocks, $from, $to, $delayMs, $runId, $jobName, $stats);
            $stats = $batchResult['stats'];
            $stats['run_id'] = $runId;
            $lastStockId = $batchResult['last_stock_id'];

            if (! $daily && $lastStockId > 0) {
                $this->setCursor($lastStockId);
                $stats['cursor_stock_id'] = $lastStockId;
            }

            if ($daily) {
                // Scope inspection is complete; coverage failures remain explicit.
                // Preserve the cursor of an unfinished historical backfill.
                $stats['cycle_completed'] = true;
                $stats['cursor_stock_id'] = 0;
                Setting::setValue(self::KEY_LAST_CYCLE_COMPLETED_AT, now()->toIso8601String());
            } else {
                $stats['cycle_completed'] = $this->markCycleIfComplete($scope, $lastStockId, $processAll);
                $stats['cursor_stock_id'] = (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0');
            }
            $stats['completed_at'] = now()->toIso8601String();
            $stats['failure_rate_percent'] = $stats['processed'] > 0
                ? round(($stats['failed'] / $stats['processed']) * 100, 1)
                : 0.0;

            $bulkErrors = ($stats['bulk']['file_errors'] ?? 0) + ($stats['bulk']['identity_conflicts'] ?? 0);
            $status = $stats['failed'] === 0 && $bulkErrors === 0
                ? 'success' : ($stats['succeeded'] > 0 ? 'partial' : 'failed');
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
            if ($daily) {
                $summary .= sprintf(' source=exchange_bhavcopy files=%d reused=%d unavailable=%d',
                    $stats['bulk']['files_downloaded'] ?? 0, $stats['bulk']['file_cache_hits'] ?? 0,
                    $stats['bulk']['unavailable_stocks'] ?? 0);
            }

            $this->syncLog->log($runId, $jobName, 'info', 'Universe price sync completed', $stats);
            $finishedAt = now();
            $completedRun = $runId ? SyncRun::query()->find($runId) : null;
            $operationalOutcome = null;
            if ($completedRun !== null) {
                $completedRun->status = $status;
                $completedRun->finished_at = $finishedAt;
                $completedRun->stocks_processed = $stats['processed'];
                $completedRun->failures = $stats['failed'];
                if ($this->hasCompleteOperationalSourceEvidence($completedRun)) {
                    $operationalOutcome = 'SUCCESS';
                }
            }
            $this->syncLog->completeRun($runId, $status, [
                'processed' => $stats['processed'],
                'failures' => $stats['failed'],
                'stored_rows' => $stats['stored_rows'],
            ], $summary, $operationalOutcome, [
                self::KEY_LAST_RUN_JSON => json_encode($stats, JSON_THROW_ON_ERROR),
            ]);

            $this->logger->scheduler('info', $summary, array_merge(['category' => 'UniversePriceSync'], $stats));

            return $stats;
        } catch (\Throwable $e) {
            $error = \Illuminate\Support\Str::limit(trim($e->getMessage()), 999, '…');
            $summary = sprintf('Universe price sync aborted (%s/%s): %s: %s',
                $mode, $scope, class_basename($e), $error);

            // Close the durable run before attempting the optional detail log.
            // A failed/oversized log row must never leave the run marked running.
            try {
                $this->syncLog->completeRun($runId, 'failed', [
                    'processed' => $stats['processed'] ?? 0,
                    'failures' => max(1, (int) ($stats['failed'] ?? 0)),
                ], $summary);
            } catch (\Throwable $completionError) {
                $this->logger->scheduler('error', 'Universe price sync failure could not be persisted', [
                    'event' => 'universe_sync_failure_persistence_failed',
                    'run_id' => $runId,
                    'exception' => $completionError::class,
                    'original_exception' => $e::class,
                ]);
            }
            try {
                $this->syncLog->log($runId, $jobName, 'error', $summary, [
                    'event' => 'universe_sync_aborted',
                    'exception' => $e::class,
                    'error' => $error,
                ]);
            } catch (\Throwable $logError) {
                // Keep a systemic operational signal even when database logging is unavailable.
                $this->logger->scheduler('error', 'Universe price sync aborted; sync log persistence failed', [
                    'event' => 'universe_sync_log_persistence_failed',
                    'run_id' => $runId,
                    'exception' => $e::class,
                    'log_exception' => $logError::class,
                    'error' => $error,
                ]);
            }
            $this->logger->scheduler('error', $summary, [
                'category' => 'UniversePriceSync',
                'event' => 'universe_sync_aborted',
                'run_id' => $runId,
                'exception' => $e::class,
                'error' => $error,
            ]);

            throw $e;
        }
    }

    protected function rangeFrom(string $mode): Carbon
    {
        if ($mode === 'backfill') {
            $days = (int) config('portfolio.universe_price_sync.history_days', 365);

            return now('Asia/Kolkata')->subDays($days)->startOfDay();
        }

        $days = (int) config('portfolio.universe_price_sync.daily_lookback_days', 10);

        return now('Asia/Kolkata')->subDays($days)->startOfDay();
    }

    /**
     * @return Collection<int, Stock>
     */
    protected function nextBatch(string $scope, int $batchSize): Collection
    {
        $cursor = (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0');
        $cursorPriority = $this->resolveCursorPriority($cursor);
        $query = $this->resolver->stockQuery($scope);

        $stocks = $this->resolver
            ->applyAfterCursor(clone $query, $cursor, $cursorPriority)
            ->limit($batchSize)
            ->get();

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

        $cursorPriority = $this->resolveCursorPriority($lastProcessedId);
        if ($lastProcessedId > 0 && ! $this->resolver->hasStocksAfterCursor($scope, $lastProcessedId, $cursorPriority)) {
            $this->setCursor(0);
            Setting::setValue(self::KEY_LAST_CYCLE_COMPLETED_AT, now()->toIso8601String());

            return true;
        }

        return false;
    }

    protected function setCursor(int $stockId, ?int $priority = null): void
    {
        $stockId = max(0, $stockId);
        Setting::setValue(self::KEY_CURSOR_STOCK_ID, (string) $stockId);

        if ($stockId <= 0) {
            Setting::setValue(self::KEY_CURSOR_PRIORITY, '0');

            return;
        }

        Setting::setValue(
            self::KEY_CURSOR_PRIORITY,
            (string) ($priority ?? $this->resolver->syncPriorityForStockId($stockId)),
        );
    }

    protected function resolveCursorPriority(int $cursorStockId): int
    {
        if ($cursorStockId <= 0) {
            return EquityUniverseService::SYNC_PRIORITY_HOLDING;
        }

        $stored = Setting::getValue(self::KEY_CURSOR_PRIORITY);
        if ($stored !== null && $stored !== '') {
            return (int) $stored;
        }

        return $this->resolver->syncPriorityForStockId($cursorStockId);
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
            'collection_method' => $mode === 'daily' ? UniverseDailyBhavcopySyncService::METHOD : 'per_stock',
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
