<?php

namespace App\Services\Backtest;

use App\Models\BacktestRun;
use App\Models\PortfolioProfile;
use App\Models\StockPrice;
use App\Models\TradingStrategy;
use App\Services\IndexCatalogService;
use App\Services\Ranking\SuccessCriteriaEvaluator;

/**
 * Attach V3 §19 success evidence onto closed backtest trade payloads before persist.
 *
 * Does not alter return-quality ranking / OD-23 / fill order — only boolean + benchmark %.
 */
final class BacktestTradeSuccessAttacher
{
    public function __construct(
        protected IndexCatalogService $indexCatalog,
        protected SuccessCriteriaEvaluator $successCriteria,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $closedTrades
     * @return list<array<string, mixed>>
     */
    public function attach(BacktestRun $run, array $closedTrades): array
    {
        if ($closedTrades === []) {
            return $closedTrades;
        }

        $profile = $this->resolveProfile($run);
        if ($profile === null) {
            return $closedTrades;
        }

        $benchmark = $this->indexCatalog->primaryBenchmarkStock();
        $closeCache = [];

        foreach ($closedTrades as $i => $trade) {
            if (! empty($trade['is_open'])) {
                $closedTrades[$i]['benchmark_return_pct'] = null;
                $closedTrades[$i]['is_success'] = null;

                continue;
            }

            $buyDate = (string) ($trade['buy_date'] ?? '');
            $sellDate = (string) ($trade['sell_date'] ?? '');
            $returnPct = $trade['return_pct'] ?? null;
            $holdingDays = (int) ($trade['holding_days'] ?? 0);

            if ($buyDate === '' || $sellDate === '' || $returnPct === null) {
                $closedTrades[$i]['benchmark_return_pct'] = null;
                $closedTrades[$i]['is_success'] = null;

                continue;
            }

            $buyClose = $this->closeOnOrBeforeCached($benchmark->id, $buyDate, $closeCache);
            $sellClose = $this->closeOnOrBeforeCached($benchmark->id, $sellDate, $closeCache);
            if ($buyClose === null || $sellClose === null || abs($buyClose) < 0.0000001) {
                $closedTrades[$i]['benchmark_return_pct'] = null;
                $closedTrades[$i]['is_success'] = null;

                continue;
            }

            $benchmarkReturnFraction = ($sellClose - $buyClose) / $buyClose;
            // Stored return_pct is percent (10.0 = +10%); evaluator uses fraction.
            $periodReturnFraction = ((float) $returnPct) / 100.0;

            $result = $this->successCriteria->evaluateForProfile(
                $profile,
                $periodReturnFraction,
                $benchmarkReturnFraction,
                max(0, $holdingDays),
            );

            $closedTrades[$i]['benchmark_return_pct'] = BacktestMath::clampDecimal12_6(
                round($benchmarkReturnFraction * 100.0, 6)
            );
            $closedTrades[$i]['is_success'] = (bool) $result['success'];
        }

        return $closedTrades;
    }

    protected function resolveProfile(BacktestRun $run): ?PortfolioProfile
    {
        if ($run->relationLoaded('profile') && $run->profile instanceof PortfolioProfile) {
            return $run->profile;
        }

        if ($run->profile_id) {
            $profile = PortfolioProfile::query()->find($run->profile_id);
            if ($profile !== null) {
                return $profile;
            }
        }

        if ($run->strategy_id) {
            $strategy = TradingStrategy::query()->with('profile')->find($run->strategy_id);

            return $strategy?->profile;
        }

        return null;
    }

    /**
     * @param  array<string, float|null>  $cache
     */
    protected function closeOnOrBeforeCached(int $stockId, string $date, array &$cache): ?float
    {
        $key = $stockId.'|'.$date;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $row = StockPrice::query()
            ->where('stock_id', $stockId)
            ->whereDate('price_date', '<=', $date)
            ->whereNotNull('close_price')
            ->orderByDesc('price_date')
            ->first(['close_price', 'adjusted_close_price']);

        if ($row === null) {
            $cache[$key] = null;

            return null;
        }

        $close = $row->adjusted_close_price ?? $row->close_price;
        $cache[$key] = $close !== null ? (float) $close : null;

        return $cache[$key];
    }
}
