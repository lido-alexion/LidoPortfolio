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

    public const KEY_IN_PROGRESS = 'price_history_gap_in_progress';

    public const KEY_IN_PROGRESS_AT = 'price_history_gap_in_progress_at';

    public const KEY_IN_PROGRESS_MODE = 'price_history_gap_in_progress_mode';

    public const KEY_GAP_INVENTORY_JSON = 'price_history_gap_inventory_json';

    public const KEY_SCAN_PROGRESS_JSON = 'price_history_gap_scan_progress_json';

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

    public function isInProgress(): bool
    {
        if (Setting::getValue(self::KEY_IN_PROGRESS, '0') !== '1') {
            return false;
        }

        $startedAt = Setting::getValue(self::KEY_IN_PROGRESS_AT);
        if (is_string($startedAt) && $startedAt !== '') {
            try {
                if (Carbon::parse($startedAt)->lt(now()->subHours(2))) {
                    $this->clearInProgress();

                    return false;
                }
            } catch (\Throwable) {
                // Keep in-progress if timestamp is malformed.
            }
        }

        return true;
    }

    protected function setInProgress(string $mode): void
    {
        Setting::setValue(self::KEY_IN_PROGRESS, '1');
        Setting::setValue(self::KEY_IN_PROGRESS_AT, now()->toIso8601String());
        Setting::setValue(self::KEY_IN_PROGRESS_MODE, $mode);
    }

    protected function clearInProgress(): void
    {
        Setting::setValue(self::KEY_IN_PROGRESS, '0');
        Setting::setValue(self::KEY_IN_PROGRESS_AT, null);
        Setting::setValue(self::KEY_IN_PROGRESS_MODE, null);
        Setting::setValue(self::KEY_SCAN_PROGRESS_JSON, null);
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

        $lastScan = $this->decodeSettingJson(self::KEY_LAST_SCAN_JSON);
        $inventory = $this->decodeSettingJson(self::KEY_GAP_INVENTORY_JSON);
        $scanProgress = $this->decodeSettingJson(self::KEY_SCAN_PROGRESS_JSON);

        return [
            'enabled' => $this->isEnabled(),
            'in_progress' => $this->isInProgress(),
            'in_progress_mode' => Setting::getValue(self::KEY_IN_PROGRESS_MODE),
            'in_progress_at' => Setting::getValue(self::KEY_IN_PROGRESS_AT),
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
            'last_scan' => $lastScan,
            'inventory_stock_count' => is_array($inventory['stock_ids'] ?? null)
                ? count($inventory['stock_ids'])
                : 0,
            'scan_progress' => $scanProgress,
            'last_fill' => $this->decodeSettingJson(self::KEY_LAST_FILL_JSON),
            'latest_sync_run' => $this->syncLog->latestRunSummary(SyncLogService::JOB_PRICE_HISTORY_GAP_FILL),
        ];
    }

    /**
     * Scan the entire universe for OHLCV gaps (DB-only, no provider fetch).
     *
     * @return array<string, mixed>
     */
    public function scanAll(?string $scope = null): array
    {
        if (! $this->isEnabled()) {
            return $this->emptyResult($scope ?? $this->resolver->defaultScope(), skipped: 1);
        }

        if ($this->isInProgress()) {
            return [
                'scope' => $this->resolver->normalizeScope($scope ?? $this->resolver->defaultScope()),
                'mode' => 'scan_all',
                'skipped' => 1,
                'reason' => 'in_progress',
            ];
        }

        $this->setInProgress('scan');

        try {
            return $this->scanAllInternal($scope);
        } finally {
            $this->clearInProgress();
        }
    }

    /**
     * Scan, then fill only stocks with gaps via providers (single backend run).
     *
     * @return array<string, mixed>
     */
    public function fillAll(?string $scope = null, bool $rescanFirst = true): array
    {
        if (! $this->isEnabled()) {
            return $this->emptyResult($scope ?? $this->resolver->defaultScope(), skipped: 1);
        }

        if ($this->isInProgress()) {
            return [
                'scope' => $this->resolver->normalizeScope($scope ?? $this->resolver->defaultScope()),
                'mode' => 'fill_all',
                'skipped' => 1,
                'reason' => 'in_progress',
            ];
        }

        $scope = $this->resolver->normalizeScope($scope ?? $this->resolver->defaultScope());
        $delayMs = (int) config('portfolio.universe_price_sync.delay_ms_between_stocks', 400);
        ['from' => $from, 'to' => $to] = $this->requiredWindow();

        $this->setInProgress('fill');

        $summary = [
            'scope' => $scope,
            'mode' => 'fill_all',
            'rescan_first' => $rescanFirst,
            'scanned' => 0,
            'with_gaps' => 0,
            'filled' => 0,
            'failed' => 0,
            'stored_rows' => 0,
            'errors' => [],
            'completed_at' => null,
        ];

        try {
            if ($rescanFirst) {
                $scan = $this->scanAllInternal($scope);
                $summary['scanned'] = (int) ($scan['scanned'] ?? 0);
                $summary['with_gaps'] = (int) ($scan['with_gaps'] ?? 0);
            } else {
                $scan = $this->decodeSettingJson(self::KEY_LAST_SCAN_JSON) ?? [];
                $summary['scanned'] = (int) ($scan['scanned'] ?? 0);
                $summary['with_gaps'] = (int) ($scan['with_gaps'] ?? 0);
            }

            $inventory = $this->decodeSettingJson(self::KEY_GAP_INVENTORY_JSON) ?? [];
            $stockIds = is_array($inventory['stock_ids'] ?? null) ? $inventory['stock_ids'] : [];

            $benchmark = $this->relativeStrength->benchmarkStock();
            $benchmarkGaps = $this->gapsForStock($benchmark);
            $summary['benchmark_has_gaps'] = $benchmarkGaps['has_gaps'];
            $summary['benchmark_gap_count'] = $benchmarkGaps['gap_count'];

            if ($benchmarkGaps['has_gaps']) {
                $benchmarkResult = $this->history->fetchMissingHistory(
                    $benchmark,
                    $from,
                    $to,
                    notifyTelegramOnFailure: false,
                );
                $summary['benchmark_filled'] = (bool) ($benchmarkResult['success'] ?? false);
                $summary['stored_rows'] += (int) ($benchmarkResult['stored_rows'] ?? 0);
                if (! ($benchmarkResult['success'] ?? false) && count($summary['errors']) < 20) {
                    $summary['errors'][] = $benchmark->symbol.': '.implode('; ', $benchmarkResult['errors'] ?? ['gap fill failed']);
                }
            }

            if ($stockIds === []) {
                $summary['stopped_reason'] = 'no_gaps';
                $summary['completed_at'] = now()->toIso8601String();
                Setting::setValue(self::KEY_LAST_FILL_JSON, json_encode($summary));

                return $summary;
            }

            $jobName = SyncLogService::JOB_PRICE_HISTORY_GAP_FILL;
            $runId = $this->syncLog->beginRun($jobName);

            $this->syncLog->log($runId, $jobName, 'info', 'Price history gap fill-all started', [
                'scope' => $scope,
                'gap_stock_count' => count($stockIds),
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
            ]);

            try {
                PriceSyncNotificationContext::withoutTelegram(function () use (
                    $stockIds,
                    $from,
                    $to,
                    $delayMs,
                    $runId,
                    $jobName,
                    &$summary,
                ) {
                    foreach ($stockIds as $index => $stockId) {
                        $stock = Stock::query()->find((int) $stockId);
                        if ($stock === null) {
                            continue;
                        }

                        try {
                            $result = $this->history->fetchMissingHistory(
                                $stock,
                                $from,
                                $to,
                                notifyTelegramOnFailure: false,
                            );

                            if ($result['success'] ?? false) {
                                $summary['filled']++;
                                $summary['stored_rows'] += (int) ($result['stored_rows'] ?? 0);
                            } else {
                                $summary['failed']++;
                                if (count($summary['errors']) < 20) {
                                    $summary['errors'][] = $stock->symbol.': '.implode('; ', $result['errors'] ?? ['gap fill failed']);
                                }
                                $this->syncLog->log($runId, $jobName, 'warning', 'Gap fill returned no rows', [
                                    'symbol' => $stock->symbol,
                                    'errors' => $result['errors'] ?? [],
                                ]);
                            }
                        } catch (\Throwable $e) {
                            $summary['failed']++;
                            if (count($summary['errors']) < 20) {
                                $summary['errors'][] = $stock->symbol.': '.$e->getMessage();
                            }
                            $this->syncLog->log($runId, $jobName, 'error', 'Gap fill failed', [
                                'symbol' => $stock->symbol,
                                'failure_reason' => $e->getMessage(),
                            ]);
                        }

                        if ($delayMs > 0 && $index < count($stockIds) - 1) {
                            usleep($delayMs * 1000);
                        }
                    }
                });
            } catch (\Throwable $e) {
                $this->syncLog->completeRun($runId, 'failed', [
                    'processed' => count($stockIds),
                    'failures' => $summary['failed'],
                ], $e->getMessage());
                throw $e;
            }

            $status = $summary['failed'] === 0 ? 'success' : ($summary['filled'] > 0 ? 'partial' : 'failed');
            $runSummary = sprintf(
                'Price history gap fill-all (%s): with_gaps=%d filled=%d failed=%d stored=%d',
                $scope,
                count($stockIds),
                $summary['filled'],
                $summary['failed'],
                $summary['stored_rows'],
            );

            $this->syncLog->log($runId, $jobName, 'info', 'Price history gap fill-all completed', $summary);
            $this->syncLog->completeRun($runId, $status, [
                'processed' => count($stockIds),
                'failures' => $summary['failed'],
                'stored_rows' => $summary['stored_rows'],
            ], $runSummary);

            $refreshed = $this->scanAllInternal($scope);
            $summary['post_fill_scan'] = [
                'scanned' => $refreshed['scanned'] ?? 0,
                'with_gaps' => $refreshed['with_gaps'] ?? 0,
            ];
            $summary['scanned'] = (int) ($refreshed['scanned'] ?? $summary['scanned']);
            $summary['with_gaps'] = (int) ($refreshed['with_gaps'] ?? $summary['with_gaps']);
            $summary['stopped_reason'] = ($refreshed['with_gaps'] ?? 0) === 0 ? 'no_gaps_remaining' : 'completed';
            $summary['completed_at'] = now()->toIso8601String();
            Setting::setValue(self::KEY_LAST_FILL_JSON, json_encode($summary));

            $this->logger->scheduler('info', $runSummary, array_merge([
                'category' => 'PriceHistoryGap',
                'event' => 'gap_fill_all_finish',
            ], $summary));

            return $summary;
        } finally {
            $this->clearInProgress();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function scanAllInternal(?string $scope = null): array
    {
        $scope = $this->resolver->normalizeScope($scope ?? $this->resolver->defaultScope());
        $universeCount = $this->resolver->count($scope);
        $benchmark = $this->relativeStrength->benchmarkStock();
        $benchmarkGaps = $this->gapsForStock($benchmark);

        $stats = [
            'scope' => $scope,
            'mode' => 'scan_all',
            'universe_count' => $universeCount,
            'scanned' => 0,
            'with_gaps' => 0,
            'symbols_with_gaps' => [],
            'gap_stock_ids' => [],
            'benchmark_has_gaps' => $benchmarkGaps['has_gaps'],
            'benchmark_gap_count' => $benchmarkGaps['gap_count'],
            'scan_completed' => false,
            'completed_at' => null,
        ];

        $progressEvery = max(50, (int) config('portfolio.universe_price_sync.gap_scan_progress_every', 250));

        foreach ($this->resolver->stockQuery($scope)->orderBy('id')->cursor() as $stock) {
            $stats['scanned']++;
            $gaps = $this->gapsForStock($stock);

            if ($gaps['has_gaps']) {
                $stats['with_gaps']++;
                $stats['symbols_with_gaps'][] = [
                    'symbol' => $stock->symbol,
                    'stock_id' => $stock->id,
                    'gap_count' => $gaps['gap_count'],
                    'ranges' => $gaps['ranges'],
                ];
                $stats['gap_stock_ids'][] = $stock->id;
            }

            if ($stats['scanned'] % $progressEvery === 0 || $stats['scanned'] === $universeCount) {
                $this->publishScanProgress($stats['scanned'], $universeCount, $stats['with_gaps']);
            }
        }

        $stats['scan_completed'] = true;
        $stats['completed_at'] = now()->toIso8601String();

        Setting::setValue(self::KEY_LAST_SCAN_JSON, json_encode($stats));
        Setting::setValue(self::KEY_GAP_INVENTORY_JSON, json_encode([
            'scope' => $scope,
            'stock_ids' => $stats['gap_stock_ids'],
            'symbols_with_gaps' => $stats['symbols_with_gaps'],
            'scanned_at' => $stats['completed_at'],
            'universe_count' => $universeCount,
            'with_gaps' => $stats['with_gaps'],
        ]));

        return $stats;
    }

    protected function publishScanProgress(int $scanned, int $universeCount, int $withGaps): void
    {
        Setting::setValue(self::KEY_SCAN_PROGRESS_JSON, json_encode([
            'scanned' => $scanned,
            'universe_count' => $universeCount,
            'with_gaps' => $withGaps,
            'progress_percent' => $universeCount > 0
                ? round(min(100, ($scanned / $universeCount) * 100), 1)
                : 0.0,
            'updated_at' => now()->toIso8601String(),
        ]));
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
                $stats['symbols_with_gaps'][] = [
                    'symbol' => $stock->symbol,
                    'gap_count' => $gaps['gap_count'],
                    'ranges' => $gaps['ranges'],
                ];
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

        $this->logger->scheduler('debug', 'Price history gap fill starting', [
            'event' => 'gap_fill_start',
            'scope' => $scope,
            'batch_size' => $batchSize,
            'reset_cursor' => $resetCursor,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'cursor_before' => (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0'),
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
                    $stats['symbols_with_gaps'][] = [
                        'symbol' => $stock->symbol,
                        'gap_count' => $gaps['gap_count'],
                        'ranges' => $gaps['ranges'],
                    ];

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

        $this->logger->scheduler('info', $summary, array_merge([
            'category' => 'PriceHistoryGap',
            'event' => 'gap_fill_finish',
        ], $final));

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
