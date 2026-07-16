<?php

namespace App\Services;

use App\Models\IgnoredPriceGap;
use App\Models\Setting;
use App\Models\Stock;
use App\Support\TradingCalendar;
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

    public const KEY_FILL_PROGRESS_JSON = 'price_history_gap_fill_progress_json';

    public const KEY_FILL_CURSOR_INDEX = 'price_history_gap_fill_cursor_index';

    public const KEY_FILL_FAILURE_REPORT_JSON = 'price_history_gap_fill_failure_report_json';

    public function __construct(
        protected UniverseStockResolverService $resolver,
        protected StockPriceHistoryService $history,
        protected RelativeStrengthService $relativeStrength,
        protected SyncLogService $syncLog,
        protected PortfolioLoggerService $logger,
        protected IgnoredPriceGapService $ignoredGaps,
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

        $mode = (string) (Setting::getValue(self::KEY_IN_PROGRESS_MODE, '') ?? '');
        $startedAt = Setting::getValue(self::KEY_IN_PROGRESS_AT);
        $startedAtCarbon = null;
        if (is_string($startedAt) && $startedAt !== '') {
            try {
                $startedAtCarbon = Carbon::parse($startedAt);
                if ($startedAtCarbon->lt(now()->subHours(2))) {
                    $this->clearInProgress();

                    return false;
                }
            } catch (\Throwable) {
                // Keep in-progress if timestamp is malformed.
            }
        }

        // Fill-all runs in short chunks; if a running lock survives too long, recover automatically.
        if ($mode === 'fill') {
            $latestRun = $this->syncLog->latestRunSummary(SyncLogService::JOB_PRICE_HISTORY_GAP_FILL);
            if (is_array($latestRun) && ($latestRun['status'] ?? null) === 'running') {
                $runStarted = $this->parseIsoTimestamp($latestRun['started_at'] ?? null);
                if ($runStarted !== null && $runStarted->lt(now()->subMinutes(8))) {
                    $this->clearInProgress();

                    return false;
                }
            } elseif ($startedAtCarbon !== null && $startedAtCarbon->lt(now()->subMinutes(3))) {
                $this->clearInProgress();

                return false;
            }
        }

        return true;
    }

    protected function setInProgress(string $mode): void
    {
        Setting::setValue(self::KEY_IN_PROGRESS, '1');
        Setting::setValue(self::KEY_IN_PROGRESS_AT, now()->toIso8601String());
        Setting::setValue(self::KEY_IN_PROGRESS_MODE, $mode);
        if ($mode !== 'scan') {
            Setting::setValue(self::KEY_SCAN_PROGRESS_JSON, null);
        }
    }

    protected function clearInProgress(): void
    {
        Setting::setValue(self::KEY_IN_PROGRESS, '0');
        Setting::setValue(self::KEY_IN_PROGRESS_AT, null);
        Setting::setValue(self::KEY_IN_PROGRESS_MODE, null);
        Setting::setValue(self::KEY_SCAN_PROGRESS_JSON, null);
    }

    protected function clearFillProgress(): void
    {
        Setting::setValue(self::KEY_FILL_PROGRESS_JSON, null);
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
        $to = TradingCalendar::lastRequiredPriceSession();

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
        $fillProgress = $this->decodeSettingJson(self::KEY_FILL_PROGRESS_JSON);

        return [
            'enabled' => $this->isEnabled(),
            'in_progress' => $this->isInProgress(),
            'in_progress_mode' => Setting::getValue(self::KEY_IN_PROGRESS_MODE),
            'in_progress_at' => Setting::getValue(self::KEY_IN_PROGRESS_AT),
            'scope' => $scope,
            'history_window_days' => $this->historyWindowDays(),
            'required_through_session' => TradingCalendar::lastRequiredPriceSession()->toDateString(),
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
            'fill_progress' => $fillProgress,
            'last_fill' => $this->decodeSettingJson(self::KEY_LAST_FILL_JSON),
            'last_fill_failure_report' => $this->decodeSettingJson(self::KEY_FILL_FAILURE_REPORT_JSON),
            'latest_sync_run' => $this->syncLog->latestRunSummary(SyncLogService::JOB_PRICE_HISTORY_GAP_FILL),
            'ignored_gap_keys' => $this->ignoredGaps->ignoredKeys(),
            'ignored_gap_count' => IgnoredPriceGap::query()->count(),
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
    public function fillAll(?string $scope = null, bool $rescanFirst = true, ?int $maxStocksPerRun = null): array
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
        $maxStocksPerRun = max(
            1,
            min(100, $maxStocksPerRun ?? (int) config('portfolio.universe_price_sync.gap_fill_all_batch_size', 15)),
        );
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
            if ($this->shouldRescanBeforeFill($scope, $rescanFirst)) {
                $scan = $this->scanAllInternal($scope);
                $summary['scanned'] = (int) ($scan['scanned'] ?? 0);
                $summary['with_gaps'] = (int) ($scan['with_gaps'] ?? 0);
                Setting::setValue(self::KEY_FILL_CURSOR_INDEX, '0');
            } else {
                $scan = $this->decodeSettingJson(self::KEY_LAST_SCAN_JSON) ?? [];
                $summary['scanned'] = (int) ($scan['scanned'] ?? 0);
                $summary['with_gaps'] = (int) ($scan['with_gaps'] ?? 0);
            }

            $inventory = $this->decodeSettingJson(self::KEY_GAP_INVENTORY_JSON) ?? [];
            $stockIds = is_array($inventory['stock_ids'] ?? null) ? $inventory['stock_ids'] : [];
            $totalGapStocks = count($stockIds);
            $fillCursor = max(0, (int) Setting::getValue(self::KEY_FILL_CURSOR_INDEX, '0'));

            if ($fillCursor >= $totalGapStocks) {
                $fillCursor = 0;
                Setting::setValue(self::KEY_FILL_CURSOR_INDEX, '0');
            }

            $benchmark = $this->relativeStrength->benchmarkStock();
            $benchmarkGaps = $this->gapsForStock($benchmark);
            $summary['benchmark_has_gaps'] = $benchmarkGaps['has_gaps'];
            $summary['benchmark_gap_count'] = $benchmarkGaps['gap_count'];

            if ($fillCursor === 0 && $benchmarkGaps['has_gaps']) {
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
                $summary['completed'] = true;
                $summary['total_gap_stocks'] = 0;
                $summary['processed_total'] = 0;
                $summary['remaining'] = 0;
                $summary['completed_at'] = now()->toIso8601String();
                Setting::setValue(self::KEY_LAST_FILL_JSON, $this->encodeSettingJson($summary));

                return $summary;
            }

            $stockIdsToProcess = array_slice($stockIds, $fillCursor, $maxStocksPerRun);

            if ($fillCursor === 0) {
                $this->initFillFailureReport($scope, $totalGapStocks);
            }

            $this->publishFillProgress($fillCursor, $totalGapStocks, 0, 0);

            $jobName = SyncLogService::JOB_PRICE_HISTORY_GAP_FILL;
            $runId = $this->syncLog->beginRun($jobName);

            $this->syncLog->log($runId, $jobName, 'info', 'Price history gap fill-all started', [
                'scope' => $scope,
                'gap_stock_count' => $totalGapStocks,
                'processing_now' => count($stockIdsToProcess),
                'fill_cursor' => $fillCursor,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
            ]);

            try {
                PriceSyncNotificationContext::withoutTelegram(function () use (
                    $stockIdsToProcess,
                    $from,
                    $to,
                    $delayMs,
                    $runId,
                    $jobName,
                    $fillCursor,
                    $totalGapStocks,
                    &$summary,
                ) {
                    foreach ($stockIdsToProcess as $index => $stockId) {
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
                                $this->appendFillFailure($stock, $result);
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
                            $this->appendFillFailure($stock, [
                                'success' => false,
                                'errors' => [$e->getMessage()],
                                'attempted_ranges' => [],
                                'remaining_ranges' => [],
                                'providers_tried' => [],
                            ]);
                            if (count($summary['errors']) < 20) {
                                $summary['errors'][] = $stock->symbol.': '.$e->getMessage();
                            }
                            $this->syncLog->log($runId, $jobName, 'error', 'Gap fill failed', [
                                'symbol' => $stock->symbol,
                                'failure_reason' => $e->getMessage(),
                            ]);
                        }

                        $this->publishFillProgress(
                            $fillCursor + $index + 1,
                            $totalGapStocks,
                            (int) ($summary['filled'] ?? 0),
                            (int) ($summary['failed'] ?? 0),
                        );

                        if ($delayMs > 0 && $index < count($stockIdsToProcess) - 1) {
                            usleep($delayMs * 1000);
                        }
                    }
                });
            } catch (\Throwable $e) {
                $this->syncLog->completeRun($runId, 'failed', [
                    'processed' => count($stockIdsToProcess),
                    'failures' => $summary['failed'],
                ], $e->getMessage());
                $summary['errors'][] = 'fill_all_aborted: '.$e->getMessage();
                $summary['stopped_reason'] = 'error';
                $summary['completed'] = false;
                Setting::setValue(self::KEY_LAST_FILL_JSON, $this->encodeSettingJson($summary));
                $this->logger->scheduler('error', 'Price history gap fill-all aborted', [
                    'category' => 'PriceHistoryGap',
                    'event' => 'gap_fill_all_error',
                    'failure_reason' => $e->getMessage(),
                ]);

                return $summary;
            }

            $processedThisRun = count($stockIdsToProcess);
            $nextCursor = $fillCursor + $processedThisRun;
            $completed = $nextCursor >= $totalGapStocks;
            $processedTotal = min($nextCursor, $totalGapStocks);
            $remaining = max(0, $totalGapStocks - $processedTotal);

            $status = $summary['failed'] === 0 ? 'success' : ($summary['filled'] > 0 ? 'partial' : 'failed');
            $runSummary = sprintf(
                'Price history gap fill-all (%s): chunk=%d/%d filled=%d failed=%d stored=%d',
                $scope,
                $processedTotal,
                $totalGapStocks,
                $summary['filled'],
                $summary['failed'],
                $summary['stored_rows'],
            );

            $summary['total_gap_stocks'] = $totalGapStocks;
            $summary['processed_before'] = $fillCursor;
            $summary['processed_this_run'] = $processedThisRun;
            $summary['processed_total'] = $processedTotal;
            $summary['remaining'] = $remaining;
            $summary['completed'] = $completed;
            $summary['stopped_reason'] = $completed ? 'completed' : 'max_batch_size';

            if ($completed) {
                Setting::setValue(self::KEY_FILL_CURSOR_INDEX, '0');
                $this->clearFillProgress();
                $summary['completed_at'] = now()->toIso8601String();
                $summary['still_with_gaps'] = $this->countStocksStillWithGaps($stockIds);
                $this->finalizeFillFailureReport(
                    resolved: max(0, $totalGapStocks - (int) $summary['still_with_gaps']),
                    unresolved: (int) $summary['still_with_gaps'],
                );
            } else {
                Setting::setValue(self::KEY_FILL_CURSOR_INDEX, (string) $nextCursor);
            }

            $this->syncLog->log($runId, $jobName, 'info', 'Price history gap fill-all completed', $summary);
            $this->syncLog->completeRun($runId, $status, [
                'processed' => $processedThisRun,
                'failures' => $summary['failed'],
                'stored_rows' => $summary['stored_rows'],
            ], $runSummary);
            Setting::setValue(self::KEY_LAST_FILL_JSON, $this->encodeSettingJson($summary));

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
     * Clear persisted gap scan inventory and fill failure report (admin UI reset).
     *
     * @return array<string, mixed>
     */
    public function clearReports(?string $scope = null): array
    {
        if ($this->isInProgress()) {
            return [
                'scope' => $this->resolver->normalizeScope($scope ?? $this->resolver->defaultScope()),
                'cleared' => false,
                'skipped' => 1,
                'reason' => 'in_progress',
            ];
        }

        $scope = $this->resolver->normalizeScope($scope ?? $this->resolver->defaultScope());

        Setting::setValue(self::KEY_LAST_SCAN_JSON, null);
        Setting::setValue(self::KEY_GAP_INVENTORY_JSON, null);
        Setting::setValue(self::KEY_FILL_FAILURE_REPORT_JSON, null);
        Setting::setValue(self::KEY_LAST_FILL_JSON, null);
        Setting::setValue(self::KEY_SCAN_PROGRESS_JSON, null);
        Setting::setValue(self::KEY_FILL_CURSOR_INDEX, '0');
        $this->clearFillProgress();

        return [
            'scope' => $scope,
            'cleared' => true,
        ];
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
                    'exchange' => $stock->exchange,
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

        $this->persistFullScanResults($scope, $stats, $universeCount);

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    protected function persistFullScanResults(string $scope, array $stats, int $universeCount): void
    {
        $stored = $stats;
        $stored['symbols_with_gaps'] = $this->compactGapSymbolRows($stats['symbols_with_gaps'] ?? []);
        unset($stored['gap_stock_ids']);

        Setting::setValue(self::KEY_LAST_SCAN_JSON, $this->encodeSettingJson($stored));
        Setting::setValue(self::KEY_GAP_INVENTORY_JSON, $this->encodeSettingJson([
            'scope' => $scope,
            'stock_ids' => $stats['gap_stock_ids'] ?? [],
            'scanned_at' => $stats['completed_at'] ?? now()->toIso8601String(),
            'universe_count' => $universeCount,
            'with_gaps' => $stats['with_gaps'] ?? 0,
        ]));
        Setting::setValue(self::KEY_FILL_CURSOR_INDEX, '0');
        $this->clearFillProgress();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function compactGapSymbolRows(array $rows): array
    {
        return array_map(static fn (array $row) => array_filter([
            'symbol' => $row['symbol'] ?? null,
            'stock_id' => $row['stock_id'] ?? null,
            'exchange' => $row['exchange'] ?? null,
            'gap_count' => $row['gap_count'] ?? 0,
            'ranges' => $row['ranges'] ?? null,
        ], static fn ($value) => $value !== null), $rows);
    }

    protected function initFillFailureReport(string $scope, int $totalGapStocks): void
    {
        Setting::setValue(self::KEY_FILL_FAILURE_REPORT_JSON, $this->encodeSettingJson([
            'scope' => $scope,
            'started_at' => now()->toIso8601String(),
            'completed_at' => null,
            'total_gap_stocks' => $totalGapStocks,
            'resolved' => 0,
            'unresolved' => 0,
            'failure_count' => 0,
            'failures' => [],
        ]));
    }

    /**
     * @param  array<string, mixed>  $fetchResult
     */
    protected function appendFillFailure(Stock $stock, array $fetchResult): void
    {
        $report = $this->decodeSettingJson(self::KEY_FILL_FAILURE_REPORT_JSON);
        if ($report === null) {
            return;
        }

        $failures = is_array($report['failures'] ?? null) ? $report['failures'] : [];
        $failures[] = [
            'symbol' => $stock->symbol,
            'stock_id' => $stock->id,
            'exchange' => $stock->exchange,
            'attempted_ranges' => array_slice($fetchResult['attempted_ranges'] ?? [], 0, 4),
            'remaining_ranges' => array_slice($fetchResult['remaining_ranges'] ?? [], 0, 4),
            'providers_tried' => array_values(array_unique($fetchResult['providers_tried'] ?? [])),
            'errors' => array_slice($fetchResult['errors'] ?? [], 0, 8),
        ];
        if (count($failures) > 500) {
            $failures = array_slice($failures, -500);
        }
        $report['failures'] = $failures;
        $report['failure_count'] = count($failures);

        Setting::setValue(self::KEY_FILL_FAILURE_REPORT_JSON, $this->encodeSettingJson($this->trimFailureReportForStorage($report)));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    protected function trimFailureReportForStorage(array $report): array
    {
        $failures = is_array($report['failures'] ?? null) ? $report['failures'] : [];
        $report['failures'] = array_slice($failures, -500);

        return $report;
    }

    protected function finalizeFillFailureReport(int $resolved, int $unresolved): void
    {
        $report = $this->decodeSettingJson(self::KEY_FILL_FAILURE_REPORT_JSON);
        if ($report === null) {
            return;
        }

        $report['completed_at'] = now()->toIso8601String();
        $report['resolved'] = $resolved;
        $report['unresolved'] = $unresolved;
        $report['failure_count'] = is_array($report['failures'] ?? null) ? count($report['failures']) : 0;

        Setting::setValue(self::KEY_FILL_FAILURE_REPORT_JSON, $this->encodeSettingJson($this->trimFailureReportForStorage($report)));
    }

    protected function encodeSettingJson(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    protected function parseIsoTimestamp(mixed $value): ?Carbon
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

    protected function publishFillProgress(int $processedTotal, int $totalGapStocks, int $filled, int $failed): void
    {
        Setting::setValue(self::KEY_FILL_PROGRESS_JSON, json_encode([
            'processed_total' => $processedTotal,
            'total_gap_stocks' => $totalGapStocks,
            'filled' => $filled,
            'failed' => $failed,
            'progress_percent' => $totalGapStocks > 0
                ? round(min(100, ($processedTotal / $totalGapStocks) * 100), 1)
                : 0.0,
            'updated_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * @param  array<int, int>  $stockIds
     */
    protected function countStocksStillWithGaps(array $stockIds): int
    {
        $count = 0;

        foreach ($stockIds as $stockId) {
            $stock = Stock::query()->find((int) $stockId);
            if ($stock === null) {
                continue;
            }

            if ($this->gapsForStock($stock)['has_gaps']) {
                $count++;
            }
        }

        return $count;
    }

    protected function shouldRescanBeforeFill(string $scope, bool $rescanFirst): bool
    {
        if (! $rescanFirst) {
            return false;
        }

        $inventory = $this->decodeSettingJson(self::KEY_GAP_INVENTORY_JSON);
        if ($inventory === null || ($inventory['scope'] ?? '') !== $scope) {
            return true;
        }

        $stockIds = $inventory['stock_ids'] ?? null;
        if (! is_array($stockIds) || $stockIds === []) {
            return true;
        }

        $lastScan = $this->decodeSettingJson(self::KEY_LAST_SCAN_JSON);
        if (($lastScan['scan_completed'] ?? false) !== true) {
            return true;
        }

        $scannedAt = $this->parseIsoTimestamp($inventory['scanned_at'] ?? $lastScan['completed_at'] ?? null);

        return $scannedAt === null || $scannedAt->lt(now()->subHours(24));
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
                    'stock_id' => $stock->id,
                    'exchange' => $stock->exchange,
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
                        'stock_id' => $stock->id,
                        'exchange' => $stock->exchange,
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
        Setting::setValue(self::KEY_LAST_FILL_JSON, $this->encodeSettingJson($summary));

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

        if ($settingsKey === self::KEY_LAST_SCAN_JSON && isset($stats['symbols_with_gaps'])) {
            $stored = $stats;
            $stored['symbols_with_gaps'] = $this->compactGapSymbolRows($stats['symbols_with_gaps']);
            Setting::setValue($settingsKey, $this->encodeSettingJson($stored));
        } else {
            Setting::setValue($settingsKey, $this->encodeSettingJson($stats));
        }

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
