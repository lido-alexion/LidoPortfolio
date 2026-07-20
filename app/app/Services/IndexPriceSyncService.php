<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class IndexPriceSyncService
{
    public const KEY_CURSOR_SYMBOL = 'index_price_sync_cursor_symbol';

    public const KEY_LAST_RUN_JSON = 'index_price_sync_last_run_json';

    public const KEY_LAST_CYCLE_COMPLETED_AT = 'index_price_sync_last_cycle_completed_at';

    public const KEY_IN_PROGRESS = 'index_price_sync_in_progress';

    public const KEY_IN_PROGRESS_AT = 'index_price_sync_in_progress_at';

    public function __construct(
        protected IndexCatalogService $catalog,
        protected PriceFetchService $priceFetch,
        protected StockPriceHistoryService $history,
        protected PriceHistoryGapService $gaps,
        protected SettingsService $settings,
        protected PortfolioLoggerService $logger,
        protected IndiaVixAlertService $indiaVixAlerts,
    ) {}

    public function isEnabled(): bool
    {
        return $this->catalog->isEnabled();
    }

    public function isSyncInProgress(): bool
    {
        if (Setting::getValue(self::KEY_IN_PROGRESS, '0') !== '1') {
            return false;
        }

        $startedAt = Setting::getValue(self::KEY_IN_PROGRESS_AT);
        if (is_string($startedAt) && $startedAt !== '') {
            try {
                if (Carbon::parse($startedAt)->lt(now()->subMinutes(10))) {
                    $this->clearInProgress();

                    return false;
                }
            } catch (\Throwable) {
                // keep lock
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $stocks = $this->catalog->ensureAllIndexStocks();
        $symbols = $this->catalog->enabledSymbolsOrdered();
        $cursorSymbol = (string) (Setting::getValue(self::KEY_CURSOR_SYMBOL, '') ?? '');
        $cursorIndex = $cursorSymbol === '' ? 0 : max(0, array_search($cursorSymbol, $symbols, true));
        if ($cursorSymbol !== '' && $cursorIndex === false) {
            $cursorIndex = 0;
        }
        $total = count($symbols);
        $processedThrough = $cursorSymbol === '' ? 0 : (int) $cursorIndex + 1;

        $indexRows = [];
        foreach ($stocks as $stock) {
            $bounds = StockPrice::query()
                ->where('stock_id', $stock->id)
                ->selectRaw('MIN(price_date) as min_date, MAX(price_date) as max_date, COUNT(*) as row_count')
                ->first();
            $gapInfo = $this->gaps->gapsForStock($stock);
            $indexRows[] = [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'name' => $stock->name,
                'exchange' => $stock->exchange,
                'yahoo_symbol' => $stock->yahoo_symbol,
                'price_from' => $bounds?->min_date,
                'price_to' => $bounds?->max_date,
                'row_count' => (int) ($bounds->row_count ?? 0),
                'has_gaps' => $gapInfo['has_gaps'],
                'gap_count' => $gapInfo['gap_count'],
                'ranges' => $gapInfo['ranges'],
            ];
        }

        $lastRun = $this->decodeJson(self::KEY_LAST_RUN_JSON);

        return [
            'enabled' => $this->isEnabled(),
            'in_progress' => $this->isSyncInProgress(),
            'in_progress_at' => Setting::getValue(self::KEY_IN_PROGRESS_AT),
            'index_count' => $total,
            'cursor_symbol' => $cursorSymbol !== '' ? $cursorSymbol : null,
            'progress_percent' => $total > 0
                ? round(min(100, ($processedThrough / $total) * 100), 1)
                : 0.0,
            'processed_through' => min($processedThrough, $total),
            'remaining' => max(0, $total - min($processedThrough, $total)),
            'last_cycle_completed_at' => Setting::getValue(self::KEY_LAST_CYCLE_COMPLETED_AT),
            'last_run' => $lastRun,
            'batch_size' => (int) config('portfolio.indexes.batch_size', 3),
            'history_days' => (int) config('portfolio.indexes.history_days', 365),
            'primary_symbol' => $this->catalog->primarySymbol(),
            'indexes' => $indexRows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function syncBatch(
        string $mode = 'daily',
        ?int $batchSize = null,
        bool $resetCursor = false,
        bool $processAll = false,
    ): array {
        if (! $this->isEnabled()) {
            return $this->emptyRun($mode, skipped: 1, reason: 'disabled');
        }

        if ($this->isSyncInProgress()) {
            return $this->emptyRun($mode, skipped: 1, reason: 'in_progress');
        }

        $mode = strtolower($mode) === 'backfill' ? 'backfill' : 'daily';
        $this->catalog->ensureAllIndexStocks();
        $symbols = $this->catalog->enabledSymbolsOrdered();
        if ($symbols === []) {
            return $this->emptyRun($mode, skipped: 1, reason: 'no_indexes');
        }

        if ($resetCursor) {
            $this->setCursorSymbol('');
        }

        $batchSize = $batchSize ?? (int) config('portfolio.indexes.batch_size', 3);
        $batchSize = max(1, $batchSize);

        $this->markInProgress();

        try {
            $cursorSymbol = (string) (Setting::getValue(self::KEY_CURSOR_SYMBOL, '') ?? '');
            $startIndex = 0;
            if ($cursorSymbol !== '') {
                $found = array_search($cursorSymbol, $symbols, true);
                $startIndex = $found === false ? 0 : ((int) $found + 1);
                if ($startIndex >= count($symbols)) {
                    $startIndex = 0;
                }
            }

            $slice = $processAll
                ? array_slice($symbols, $startIndex)
                : array_slice($symbols, $startIndex, $batchSize);

            if ($slice === [] && $startIndex > 0) {
                $startIndex = 0;
                $slice = $processAll ? $symbols : array_slice($symbols, 0, $batchSize);
            }

            $stats = [
                'mode' => $mode,
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'stored_rows' => 0,
                'fetched_rows' => 0,
                'skipped' => 0,
                'cycle_completed' => false,
                'symbols' => [],
                'errors' => [],
                'cursor_before' => $cursorSymbol !== '' ? $cursorSymbol : null,
            ];

            $delayMs = (int) config('portfolio.indexes.delay_ms_between_indexes', 400);
            $lastSymbol = $cursorSymbol;

            PriceSyncNotificationContext::withoutTelegram(function () use (
                $slice,
                $mode,
                $delayMs,
                &$stats,
                &$lastSymbol,
            ): void {
                foreach ($slice as $index => $symbol) {
                    $result = $this->syncOneSymbol($symbol, $mode);
                    $stats['processed']++;
                    $stats['stored_rows'] += (int) ($result['stored_rows'] ?? 0);
                    $stats['fetched_rows'] += (int) ($result['fetched_rows'] ?? 0);
                    $stats['symbols'][] = [
                        'symbol' => $symbol,
                        'success' => (bool) ($result['success'] ?? false),
                        'stored_rows' => (int) ($result['stored_rows'] ?? 0),
                    ];
                    if ($result['success'] ?? false) {
                        $stats['succeeded']++;
                    } else {
                        $stats['failed']++;
                        if (count($stats['errors']) < 20) {
                            $stats['errors'][] = $symbol.': '.implode('; ', $result['errors'] ?? ['sync failed']);
                        }
                    }
                    $lastSymbol = $symbol;
                    if ($delayMs > 0 && $index < count($slice) - 1) {
                        usleep($delayMs * 1000);
                    }
                }
            });

            $lastIndex = array_search($lastSymbol, $symbols, true);
            $cycleCompleted = $lastIndex !== false && (int) $lastIndex === count($symbols) - 1;
            if ($cycleCompleted) {
                $this->setCursorSymbol('');
                Setting::setValue(self::KEY_LAST_CYCLE_COMPLETED_AT, now()->toIso8601String());
                $stats['cycle_completed'] = true;
                $stats['cursor_after'] = null;
            } else {
                $this->setCursorSymbol((string) $lastSymbol);
                $stats['cursor_after'] = $lastSymbol;
            }

            $stats['completed_at'] = now()->toIso8601String();
            Setting::setValue(self::KEY_LAST_RUN_JSON, json_encode($stats, JSON_THROW_ON_ERROR));

            $this->logger->scheduler(
                $stats['failed'] > 0 ? 'warning' : 'info',
                sprintf(
                    'Index price sync (%s): processed=%d ok=%d failed=%d stored=%d',
                    $mode,
                    $stats['processed'],
                    $stats['succeeded'],
                    $stats['failed'],
                    $stats['stored_rows'],
                ),
                array_merge(['category' => 'IndexPriceSync'], $stats),
            );

            $stats['indiavix_alert'] = $this->indiaVixAlerts->evaluateAndNotify();

            return $stats;
        } finally {
            $this->clearInProgress();
        }
    }

    /**
     * Sync a single configured index (used by backfill script / primary daily).
     *
     * @return array{success: bool, stored_rows: int, fetched_rows: int, full_history: bool, from_date: string, to_date: string, errors: array<int, string>}
     */
    public function syncOneSymbol(string $symbol, string $mode = 'daily'): array
    {
        $def = $this->catalog->definitionForSymbol($symbol);
        if ($def === null || ! ($def['enabled'] ?? false)) {
            return [
                'success' => false,
                'stored_rows' => 0,
                'fetched_rows' => 0,
                'full_history' => false,
                'from_date' => '',
                'to_date' => '',
                'errors' => ["Unknown or disabled index: {$symbol}"],
            ];
        }

        $stock = $this->catalog->ensureIndexStock($def);
        $mode = strtolower($mode) === 'backfill' ? 'backfill' : 'daily';

        if ($mode === 'backfill') {
            $to = TradingCalendar::lastRequiredPriceSession()->copy()->startOfDay();
            $from = $to->copy()->subDays((int) config('portfolio.indexes.history_days', 365))->startOfDay();
            $fullHistory = true;
        } else {
            $needsFull = ! $this->history->getCachedAnalyticsHistoryStatus($stock, 6)['cache_hit'];
            $fullHistory = $needsFull;
            $to = now()->startOfDay();
            $from = $needsFull
                ? now()->subDays((int) config('portfolio.indexes.history_days', 365))->startOfDay()
                : now()->subDays($this->dailyLookbackDays())->startOfDay();
        }

        $result = $this->priceFetch->syncStock($stock, $from, $to, notifyTelegramOnFailure: false);

        $payload = [
            'success' => (bool) ($result['success'] ?? false),
            'stored_rows' => (int) ($result['stored_rows'] ?? 0),
            'fetched_rows' => (int) ($result['fetched_rows'] ?? 0),
            'full_history' => $fullHistory,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'errors' => $result['errors'] ?? [],
        ];

        if (($payload['success'] ?? false) && strtoupper($symbol) === IndiaVixAlertService::SYMBOL) {
            $payload['indiavix_alert'] = $this->indiaVixAlerts->evaluateAndNotify();
        }

        return $payload;
    }

    /**
     * Fill gaps for indexes that still have missing ranges (batched by cursor).
     *
     * @return array<string, mixed>
     */
    public function fillGapsBatch(?int $batchSize = null, bool $resetCursor = false): array
    {
        if (! $this->isEnabled()) {
            return $this->emptyRun('gap_fill', skipped: 1, reason: 'disabled');
        }

        if ($this->isSyncInProgress()) {
            return $this->emptyRun('gap_fill', skipped: 1, reason: 'in_progress');
        }

        $this->catalog->ensureAllIndexStocks();
        $gapped = [];
        foreach ($this->catalog->indexStockQuery()->get() as $stock) {
            if ($this->gaps->gapsForStock($stock)['has_gaps']) {
                $gapped[] = $stock->symbol;
            }
        }

        if ($gapped === []) {
            return [
                'mode' => 'gap_fill',
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'stored_rows' => 0,
                'with_gaps' => 0,
                'skipped' => 0,
                'completed' => true,
                'errors' => [],
            ];
        }

        // Reuse sync cursor namespace for gap fill progression across gapped symbols only.
        if ($resetCursor) {
            $this->setCursorSymbol('');
        }

        $batchSize = max(1, $batchSize ?? (int) config('portfolio.indexes.batch_size', 3));
        $cursorSymbol = (string) (Setting::getValue(self::KEY_CURSOR_SYMBOL, '') ?? '');
        $startIndex = 0;
        if ($cursorSymbol !== '') {
            $found = array_search($cursorSymbol, $gapped, true);
            $startIndex = $found === false ? 0 : ((int) $found + 1);
            if ($startIndex >= count($gapped)) {
                $startIndex = 0;
            }
        }

        $slice = array_slice($gapped, $startIndex, $batchSize);
        $this->markInProgress();

        try {
            ['from' => $from, 'to' => $to] = $this->gaps->requiredWindow();
            $stats = [
                'mode' => 'gap_fill',
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'stored_rows' => 0,
                'with_gaps' => count($gapped),
                'errors' => [],
            ];

            $lastSymbol = $cursorSymbol;
            PriceSyncNotificationContext::withoutTelegram(function () use ($slice, $from, $to, &$stats, &$lastSymbol): void {
                foreach ($slice as $symbol) {
                    $stock = Stock::query()->where('symbol', $symbol)->where('is_benchmark', true)->first();
                    if ($stock === null) {
                        continue;
                    }
                    $result = $this->history->fetchMissingHistory($stock, $from, $to, notifyTelegramOnFailure: false);
                    $stats['processed']++;
                    $stats['stored_rows'] += (int) ($result['stored_rows'] ?? 0);
                    if ($result['success'] ?? false) {
                        $stats['succeeded']++;
                    } else {
                        $stats['failed']++;
                        if (count($stats['errors']) < 20) {
                            $stats['errors'][] = $symbol.': '.implode('; ', $result['errors'] ?? ['gap fill failed']);
                        }
                    }
                    $lastSymbol = $symbol;
                }
            });

            $lastIndex = array_search($lastSymbol, $gapped, true);
            if ($lastIndex !== false && (int) $lastIndex >= count($gapped) - 1) {
                $this->setCursorSymbol('');
                $stats['completed'] = true;
            } else {
                $this->setCursorSymbol((string) $lastSymbol);
                $stats['completed'] = false;
            }

            Setting::setValue(self::KEY_LAST_RUN_JSON, json_encode($stats, JSON_THROW_ON_ERROR));

            $stats['indiavix_alert'] = $this->indiaVixAlerts->evaluateAndNotify();

            return $stats;
        } finally {
            $this->clearInProgress();
        }
    }

    public function resetCursor(): void
    {
        $this->setCursorSymbol('');
    }

    protected function markInProgress(): void
    {
        Setting::setValue(self::KEY_IN_PROGRESS, '1');
        Setting::setValue(self::KEY_IN_PROGRESS_AT, now()->toIso8601String());
    }

    protected function clearInProgress(): void
    {
        Setting::setValue(self::KEY_IN_PROGRESS, '0');
        Setting::setValue(self::KEY_IN_PROGRESS_AT, null);
    }

    protected function setCursorSymbol(string $symbol): void
    {
        Cache::forget('setting.'.self::KEY_CURSOR_SYMBOL);
        Setting::setValue(self::KEY_CURSOR_SYMBOL, $symbol);
    }

    protected function dailyLookbackDays(): int
    {
        return max(14, (int) config('portfolio.universe_price_sync.daily_lookback_days', 10));
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyRun(string $mode, int $skipped = 0, string $reason = ''): array
    {
        return [
            'mode' => $mode,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'stored_rows' => 0,
            'fetched_rows' => 0,
            'skipped' => $skipped,
            'cycle_completed' => false,
            'reason' => $reason,
            'errors' => $reason !== '' ? [$reason] : [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeJson(string $key): ?array
    {
        $raw = Setting::getValue($key);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
