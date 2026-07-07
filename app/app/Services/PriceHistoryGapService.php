<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Stock;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PriceHistoryGapService
{
    public const KEY_CURSOR_STOCK_ID = 'price_history_gap_cursor_stock_id';

    public const KEY_LAST_SCAN_JSON = 'price_history_gap_last_scan_json';

    public const KEY_LAST_FILL_JSON = 'price_history_gap_last_fill_json';

    public const KEY_LAST_CYCLE_COMPLETED_AT = 'price_history_gap_last_cycle_completed_at';

    public function __construct(
        protected UniverseStockResolverService $resolver,
        protected StockPriceHistoryService $history,
        protected RelativeStrengthService $relativeStrength,
        protected SyncLogService $syncLog,
        protected PortfolioLoggerService $logger,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('portfolio.universe_price_sync.enabled', true);
    }

    public function historyWindowDays(): int
    {
        $historyDays = (int) config('portfolio.universe_price_sync.history_days', 365);
        $analyticsDays = (int) config('portfolio.history.analytics_buffer_days.6m', 210);

        return max($historyDays, $analyticsDays);
    }

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    public function requiredWindow(): array
    {
        $to = now()->startOfDay();

        return [
            'from' => $to->copy()->subDays($this->historyWindowDays()),
            'to' => $to,
        ];
    }

    /**
     * @return array{
     *   has_gaps: bool,
     *   gap_count: int,
     *   ranges: array<int, array{from: string, to: string}>
     * }
     */
    public function gapsForStock(Stock $stock): array
    {
        ['from' => $from, 'to' => $to] = $this->requiredWindow();
        $ranges = $this->history->getMissingHistoryRanges($stock, $from, $to);

        return [
            'has_gaps' => $ranges !== [],
            'gap_count' => count($ranges),
            'ranges' => array_map(fn (array $range) => [
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
            ], $ranges),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(?string $scope = null): array
    {
        $scope = $this->resolver->normalizeScope($scope ?? $this->resolver->defaultScope());
        $benchmark = $this->relativeStrength->benchmarkStock();
        $cursorId = (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0');
        $cursorStock = $cursorId > 0 ? Stock::query()->find($cursorId) : null;
        $universeCount = $this->resolver->count($scope);
        $processedThrough = $universeCount > 0 && $cursorId > 0
            ? $this->resolver->stockQuery($scope)->where('id', '<=', $cursorId)->count()
            : 0;

        return [
            'enabled' => $this->isEnabled(),
            'scope' => $scope,
            'history_window_days' => $this->historyWindowDays(),
            'max_internal_gap_days' => (int) config('portfolio.history.max_internal_gap_days', 7),
            'universe_count' => $universeCount,
            'cursor_stock_id' => $cursorId,
            'cursor_symbol' => $cursorStock?->symbol,
            'progress_percent' => $universeCount > 0
                ? round(min(100, ($processedThrough / $universeCount) * 100), 1)
                : 0.0,
            'last_cycle_completed_at' => Setting::getValue(self::KEY_LAST_CYCLE_COMPLETED_AT),
            'benchmark' => array_merge(
                ['symbol' => $benchmark->symbol, 'stock_id' => $benchmark->id],
                $this->gapsForStock($benchmark),
            ),
            'last_scan' => $this->decodeSettingJson(self::KEY_LAST_SCAN_JSON),
            'last_fill' => $this->decodeSettingJson(self::KEY_LAST_FILL_JSON),
            'latest_sync_run' => $this->syncLog->latestRunSummary(SyncLogService::JOB_PRICE_HISTORY_GAP_FILL),
        ];
    }

    /**
     * Scan the next universe batch for OHLCV gaps (no provider fetch).
     *
     * @return array<string, mixed>
     */
    public function scanBatch(
        ?string $scope = null,
        ?int $batchSize = null,
        bool $resetCursor = false,
    ): array {
        if (! $this->isEnabled()) {
            return $this->emptyResult($scope ?? $this->resolver->defaultScope(), skipped: 1);
        }

        $scope = $this->resolver->normalizeScope($scope ?? $this->resolver->defaultScope());
        $batchSize = $batchSize ?? (int) config('portfolio.universe_price_sync.batch_size', 125);

        if ($resetCursor) {
            $this->setCursor(0);
        }

        $benchmark = $this->relativeStrength->benchmarkStock();
        $benchmarkGaps = $this->gapsForStock($benchmark);

        $stocks = $this->nextBatch($scope, $batchSize);
        $stats = $this->baseStats($scope, 'scan', $batchSize);
        $stats['benchmark_has_gaps'] = $benchmarkGaps['has_gaps'];
        $stats['benchmark_gap_count'] = $benchmarkGaps['gap_count'];

        $lastStockId = 0;
        foreach ($stocks as $stock) {
            $lastStockId = $stock->id;
            $stats['scanned']++;
            $gaps = $this->gapsForStock($stock);

            if ($gaps['has_gaps']) {
                $stats['with_gaps']++;
                if (count($stats['symbols_with_gaps']) < 25) {
                    $stats['symbols_with_gaps'][] = [
                        'symbol' => $stock->symbol,
                        'gap_count' => $gaps['gap_count'],
                        'ranges' => $gaps['ranges'],
                    ];
                }
            }
        }

        return $this->finalizeBatch($scope, $stats, $lastStockId, self::KEY_LAST_SCAN_JSON);
    }

    /**
     * Scan and fill OHLCV gaps for the next universe batch.
     *
     * @return array<string, mixed>
     */
    public function fillBatch(
        ?string $scope = null,
        ?int $batchSize = null,
        bool $resetCursor = false,
    ): array {
        if (! $this->isEnabled()) {
            return $this->emptyResult($scope ?? $this->resolver->defaultScope(), skipped: 1);
        }

        $scope = $this->resolver->normalizeScope($scope ?? $this->resolver->defaultScope());
        $batchSize = $batchSize ?? (int) config('portfolio.universe_price_sync.batch_size', 125);
        $delayMs = (int) config('portfolio.universe_price_sync.delay_ms_between_stocks', 400);
        ['from' => $from, 'to' => $to] = $this->requiredWindow();

        if ($resetCursor) {
            $this->setCursor(0);
        }

        $jobName = SyncLogService::JOB_PRICE_HISTORY_GAP_FILL;
        $runId = $this->syncLog->beginRun($jobName);

        $stats = $this->baseStats($scope, 'fill', $batchSize);
        $stats['stored_rows'] = 0;
        $stats['filled'] = 0;
        $stats['failed'] = 0;
        $stats['errors'] = [];

        $this->syncLog->log($runId, $jobName, 'info', 'Price history gap fill started', [
            'scope' => $scope,
            'batch_size' => $batchSize,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
        ]);

        $lastStockId = 0;

        try {
            PriceSyncNotificationContext::withoutTelegram(function () use (
                $scope,
                $batchSize,
                $delayMs,
                $from,
                $to,
                $runId,
                $jobName,
                &$stats,
                &$lastStockId,
            ) {
                $benchmark = $this->relativeStrength->benchmarkStock();
                $benchmarkGaps = $this->gapsForStock($benchmark);
                $stats['benchmark_has_gaps'] = $benchmarkGaps['has_gaps'];
                $stats['benchmark_gap_count'] = $benchmarkGaps['gap_count'];

                if ($benchmarkGaps['has_gaps']) {
                    $benchmarkResult = $this->history->fetchMissingHistory(
                        $benchmark,
                        $from,
                        $to,
                        notifyTelegramOnFailure: false,
                    );
                    $stats['benchmark_filled'] = (bool) ($benchmarkResult['success'] ?? false);
                    $stats['stored_rows'] += (int) ($benchmarkResult['stored_rows'] ?? 0);
                    if (! ($benchmarkResult['success'] ?? false) && count($stats['errors']) < 20) {
                        $stats['errors'][] = $benchmark->symbol.': '.implode('; ', $benchmarkResult['errors'] ?? ['gap fill failed']);
                    }
                } else {
                    $stats['benchmark_filled'] = false;
                }

                $stocks = $this->nextBatch($scope, $batchSize);

                foreach ($stocks as $index => $stock) {
                    $lastStockId = $stock->id;
                    $stats['scanned']++;
                    $gaps = $this->gapsForStock($stock);

                    if (! $gaps['has_gaps']) {
                        continue;
                    }

                    $stats['with_gaps']++;
                    if (count($stats['symbols_with_gaps']) < 25) {
                        $stats['symbols_with_gaps'][] = [
                            'symbol' => $stock->symbol,
                            'gap_count' => $gaps['gap_count'],
                            'ranges' => $gaps['ranges'],
                        ];
                    }

                    try {
                        $result = $this->history->fetchMissingHistory(
                            $stock,
                            $from,
                            $to,
                            notifyTelegramOnFailure: false,
                        );

                        if ($result['success'] ?? false) {
                            $stats['filled']++;
                            $stats['stored_rows'] += (int) ($result['stored_rows'] ?? 0);
                        } else {
                            $stats['failed']++;
                            if (count($stats['errors']) < 20) {
                                $stats['errors'][] = $stock->symbol.': '.implode('; ', $result['errors'] ?? ['gap fill failed']);
                            }
                            $this->syncLog->log($runId, $jobName, 'warning', 'Gap fill returned no rows', [
                                'symbol' => $stock->symbol,
                                'errors' => $result['errors'] ?? [],
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $stats['failed']++;
                        if (count($stats['errors']) < 20) {
                            $stats['errors'][] = $stock->symbol.': '.$e->getMessage();
                        }
                        $this->syncLog->log($runId, $jobName, 'error', 'Gap fill failed', [
                            'symbol' => $stock->symbol,
                            'failure_reason' => $e->getMessage(),
                        ]);
                    }

                    if ($delayMs > 0 && $index < $stocks->count() - 1) {
                        usleep($delayMs * 1000);
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->syncLog->completeRun($runId, 'failed', [
                'processed' => $stats['scanned'],
                'failures' => $stats['failed'],
            ], $e->getMessage());
            throw $e;
        }

        $final = $this->finalizeBatch($scope, $stats, $lastStockId, self::KEY_LAST_FILL_JSON);

        $status = $stats['failed'] === 0 ? 'success' : ($stats['filled'] > 0 ? 'partial' : 'failed');
        $summary = sprintf(
            'Price history gap fill (%s): scanned=%d with_gaps=%d filled=%d failed=%d stored=%d',
            $scope,
            $stats['scanned'],
            $stats['with_gaps'],
            $stats['filled'],
            $stats['failed'],
            $stats['stored_rows'],
        );

        $this->syncLog->log($runId, $jobName, 'info', 'Price history gap fill completed', $final);
        $this->syncLog->completeRun($runId, $status, [
            'processed' => $stats['scanned'],
            'failures' => $stats['failed'],
            'stored_rows' => $stats['stored_rows'],
        ], $summary);

        $this->logger->scheduler('info', $summary, array_merge(['category' => 'PriceHistoryGap'], $final));

        return $final;
    }

    /**
     * Chain fillBatch until the universe cursor cycle completes or limits are hit.
     *
     * @return array<string, mixed>
     */
    public function fillCycle(
        ?string $scope = null,
        ?int $batchSize = null,
        bool $resetCursor = false,
        int $maxBatches = 500,
        ?int $maxSeconds = null,
    ): array {
        if (! $this->isEnabled()) {
            return $this->emptyResult($scope ?? $this->resolver->defaultScope(), skipped: 1);
        }

        $scope = $this->resolver->normalizeScope($scope ?? $this->resolver->defaultScope());
        $batchSize = $batchSize ?? (int) config('portfolio.universe_price_sync.batch_size', 125);
        $maxBatches = max(1, $maxBatches);
        $startedAt = microtime(true);

        $summary = [
            'scope' => $scope,
            'mode' => 'fill_cycle',
            'batch_size' => $batchSize,
            'batches_run' => 0,
            'scanned' => 0,
            'with_gaps' => 0,
            'filled' => 0,
            'failed' => 0,
            'stored_rows' => 0,
            'cycle_completed' => false,
            'stopped_reason' => null,
            'cursor_stock_id' => (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0'),
            'errors' => [],
        ];

        if ($resetCursor) {
            $this->setCursor(0);
        }

        for ($batch = 1; $batch <= $maxBatches; $batch++) {
            if ($maxSeconds !== null && (microtime(true) - $startedAt) >= $maxSeconds) {
                $summary['stopped_reason'] = 'max_seconds';
                break;
            }

            $result = $this->fillBatch($scope, $batchSize, resetCursor: false);
            if (($result['skipped'] ?? 0) > 0) {
                $summary['stopped_reason'] = 'disabled';
                break;
            }

            $summary['batches_run'] = $batch;
            $summary['scanned'] += (int) ($result['scanned'] ?? 0);
            $summary['with_gaps'] += (int) ($result['with_gaps'] ?? 0);
            $summary['filled'] += (int) ($result['filled'] ?? 0);
            $summary['failed'] += (int) ($result['failed'] ?? 0);
            $summary['stored_rows'] += (int) ($result['stored_rows'] ?? 0);
            $summary['cursor_stock_id'] = (int) ($result['cursor_stock_id'] ?? $summary['cursor_stock_id']);
            $summary['cycle_completed'] = (bool) ($result['cycle_completed'] ?? false);

            foreach ($result['errors'] ?? [] as $error) {
                if (count($summary['errors']) < 20) {
                    $summary['errors'][] = $error;
                }
            }

            if ($summary['cycle_completed']) {
                $summary['stopped_reason'] = 'cycle_completed';
                break;
            }
        }

        if ($summary['stopped_reason'] === null) {
            $summary['stopped_reason'] = 'max_batches';
        }

        $summary['completed_at'] = now()->toIso8601String();
        Setting::setValue(self::KEY_LAST_FILL_JSON, json_encode($summary));

        return $summary;
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

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    protected function finalizeBatch(string $scope, array $stats, int $lastStockId, string $settingsKey): array
    {
        if ($lastStockId > 0) {
            $this->setCursor($lastStockId);
            $stats['cursor_stock_id'] = $lastStockId;
        } else {
            $stats['cursor_stock_id'] = (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0');
        }

        $stats['cycle_completed'] = $this->markCycleIfComplete($scope, $lastStockId);
        $stats['cursor_stock_id'] = (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0');
        $stats['completed_at'] = now()->toIso8601String();

        Setting::setValue($settingsKey, json_encode($stats));

        return $stats;
    }

    protected function markCycleIfComplete(string $scope, int $lastProcessedId): bool
    {
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
     * @return array<string, mixed>
     */
    protected function baseStats(string $scope, string $mode, int $batchSize): array
    {
        return [
            'scope' => $scope,
            'mode' => $mode,
            'batch_size' => $batchSize,
            'scanned' => 0,
            'with_gaps' => 0,
            'symbols_with_gaps' => [],
            'cycle_completed' => false,
            'cursor_stock_id' => (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyResult(string $scope, int $skipped = 0): array
    {
        return [
            'scope' => $scope,
            'mode' => 'fill',
            'batch_size' => 0,
            'scanned' => 0,
            'with_gaps' => 0,
            'filled' => 0,
            'failed' => 0,
            'stored_rows' => 0,
            'skipped' => $skipped,
            'symbols_with_gaps' => [],
            'cycle_completed' => false,
            'cursor_stock_id' => (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0'),
            'errors' => [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeSettingJson(string $key): ?array
    {
        $raw = Setting::getValue($key);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
