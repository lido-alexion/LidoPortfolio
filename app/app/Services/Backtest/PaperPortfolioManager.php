<?php

namespace App\Services\Backtest;

use App\Models\StockPrice;

/**
 * Paper (virtual) portfolio: cash, holdings, mark-to-market.
 * Never touches live CashManagementService / holdings tables.
 */
class PaperPortfolioManager
{
    public function __construct(private SimulationContext $ctx) {}

    /**
     * @return array{cash: float, invested_value: float, portfolio_value: float, unrealized_profit: float, holdings_count: int, prices: array<int, float>, drawdown_pct: float}
     */
    public function valueAsOf(string $asOfDate, bool $recordUtilization = false): array
    {
        $holdings = $this->ctx->holdings();
        $stockIds = array_map('intval', array_keys($holdings));
        $prices = $this->closesAsOf($stockIds, $asOfDate);

        $invested = 0.0;
        $costBasis = 0.0;
        $count = 0;
        foreach ($holdings as $stockId => $row) {
            $qty = (float) ($row['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $count++;
            $price = $prices[(int) $stockId] ?? (float) ($row['avg_cost'] ?? 0);
            $invested += $qty * $price;
            $costBasis += $qty * (float) ($row['avg_cost'] ?? 0);
        }

        $cash = $this->ctx->cash();
        $portfolioValue = round($cash + $invested, 4);
        $unrealized = round($invested - $costBasis, 4);

        $peak = (float) $this->ctx->get('peak_portfolio_value', $portfolioValue);
        if ($portfolioValue > $peak) {
            $this->ctx->set('peak_portfolio_value', $portfolioValue);
            $peak = $portfolioValue;
        }

        $maxConcurrent = (int) $this->ctx->get('max_concurrent_positions', 0);
        if ($count > $maxConcurrent) {
            $this->ctx->set('max_concurrent_positions', $count);
        }

        if ($recordUtilization) {
            $utilDays = (int) $this->ctx->get('utilization_days', 0);
            $utilSum = (float) $this->ctx->get('utilization_sum', 0);
            $utilPct = $portfolioValue > 0 ? ($invested / $portfolioValue) * 100.0 : 0.0;
            $this->ctx->set('utilization_sum', $utilSum + $utilPct);
            $this->ctx->set('utilization_days', $utilDays + 1);
        }

        return [
            'cash' => round($cash, 4),
            'invested_value' => round($invested, 4),
            'portfolio_value' => $portfolioValue,
            'unrealized_profit' => $unrealized,
            'holdings_count' => $count,
            'prices' => $prices,
            'drawdown_pct' => $peak > 0 ? round((($peak - $portfolioValue) / $peak) * 100.0, 6) : 0.0,
        ];
    }

    /**
     * @param  list<int>  $stockIds
     * @return array<int, float>
     */
    public function closesAsOf(array $stockIds, string $asOfDate): array
    {
        if ($stockIds === []) {
            return [];
        }

        $rows = StockPrice::query()
            ->from('portfolio_stock_prices as p')
            ->joinSub(
                StockPrice::query()
                    ->selectRaw('stock_id, MAX(price_date) as d')
                    ->whereIn('stock_id', $stockIds)
                    ->where('price_date', '<=', $asOfDate)
                    ->whereNotNull('close_price')
                    ->groupBy('stock_id'),
                'latest',
                function ($join) {
                    $join->on('p.stock_id', '=', 'latest.stock_id')
                        ->on('p.price_date', '=', 'latest.d');
                }
            )
            ->get(['p.stock_id', 'p.close_price']);

        $out = [];
        foreach ($rows as $row) {
            if ($row->close_price !== null) {
                $out[(int) $row->stock_id] = (float) $row->close_price;
            }
        }

        return $out;
    }

    /**
     * Allocation % of portfolio for a held stock.
     */
    public function allocationPct(int $stockId, float $portfolioValue, ?float $price = null): float
    {
        $holdings = $this->ctx->holdings();
        $row = $holdings[$stockId] ?? $holdings[(string) $stockId] ?? null;
        if ($row === null || $portfolioValue <= 0) {
            return 0.0;
        }
        $qty = (float) ($row['qty'] ?? 0);
        $px = $price ?? (float) ($row['avg_cost'] ?? 0);
        if ($qty <= 0 || $px <= 0) {
            return 0.0;
        }

        return round((($qty * $px) / $portfolioValue) * 100.0, 4);
    }

    /**
     * @return array<int, float> stock_id => qty
     */
    public function heldQuantities(): array
    {
        $out = [];
        foreach ($this->ctx->holdings() as $stockId => $row) {
            $qty = (float) ($row['qty'] ?? 0);
            if ($qty > 0) {
                $out[(int) $stockId] = $qty;
            }
        }

        return $out;
    }
}
