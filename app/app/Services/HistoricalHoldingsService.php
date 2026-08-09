<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * F014 on-demand as-of holdings reconstruction + valuation (PD-F014-*).
 */
class HistoricalHoldingsService
{
    public function __construct(
        protected PortfolioHistoricalHoldingsService $reconstruction,
    ) {}

    /**
     * @return array{
     *   as_of: string,
     *   holdings: list<array<string, mixed>>,
     *   warnings: list<array<string, mixed>>,
     *   totals: array<string, mixed>,
     *   completeness: array<string, mixed>
     * }
     */
    public function asOf(PortfolioProfile $profile, string $asOfDate): array
    {
        $asOfDate = substr(trim($asOfDate), 0, 10);
        if ($asOfDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
            throw ValidationException::withMessages([
                'as_of' => ['as_of must be a date in YYYY-MM-DD format.'],
            ]);
        }

        $today = now()->toDateString();
        if ($asOfDate > $today) {
            throw ValidationException::withMessages([
                'as_of' => ['as_of cannot be in the future.'],
            ]);
        }

        $asOf = Carbon::parse($asOfDate)->startOfDay();
        $transactions = $this->loadTransactions($profile);
        $detailed = $this->reconstruction->holdingsAsOfDetailed($transactions, $asOf);
        $holdingsMap = $detailed['holdings'];
        $warnings = $detailed['warnings'];

        if ($holdingsMap === []) {
            return [
                'as_of' => $asOfDate,
                'holdings' => [],
                'warnings' => $this->enrichWarnings($warnings, []),
                'totals' => [
                    'invested_value' => 0.0,
                    'market_value' => null,
                    'unrealized_profit' => null,
                    'unrealized_gain_percent' => null,
                    'valuation_complete' => true,
                ],
                'completeness' => [
                    'valuation_complete' => true,
                    'missing_price_count' => 0,
                    'priced_holding_count' => 0,
                    'holding_count' => 0,
                ],
            ];
        }

        $stockIds = array_values(array_unique(array_merge(
            array_map('intval', array_keys($holdingsMap)),
            array_map(fn (array $w) => (int) ($w['stock_id'] ?? 0), $warnings),
        )));
        $stockIds = array_values(array_filter($stockIds, fn (int $id) => $id > 0));
        $stocks = $stockIds === []
            ? collect()
            : Stock::query()->whereIn('id', $stockIds)->get()->keyBy('id');
        $priceByStock = $this->latestClosesOnOrBefore(
            array_map('intval', array_keys($holdingsMap)),
            $asOf,
        );

        $rows = [];
        $investedTotal = 0.0;
        $marketTotal = 0.0;
        $marketComplete = true;
        $missingPriceCount = 0;
        $pricedCount = 0;

        foreach ($holdingsMap as $stockId => $holding) {
            $stockId = (int) $stockId;
            $qty = (float) $holding['quantity'];
            $invested = round((float) $holding['invested_amount'], 4);
            $avgBuy = round((float) $holding['avg_buy_price'], 4);
            $investedTotal += $invested;

            $close = $priceByStock[$stockId] ?? null;
            $priceAvailable = $close !== null && $close > 0;
            $asOfPrice = $priceAvailable ? round($close, 4) : null;
            $marketValue = $priceAvailable ? round($qty * $close, 4) : null;
            $unrealized = ($marketValue !== null)
                ? round($marketValue - $invested, 4)
                : null;
            $unrealizedPct = ($unrealized !== null && $invested > 0)
                ? round(($unrealized / $invested) * 100, 2)
                : null;

            if ($priceAvailable) {
                $marketTotal += $marketValue;
                $pricedCount++;
            } else {
                $marketComplete = false;
                $missingPriceCount++;
            }

            $stock = $stocks->get($stockId);

            $rows[] = [
                'stock_id' => $stockId,
                'symbol' => $stock?->symbol,
                'name' => $stock?->name,
                'exchange' => $stock?->exchange,
                'quantity' => round($qty, 4),
                'avg_buy_price' => $avgBuy,
                'invested_amount' => $invested,
                'as_of_price' => $asOfPrice,
                'price_available' => $priceAvailable,
                'market_value' => $marketValue,
                'unrealized_profit' => $unrealized,
                'unrealized_gain_percent' => $unrealizedPct,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            return strcmp((string) ($a['symbol'] ?? ''), (string) ($b['symbol'] ?? ''));
        });

        $totalsMarket = $marketComplete ? round($marketTotal, 4) : null;
        $totalsUnrealized = $marketComplete
            ? round($marketTotal - $investedTotal, 4)
            : null;
        $totalsUnrealizedPct = ($totalsUnrealized !== null && $investedTotal > 0)
            ? round(($totalsUnrealized / $investedTotal) * 100, 2)
            : null;

        return [
            'as_of' => $asOfDate,
            'holdings' => $rows,
            'warnings' => $this->enrichWarnings($warnings, $stocks),
            'totals' => [
                'invested_value' => round($investedTotal, 4),
                'market_value' => $totalsMarket,
                'unrealized_profit' => $totalsUnrealized,
                'unrealized_gain_percent' => $totalsUnrealizedPct,
                'valuation_complete' => $marketComplete,
            ],
            'completeness' => [
                'valuation_complete' => $marketComplete,
                'missing_price_count' => $missingPriceCount,
                'priced_holding_count' => $pricedCount,
                'holding_count' => count($rows),
            ],
        ];
    }

    protected function loadTransactions(PortfolioProfile $profile): Collection
    {
        return Transaction::query()
            ->where('profile_id', $profile->id)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Single F014 price path (PD-06): latest price_date <= asOf; adjusted_close ?? close.
     *
     * @param  list<int>  $stockIds
     * @return array<int, float>
     */
    protected function latestClosesOnOrBefore(array $stockIds, Carbon $asOf): array
    {
        if ($stockIds === []) {
            return [];
        }

        $asOfEnd = $asOf->copy()->endOfDay();
        $rows = StockPrice::query()
            ->whereIn('stock_id', $stockIds)
            ->where('price_date', '<=', $asOfEnd)
            ->orderByDesc('price_date')
            ->get(['stock_id', 'price_date', 'close_price', 'adjusted_close_price']);

        $result = [];
        foreach ($rows as $row) {
            $stockId = (int) $row->stock_id;
            if (isset($result[$stockId])) {
                continue;
            }
            $close = $row->adjusted_close_price ?? $row->close_price;
            if ($close === null || (float) $close <= 0) {
                continue;
            }
            $result[$stockId] = (float) $close;
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     * @param  \Illuminate\Support\Collection<int, Stock>|array  $stocks
     * @return list<array<string, mixed>>
     */
    protected function enrichWarnings(array $warnings, $stocks): array
    {
        $byId = $stocks instanceof Collection ? $stocks : collect($stocks);

        return array_map(function (array $warning) use ($byId) {
            $stock = $byId->get((int) ($warning['stock_id'] ?? 0));
            $warning['symbol'] = $stock?->symbol;
            $warning['name'] = $stock?->name;

            return $warning;
        }, $warnings);
    }
}
