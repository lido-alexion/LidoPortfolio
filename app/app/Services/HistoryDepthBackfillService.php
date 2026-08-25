<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Stock;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Deepens stored OHLCV to **all available** provider/listing history (OD-17).
 * One cursor-based campaign over indices + the equity universe: per stock it
 * fetches the missing older prefix (`includePreListingPrefix`). Providers
 * return nothing before the listing date — counted `already_deep`, not failed.
 * A full pass records completed mode `all_available` and goes idle. Re-arm
 * with `--reset` (numeric HISTORY_DEPTH_TARGET_DAYS is not a V3 ceiling).
 */
class HistoryDepthBackfillService
{
    public const TARGET_ALL_AVAILABLE = 'all_available';

    public const KEY_CURSOR_STOCK_ID = 'history_depth_cursor_stock_id';

    public const KEY_CURSOR_PRIORITY = 'history_depth_cursor_priority';

    public const KEY_INDEXES_DONE_AT = 'history_depth_indexes_done_at';

    public const KEY_COMPLETED_AT = 'history_depth_completed_at';

    public const KEY_COMPLETED_TARGET_DAYS = 'history_depth_completed_target_days';

    public const KEY_IN_PROGRESS = 'history_depth_in_progress';

    public const KEY_IN_PROGRESS_AT = 'history_depth_in_progress_at';

    public const KEY_LAST_RUN_JSON = 'history_depth_last_run_json';

    public const KEY_PROGRESS_JSON = 'history_depth_progress_json';

    public const JOB_NAME = SyncLogService::JOB_HISTORY_DEPTH_BACKFILL;

    protected const IN_PROGRESS_STALE_MINUTES = 30;

    public function __construct(
        protected UniverseStockResolverService $resolver,
        protected StockPriceHistoryService $history,
        protected UniversePriceSyncService $universeSync,
        protected SyncLogService $syncLog,
        protected PortfolioLoggerService $logger,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('portfolio.history_depth_backfill.enabled', true);
    }

    /**
     * @deprecated OD-17 has no numeric product ceiling. Kept for status BC.
     */
    public function targetHistoryDays(): string
    {
        return self::TARGET_ALL_AVAILABLE;
    }

    /**
     * Campaign is complete after one full all-available pass.
     * Legacy numeric completions (e.g. 550) are treated as incomplete so the
     * campaign re-arms once to deepen beyond the V1 cap.
     */
    public function isCompleted(): bool
    {
        if (! Setting::getValue(self::KEY_COMPLETED_AT)) {
            return false;
        }

        return (string) Setting::getValue(self::KEY_COMPLETED_TARGET_DAYS, '') === self::TARGET_ALL_AVAILABLE;
    }

    /**
     * Scheduler gate: run all day until the campaign completes. Skips a tick
     * while the universe daily sync is mid-batch to avoid provider contention.
     */
    public function isDue(): bool
    {
        return $this->isEnabled()
            && ! $this->isCompleted()
            && ! $this->isRunInProgress()
            && ! $this->universeSync->isSyncInProgress();
    }

    public function isRunInProgress(): bool
    {
        if (Setting::getValue(self::KEY_IN_PROGRESS) !== '1') {
            return false;
        }

        $at = Setting::getValue(self::KEY_IN_PROGRESS_AT);
        if ($at === null) {
            return false;
        }

        try {
            return Carbon::parse($at)->gt(now()->subMinutes(self::IN_PROGRESS_STALE_MINUTES));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Restart the campaign from the first stock (also clears the completed marker).
     */
    public function resetCampaign(): void
    {
        $this->setCursor(0);
        Setting::setValue(self::KEY_INDEXES_DONE_AT, '');
        Setting::setValue(self::KEY_COMPLETED_AT, '');
        Setting::setValue(self::KEY_COMPLETED_TARGET_DAYS, '');
        Setting::setValue(self::KEY_PROGRESS_JSON, json_encode([
            'processed' => 0,
            'deepened' => 0,
            'already_deep' => 0,
            'failed' => 0,
            'stored_rows' => 0,
            'started_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $universeTotal = $this->resolver->count();
        $cursor = (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0');
        $processedThrough = $cursor > 0
            ? $this->resolver->countThroughCursor(null, $cursor, $this->cursorPriority($cursor))
            : 0;
        $progress = $this->decodeJsonSetting(self::KEY_PROGRESS_JSON);

        return [
            'enabled' => $this->isEnabled(),
            'target_history_days' => self::TARGET_ALL_AVAILABLE,
            'target' => self::TARGET_ALL_AVAILABLE,
            'completed_at' => Setting::getValue(self::KEY_COMPLETED_AT) ?: null,
            'completed_target_days' => (string) Setting::getValue(self::KEY_COMPLETED_TARGET_DAYS, ''),
            'indexes_done_at' => Setting::getValue(self::KEY_INDEXES_DONE_AT) ?: null,
            'cursor_stock_id' => $cursor,
            'universe_total' => $universeTotal,
            'processed_through' => $processedThrough,
            'progress' => $progress,
            'last_run' => $this->decodeJsonSetting(self::KEY_LAST_RUN_JSON),
            'in_progress' => $this->isRunInProgress(),
            'due' => $this->isDue(),
        ];
    }

    /**
     * Process one batch: indices first (once per campaign), then the equity
     * universe after the cursor. Safe to call repeatedly; self-terminates.
     *
     * @return array<string,mixed>
     */
    public function runBatch(?int $batchSize = null, bool $resetCampaign = false): array
    {
        if (! $this->isEnabled()) {
            return ['skipped' => true, 'reason' => 'disabled'];
        }

        if ($resetCampaign) {
            $this->resetCampaign();
        }

        if ($this->isCompleted()) {
            return ['skipped' => true, 'reason' => 'completed'];
        }

        if ($this->isRunInProgress()) {
            return ['skipped' => true, 'reason' => 'in_progress'];
        }

        Setting::setValue(self::KEY_IN_PROGRESS, '1');
        Setting::setValue(self::KEY_IN_PROGRESS_AT, now()->toIso8601String());

        try {
            return $this->processBatch($batchSize);
        } finally {
            Setting::setValue(self::KEY_IN_PROGRESS, '0');
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function processBatch(?int $batchSize): array
    {
        $batchSize = max(1, $batchSize ?? (int) config('portfolio.history_depth_backfill.batch_size', 25));
        $delayMs = max(0, (int) config('portfolio.history_depth_backfill.delay_ms_between_stocks', 400));
        $requiredTo = TradingCalendar::lastRequiredPriceSession();
        $requiredFrom = $this->history->allAvailableHistoryFrom();

        $runId = $this->syncLog->beginRun(self::JOB_NAME);

        $stats = [
            'batch_size' => $batchSize,
            'target_history_days' => self::TARGET_ALL_AVAILABLE,
            'required_from' => $requiredFrom->toDateString(),
            'required_to' => $requiredTo->toDateString(),
            'processed' => 0,
            'deepened' => 0,
            'already_deep' => 0,
            'failed' => 0,
            'stored_rows' => 0,
            'indexes_processed' => 0,
            'cycle_completed' => false,
            'errors' => [],
        ];

        // Indices once per campaign — only ~26 rows, cheap enough for one batch.
        if ((string) Setting::getValue(self::KEY_INDEXES_DONE_AT, '') === '') {
            $indexes = Stock::query()
                ->where('is_benchmark', true)
                ->where('is_active', true)
                ->orderBy('symbol')
                ->get();
            foreach ($indexes as $index) {
                $this->deepenStock($index, $requiredFrom, $requiredTo, $stats, $runId);
                $stats['indexes_processed']++;
                $this->pause($delayMs);
            }
            Setting::setValue(self::KEY_INDEXES_DONE_AT, now()->toIso8601String());
        }

        $stocks = $this->nextBatch($batchSize);
        $lastProcessedId = 0;
        foreach ($stocks as $stock) {
            $this->deepenStock($stock, $requiredFrom, $requiredTo, $stats, $runId);
            $lastProcessedId = (int) $stock->id;
            $this->setCursor($lastProcessedId);
            $this->pause($delayMs);
        }

        if ($lastProcessedId > 0
            && ! $this->resolver->hasStocksAfterCursor(null, $lastProcessedId, $this->cursorPriority($lastProcessedId))) {
            $stats['cycle_completed'] = true;
        } elseif ($stocks->isEmpty()) {
            // Nothing after the cursor (empty tail batch) — the pass is done.
            $stats['cycle_completed'] = true;
        }

        $this->accumulateProgress($stats);

        if ($stats['cycle_completed']) {
            $this->setCursor(0);
            Setting::setValue(self::KEY_COMPLETED_AT, now()->toIso8601String());
            Setting::setValue(self::KEY_COMPLETED_TARGET_DAYS, self::TARGET_ALL_AVAILABLE);
        }

        $stats['cursor_stock_id'] = (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0');
        Setting::setValue(self::KEY_LAST_RUN_JSON, json_encode(array_merge($stats, [
            'finished_at' => now()->toIso8601String(),
        ]), JSON_THROW_ON_ERROR));

        $summary = sprintf(
            'History depth backfill: processed %d (deepened %d, already deep %d, failed %d, rows %d)%s',
            $stats['processed'],
            $stats['deepened'],
            $stats['already_deep'],
            $stats['failed'],
            $stats['stored_rows'],
            $stats['cycle_completed'] ? ' — campaign COMPLETE' : '',
        );
        $status = $stats['failed'] > 0
            ? ($stats['deepened'] + $stats['already_deep'] > 0 ? 'partial' : 'failed')
            : 'success';
        $this->syncLog->log($runId, self::JOB_NAME, 'info', 'History depth backfill batch completed', $stats);
        $this->syncLog->completeRun($runId, $status, [
            'processed' => $stats['processed'],
            'failures' => $stats['failed'],
            'stored_rows' => $stats['stored_rows'],
        ], $summary);
        $this->logger->scheduler('info', $summary, array_merge(['category' => 'HistoryDepth'], [
            'cycle_completed' => $stats['cycle_completed'],
            'cursor_stock_id' => $stats['cursor_stock_id'],
        ]));

        return $stats;
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    protected function deepenStock(Stock $stock, Carbon $requiredFrom, Carbon $requiredTo, array &$stats, ?string $runId): void
    {
        $stats['processed']++;

        try {
            $result = $this->history->fetchMissingHistory(
                $stock,
                $requiredFrom->copy(),
                $requiredTo->copy(),
                notifyTelegramOnFailure: false,
                includePreListingPrefix: true,
            );

            $stored = (int) ($result['stored_rows'] ?? 0);
            $stats['stored_rows'] += $stored;

            if (($result['cache_hit'] ?? false) === true) {
                $stats['already_deep']++;

                return;
            }

            if (($result['success'] ?? false) === true || $stored > 0) {
                $stats['deepened']++;

                return;
            }

            // No rows and no success: usually a pre-listing prefix providers
            // cannot serve. Count as processed-but-not-deepened, not a failure,
            // unless the providers reported real errors.
            $errors = array_filter((array) ($result['errors'] ?? []));
            if ($errors !== []) {
                $stats['failed']++;
                if (count($stats['errors']) < 20) {
                    $stats['errors'][] = $stock->symbol.': '.implode('; ', array_slice($errors, 0, 2));
                }
                $this->syncLog->log($runId, self::JOB_NAME, 'warning', 'History deepen failed', [
                    'symbol' => $stock->symbol,
                    'exchange' => $stock->exchange,
                    'errors' => array_slice($errors, 0, 3),
                ]);
            } else {
                $stats['already_deep']++;
            }
        } catch (Throwable $e) {
            $stats['failed']++;
            if (count($stats['errors']) < 20) {
                $stats['errors'][] = $stock->symbol.': '.$e->getMessage();
            }
            $this->syncLog->log($runId, self::JOB_NAME, 'warning', 'History deepen threw', [
                'symbol' => $stock->symbol,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return Collection<int, Stock>
     */
    protected function nextBatch(int $batchSize): Collection
    {
        $cursor = (int) Setting::getValue(self::KEY_CURSOR_STOCK_ID, '0');

        return $this->resolver
            ->applyAfterCursor($this->resolver->stockQuery(), $cursor, $this->cursorPriority($cursor))
            ->limit($batchSize)
            ->get();
    }

    protected function setCursor(int $stockId): void
    {
        $stockId = max(0, $stockId);
        Setting::setValue(self::KEY_CURSOR_STOCK_ID, (string) $stockId);
        Setting::setValue(
            self::KEY_CURSOR_PRIORITY,
            (string) ($stockId > 0 ? $this->resolver->syncPriorityForStockId($stockId) : 0),
        );
    }

    protected function cursorPriority(int $cursorStockId): ?int
    {
        if ($cursorStockId <= 0) {
            return EquityUniverseService::SYNC_PRIORITY_HOLDING;
        }

        $stored = Setting::getValue(self::KEY_CURSOR_PRIORITY);

        return is_numeric($stored)
            ? (int) $stored
            : $this->resolver->syncPriorityForStockId($cursorStockId);
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    protected function accumulateProgress(array $stats): void
    {
        $progress = $this->decodeJsonSetting(self::KEY_PROGRESS_JSON) ?? [
            'processed' => 0,
            'deepened' => 0,
            'already_deep' => 0,
            'failed' => 0,
            'stored_rows' => 0,
            'started_at' => now()->toIso8601String(),
        ];

        foreach (['processed', 'deepened', 'already_deep', 'failed', 'stored_rows'] as $key) {
            $progress[$key] = (int) ($progress[$key] ?? 0) + (int) ($stats[$key] ?? 0);
        }
        $progress['updated_at'] = now()->toIso8601String();

        Setting::setValue(self::KEY_PROGRESS_JSON, json_encode($progress, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function decodeJsonSetting(string $key): ?array
    {
        $raw = Setting::getValue($key);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    protected function pause(int $delayMs): void
    {
        if ($delayMs > 0 && app()->environment() !== 'testing') {
            usleep($delayMs * 1000);
        }
    }
}
