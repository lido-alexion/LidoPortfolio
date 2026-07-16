<?php

namespace App\Services;

use App\Models\Stock;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class BseBhavcopyBackfillService
{
    public function __construct(
        protected BseBhavcopyService $bhavcopy,
        protected BseEquityMasterService $bseMaster,
        protected EquityUniverseService $equityUniverse,
        protected PriceFetchService $priceFetch,
    ) {}

    /**
     * @return array{
     *   stocks: int,
     *   days_processed: int,
     *   days_skipped: int,
     *   rows_stored: int,
     *   rows_matched: int,
     *   from_date: string,
     *   to_date: string,
     *   errors: array<int, string>
     * }
     */
    public function backfill(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $maxTradingDays = null,
        bool $dryRun = false,
    ): array {
        $to = ($to ?? TradingCalendar::lastRequiredPriceSession())->copy()->startOfDay();
        $from = ($from ?? $to->copy()->subDays((int) config('portfolio.universe_price_sync.history_days', 365)))->copy()->startOfDay();

        if ($from->gt($to)) {
            throw new \InvalidArgumentException('from date must be on or before to date');
        }

        $stocks = $this->bseOnlyStocks();
        $lookup = $this->buildStockLookup($stocks);

        $stats = [
            'stocks' => $stocks->count(),
            'days_processed' => 0,
            'days_skipped' => 0,
            'rows_stored' => 0,
            'rows_matched' => 0,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'errors' => [],
        ];

        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            if (! TradingCalendar::isEquitySessionDate($cursor)) {
                $cursor->addDay();
                continue;
            }

            if ($maxTradingDays !== null && $stats['days_processed'] >= $maxTradingDays) {
                break;
            }

            try {
                $dayStored = 0;
                $this->bhavcopy->eachEquityRowForDate($cursor, function (array $row) use ($lookup, $dryRun, &$stats, &$dayStored): void {
                    $stock = $this->resolveStock($row, $lookup);
                    if ($stock === null) {
                        return;
                    }

                    $stats['rows_matched']++;
                    if ($dryRun) {
                        return;
                    }

                    $dayStored += $this->priceFetch->storeHistoricalRows($stock, [[
                        'price_date' => $row['price_date'],
                        'open_price' => $row['open_price'],
                        'high_price' => $row['high_price'],
                        'low_price' => $row['low_price'],
                        'close_price' => $row['close_price'],
                        'volume' => $row['volume'],
                    ]], 'bse_bhavcopy');
                });
            } catch (\Throwable $e) {
                $stats['days_skipped']++;
                $stats['errors'][] = $cursor->toDateString().': '.$e->getMessage();
                $cursor->addDay();
                continue;
            }

            $stats['days_processed']++;
            $stats['rows_stored'] += $dayStored;
            $cursor->addDay();
        }

        return $stats;
    }

    /**
     * @return array{updated: int, missing_symbol: int, missing_code: int}
     */
    public function syncScripCodesFromMaster(): array
    {
        if (! Schema::hasColumn('portfolio_stocks', 'bse_scrip_code')) {
            throw new \RuntimeException('portfolio_stocks.bse_scrip_code column is missing — run migrations first.');
        }

        $rows = $this->bseMaster->fetchEquityRows();
        $stats = ['updated' => 0, 'missing_symbol' => 0, 'missing_code' => 0];

        foreach ($rows as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            $scripCode = trim((string) ($row['scrip_code'] ?? ''));
            if ($symbol === '') {
                $stats['missing_symbol']++;
                continue;
            }
            if ($scripCode === '') {
                $stats['missing_code']++;
                continue;
            }

            $updated = Stock::query()
                ->where('exchange', 'BSE')
                ->where('symbol', $symbol)
                ->where(function ($query) use ($scripCode) {
                    $query->whereNull('bse_scrip_code')
                        ->orWhere('bse_scrip_code', '!=', $scripCode);
                })
                ->update(['bse_scrip_code' => $scripCode]);

            $stats['updated'] += $updated;
        }

        return $stats;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Stock>
     */
    protected function bseOnlyStocks()
    {
        return $this->equityUniverse->universeStockQuery()
            ->where('exchange', 'BSE')
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Stock>  $stocks
     * @return array{
     *   by_scrip_code: array<string, Stock>,
     *   by_symbol: array<string, Stock>
     * }
     */
    protected function buildStockLookup($stocks): array
    {
        $byScripCode = [];
        $bySymbol = [];

        foreach ($stocks as $stock) {
            $symbol = strtoupper((string) $stock->symbol);
            $bySymbol[$symbol] = $stock;

            $code = trim((string) ($stock->bse_scrip_code ?? ''));
            if ($code !== '') {
                $byScripCode[$code] = $stock;
            }
        }

        return [
            'by_scrip_code' => $byScripCode,
            'by_symbol' => $bySymbol,
        ];
    }

    /**
     * @param  array{
     *   scrip_code?: string,
     *   symbol?: string
     * }  $row
     * @param  array{
     *   by_scrip_code: array<string, Stock>,
     *   by_symbol: array<string, Stock>
     * }  $lookup
     */
    protected function resolveStock(array $row, array $lookup): ?Stock
    {
        $scripCode = trim((string) ($row['scrip_code'] ?? ''));
        if ($scripCode !== '' && isset($lookup['by_scrip_code'][$scripCode])) {
            return $lookup['by_scrip_code'][$scripCode];
        }

        $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
        if ($symbol !== '' && isset($lookup['by_symbol'][$symbol])) {
            return $lookup['by_symbol'][$symbol];
        }

        return null;
    }
}
