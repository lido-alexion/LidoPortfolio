<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\CorporateAction;
use App\Models\Holding;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockMetric;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\WatchlistItem;
use App\Support\TradingCalendar;
use Illuminate\Support\Facades\DB;

class DualListedNseRepairService
{
    public const BACKFILL_CURSOR_KEY = 'dual_listed_nse_repair_backfill_cursor_id';

    public function __construct(
        protected StockPriceHistoryService $priceHistory,
    ) {}

    /**
     * @return array{
     *   pairs_found: int,
     *   bse_rows_deactivated: int,
     *   bse_prices_deleted: int,
     *   bse_metrics_deleted: int,
     *   references_repointed: int,
     *   nse_backfill_stocks: int,
     *   nse_backfill_rows: int,
     *   nse_backfill_failures: int,
     *   errors: array<int, string>
     * }
     */
    public function repair(bool $dryRun = true, bool $backfill = true, ?int $maxBackfill = null): array
    {
        $stats = [
            'pairs_found' => 0,
            'bse_rows_deactivated' => 0,
            'bse_prices_deleted' => 0,
            'bse_metrics_deleted' => 0,
            'references_repointed' => 0,
            'nse_backfill_stocks' => 0,
            'nse_backfill_rows' => 0,
            'nse_backfill_failures' => 0,
            'errors' => [],
        ];

        $activeNse = Stock::query()
            ->where('exchange', 'NSE')
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->get();

        if ($activeNse->isEmpty()) {
            return $stats;
        }

        /** @var array<string, Stock> $nseByIsin */
        $nseByIsin = [];
        /** @var array<string, Stock> $nseBySymbol */
        $nseBySymbol = [];
        foreach ($activeNse as $nseStock) {
            $isin = strtoupper(trim((string) $nseStock->isin));
            if ($isin !== '') {
                $nseByIsin[$isin] = $nseStock;
            }
            $nseBySymbol[strtoupper((string) $nseStock->symbol)] = $nseStock;
        }

        $bseDuplicates = Stock::query()
            ->where('exchange', 'BSE')
            ->where('is_benchmark', false)
            ->where(function ($query) use ($nseByIsin, $nseBySymbol): void {
                if ($nseByIsin !== []) {
                    $query->whereIn('isin', array_keys($nseByIsin));
                }
                if ($nseBySymbol !== []) {
                    $method = $nseByIsin === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('symbol', array_keys($nseBySymbol));
                }
            })
            ->orderBy('id')
            ->get();

        $nseIdsToBackfill = [];
        $handledBseIds = [];

        foreach ($bseDuplicates as $bseStock) {
            if (isset($handledBseIds[$bseStock->id])) {
                continue;
            }

            $nseStock = $this->resolveNseCounterpart($bseStock, $nseByIsin, $nseBySymbol);
            if ($nseStock === null || $bseStock->id === $nseStock->id) {
                continue;
            }

            $handledBseIds[$bseStock->id] = true;
            $stats['pairs_found']++;

            if ($dryRun) {
                $stats['bse_prices_deleted'] += StockPrice::query()->where('stock_id', $bseStock->id)->count();
                $stats['bse_metrics_deleted'] += StockMetric::query()->where('stock_id', $bseStock->id)->exists() ? 1 : 0;
                if ($bseStock->is_active) {
                    $stats['bse_rows_deactivated']++;
                }
                $nseIdsToBackfill[$nseStock->id] = true;
                continue;
            }

            DB::transaction(function () use ($bseStock, $nseStock, &$stats, &$nseIdsToBackfill): void {
                if ($nseStock->isin && ! $bseStock->isin) {
                    $bseStock->isin = $nseStock->isin;
                    $bseStock->save();
                }

                if (! $nseStock->is_dual_listed) {
                    $nseStock->is_dual_listed = true;
                    $nseStock->save();
                }

                $stats['references_repointed'] += $this->repointReferences($bseStock->id, $nseStock->id);

                $deletedPrices = StockPrice::query()->where('stock_id', $bseStock->id)->delete();
                $stats['bse_prices_deleted'] += $deletedPrices;

                $deletedMetrics = StockMetric::query()->where('stock_id', $bseStock->id)->delete();
                $stats['bse_metrics_deleted'] += $deletedMetrics;

                if ($bseStock->is_active) {
                    $bseStock->is_active = false;
                    $bseStock->save();
                    $stats['bse_rows_deactivated']++;
                }

                $nseIdsToBackfill[$nseStock->id] = true;
            });
        }

        if ($dryRun || ! $backfill || $nseIdsToBackfill === []) {
            $stats['nse_backfill_stocks'] = count($nseIdsToBackfill);

            return $stats;
        }

        return array_merge($stats, $this->runNseBackfillBatch(array_keys($nseIdsToBackfill), $maxBackfill, useCursor: true));
    }

    /**
     * Backfill NSE OHLCV for stocks that have a legacy BSE duplicate row (batched via cursor).
     *
     * @return array{
     *   nse_backfill_stocks: int,
     *   nse_backfill_rows: int,
     *   nse_backfill_failures: int,
     *   nse_backfill_remaining: int,
     *   nse_backfill_cursor_id: int,
     *   errors: array<int, string>
     * }
     */
    public function backfillPairedNseHistory(?int $maxBackfill = 25, bool $resetCursor = false): array
    {
        if ($resetCursor) {
            Setting::setValue(self::BACKFILL_CURSOR_KEY, '0');
        }

        $candidateIds = $this->collectPairedNseStockIds();

        return $this->runNseBackfillBatch($candidateIds, $maxBackfill, useCursor: true);
    }

    /**
     * @return array<int, int>
     */
    public function collectPairedNseStockIds(): array
    {
        $activeNse = Stock::query()
            ->where('exchange', 'NSE')
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->get(['id', 'symbol', 'isin']);

        if ($activeNse->isEmpty()) {
            return [];
        }

        /** @var array<string, int> $nseByIsin */
        $nseByIsin = [];
        /** @var array<string, int> $nseBySymbol */
        $nseBySymbol = [];
        foreach ($activeNse as $nseStock) {
            $isin = strtoupper(trim((string) $nseStock->isin));
            if ($isin !== '') {
                $nseByIsin[$isin] = (int) $nseStock->id;
            }
            $nseBySymbol[strtoupper((string) $nseStock->symbol)] = (int) $nseStock->id;
        }

        $bseDuplicates = Stock::query()
            ->where('exchange', 'BSE')
            ->where('is_benchmark', false)
            ->where(function ($query) use ($nseByIsin, $nseBySymbol): void {
                if ($nseByIsin !== []) {
                    $query->whereIn('isin', array_keys($nseByIsin));
                }
                if ($nseBySymbol !== []) {
                    $method = $nseByIsin === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('symbol', array_keys($nseBySymbol));
                }
            })
            ->get(['id', 'symbol', 'isin']);

        $nseIds = [];
        foreach ($bseDuplicates as $bseStock) {
            $isin = strtoupper(trim((string) $bseStock->isin));
            $nseId = ($isin !== '' && isset($nseByIsin[$isin]))
                ? $nseByIsin[$isin]
                : ($nseBySymbol[strtoupper((string) $bseStock->symbol)] ?? null);
            if ($nseId !== null) {
                $nseIds[$nseId] = true;
            }
        }

        $ids = array_keys($nseIds);
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<int, int>  $candidateIds
     * @return array{
     *   nse_backfill_stocks: int,
     *   nse_backfill_rows: int,
     *   nse_backfill_failures: int,
     *   nse_backfill_remaining: int,
     *   nse_backfill_cursor_id: int,
     *   errors: array<int, string>
     * }
     */
    protected function runNseBackfillBatch(array $candidateIds, ?int $maxBackfill, bool $useCursor): array
    {
        $stats = [
            'nse_backfill_stocks' => 0,
            'nse_backfill_rows' => 0,
            'nse_backfill_failures' => 0,
            'nse_backfill_remaining' => 0,
            'nse_backfill_cursor_id' => (int) Setting::getValue(self::BACKFILL_CURSOR_KEY, '0'),
            'errors' => [],
        ];

        $ids = array_values(array_unique(array_map('intval', $candidateIds)));
        sort($ids);

        if ($useCursor) {
            $cursorId = (int) Setting::getValue(self::BACKFILL_CURSOR_KEY, '0');
            $ids = array_values(array_filter($ids, static fn (int $id) => $id > $cursorId));
            $stats['nse_backfill_cursor_id'] = $cursorId;
        }

        if ($ids === []) {
            return $stats;
        }

        if ($maxBackfill !== null && $maxBackfill > 0) {
            $ids = array_slice($ids, 0, $maxBackfill);
        }

        $requiredTo = TradingCalendar::lastRequiredPriceSession()->copy()->startOfDay();
        $requiredFrom = $requiredTo->copy()->subDays((int) config('portfolio.universe_price_sync.history_days', 365))->startOfDay();

        PriceSyncNotificationContext::withoutTelegram(function () use ($ids, $requiredFrom, $requiredTo, &$stats): void {
            foreach (Stock::query()->whereIn('id', $ids)->orderBy('id')->get() as $stock) {
                $stats['nse_backfill_stocks']++;
                try {
                    $result = $this->priceHistory->fetchMissingHistory(
                        $stock,
                        $requiredFrom,
                        $requiredTo,
                        notifyTelegramOnFailure: false,
                    );
                    $stats['nse_backfill_rows'] += (int) ($result['stored_rows'] ?? 0);
                    if (! ($result['success'] ?? false)) {
                        $stats['nse_backfill_failures']++;
                        if (count($stats['errors']) < 20) {
                            $stats['errors'][] = $stock->symbol.': '.implode('; ', $result['errors'] ?? ['backfill failed']);
                        }
                    }
                } catch (\Throwable $e) {
                    $stats['nse_backfill_failures']++;
                    if (count($stats['errors']) < 20) {
                        $stats['errors'][] = $stock->symbol.': '.$e->getMessage();
                    }
                }
            }
        });

        if ($useCursor && $ids !== []) {
            $newCursor = max($ids);
            Setting::setValue(self::BACKFILL_CURSOR_KEY, (string) $newCursor);
            $stats['nse_backfill_cursor_id'] = $newCursor;
            $allIds = $this->collectPairedNseStockIds();
            $stats['nse_backfill_remaining'] = count(array_filter($allIds, static fn (int $id) => $id > $newCursor));
        }

        return $stats;
    }

    /**
     * @param  array<string, Stock>  $nseByIsin
     * @param  array<string, Stock>  $nseBySymbol
     */
    protected function resolveNseCounterpart(Stock $bseStock, array $nseByIsin, array $nseBySymbol): ?Stock
    {
        $isin = strtoupper(trim((string) $bseStock->isin));
        if ($isin !== '' && isset($nseByIsin[$isin])) {
            return $nseByIsin[$isin];
        }

        $symbol = strtoupper((string) $bseStock->symbol);

        return $nseBySymbol[$symbol] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function probeState(?string $sampleSymbol = 'TOKYOPLAST'): array
    {
        $activeNseWithIsin = Stock::query()
            ->where('exchange', 'NSE')
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->whereNotNull('isin')
            ->where('isin', '!=', '')
            ->count();

        $activeNseTotal = Stock::query()
            ->where('exchange', 'NSE')
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->count();

        $nseIsins = Stock::query()
            ->where('exchange', 'NSE')
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->whereNotNull('isin')
            ->where('isin', '!=', '')
            ->pluck('isin')
            ->map(fn ($isin) => strtoupper((string) $isin));

        $bseIsinMatches = Stock::query()
            ->where('exchange', 'BSE')
            ->where('is_benchmark', false)
            ->whereIn('isin', $nseIsins->all())
            ->count();

        $nseSymbols = Stock::query()
            ->where('exchange', 'NSE')
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->pluck('symbol')
            ->map(fn ($symbol) => strtoupper((string) $symbol));

        $bseSymbolMatches = Stock::query()
            ->where('exchange', 'BSE')
            ->where('is_benchmark', false)
            ->whereIn('symbol', $nseSymbols->all())
            ->count();

        $dryRun = $this->repair(dryRun: true, backfill: false);

        $sample = null;
        if ($sampleSymbol !== null && $sampleSymbol !== '') {
            $sample = Stock::query()
                ->where('symbol', strtoupper($sampleSymbol))
                ->where('is_benchmark', false)
                ->orderByRaw("CASE exchange WHEN 'NSE' THEN 0 ELSE 1 END")
                ->get(['id', 'symbol', 'exchange', 'series', 'isin', 'is_dual_listed', 'is_active'])
                ->toArray();
        }

        return [
            'active_nse_total' => $activeNseTotal,
            'active_nse_with_isin' => $activeNseWithIsin,
            'bse_rows_matching_nse_isin' => $bseIsinMatches,
            'bse_rows_matching_nse_symbol' => $bseSymbolMatches,
            'repair_pairs_found_dry_run' => $dryRun['pairs_found'],
            'sample_symbol_rows' => $sample,
        ];
    }

    protected function repointReferences(int $fromStockId, int $toStockId): int
    {
        $moved = 0;

        $moved += Transaction::query()->where('stock_id', $fromStockId)->update(['stock_id' => $toStockId]);

        $moved += CorporateAction::query()->where('stock_id', $fromStockId)->update(['stock_id' => $toStockId]);

        $moved += Alert::query()->where('stock_id', $fromStockId)->update(['stock_id' => $toStockId]);

        $moved += WatchlistItem::query()->where('stock_id', $fromStockId)->update(['stock_id' => $toStockId]);

        foreach (Holding::query()->where('stock_id', $fromStockId)->get() as $holding) {
            $existing = Holding::query()
                ->where('user_id', $holding->user_id)
                ->where('stock_id', $toStockId)
                ->first();

            if ($existing !== null) {
                $existing->quantity = (float) $existing->quantity + (float) $holding->quantity;
                $existing->invested_amount = (float) $existing->invested_amount + (float) $holding->invested_amount;
                $existing->realized_profit = (float) $existing->realized_profit + (float) $holding->realized_profit;
                $existing->save();
                $holding->delete();
            } else {
                $holding->stock_id = $toStockId;
                $holding->save();
            }

            $moved++;
        }

        return $moved;
    }
}
