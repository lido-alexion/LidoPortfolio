<?php

namespace App\Services\Ranking;

use App\Models\PortfolioProfile;
use App\Services\ProfileSettingsService;

/**
 * V3 §19 success definition (pure evaluator).
 *
 * Successful when all hold:
 * 1. Period return is positive
 * 2. Period return beats NIFTY 50 over the comparable period
 * 3. Period return beats opportunity-cost period threshold (1+r)^T − 1
 *    where T = calendar_days / 365 (OD-02) and r = portfolio opportunity_cost_rate (default 0.12)
 *
 * Ranking still uses return-quality magnitude (trimmed mean); this boolean is the §19 flag.
 * Closed backtest trades persist `benchmark_return_pct` + `is_success` via BacktestTradeSuccessAttacher.
 */
final class SuccessCriteriaEvaluator
{
    public function __construct(
        protected ProfileSettingsService $profileSettings,
    ) {}

    /**
     * @param  float  $periodReturnFraction  Holding simple return as fraction (0.10 = +10%)
     * @param  float  $benchmarkReturnFraction  NIFTY 50 simple return over same dates (fraction)
     * @param  int  $holdingCalendarDays  OD-02 calendar days
     * @param  float  $opportunityCostRate  Annualized rate as fraction (default 0.12)
     * @return array{
     *     success: bool,
     *     positive_return: bool,
     *     beats_benchmark: bool,
     *     beats_opportunity_cost: bool,
     *     opportunity_cost_rate: float,
     *     t_years: float,
     *     opportunity_cost_period_threshold: float,
     *     period_return: float,
     *     benchmark_return: float
     * }
     */
    public function evaluate(
        float $periodReturnFraction,
        float $benchmarkReturnFraction,
        int $holdingCalendarDays,
        float $opportunityCostRate = 0.12,
    ): array {
        $days = max(0, $holdingCalendarDays);
        $tYears = $days / 365.0;
        $threshold = $tYears > 0.0
            ? ((1.0 + $opportunityCostRate) ** $tYears) - 1.0
            : 0.0;

        $positive = $periodReturnFraction > 0.0;
        $beatsBenchmark = $periodReturnFraction > $benchmarkReturnFraction;
        $beatsOpp = $periodReturnFraction > $threshold;

        return [
            'success' => $positive && $beatsBenchmark && $beatsOpp,
            'positive_return' => $positive,
            'beats_benchmark' => $beatsBenchmark,
            'beats_opportunity_cost' => $beatsOpp,
            'opportunity_cost_rate' => $opportunityCostRate,
            't_years' => $tYears,
            'opportunity_cost_period_threshold' => $threshold,
            'period_return' => $periodReturnFraction,
            'benchmark_return' => $benchmarkReturnFraction,
        ];
    }

    /**
     * Resolve r from portfolio settings (decimal fraction string/number → float).
     */
    public function opportunityCostRateFor(PortfolioProfile $profile): float
    {
        $raw = $this->profileSettings->get($profile, 'opportunity_cost_rate', '0.12');
        $rate = is_numeric($raw) ? (float) $raw : 0.12;
        if ($rate < 0.0) {
            return 0.0;
        }
        if ($rate > 1.0) {
            // Guard accidental percent input (e.g. 12 instead of 0.12)
            if ($rate <= 100.0) {
                return $rate / 100.0;
            }

            return 1.0;
        }

        return $rate;
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluateForProfile(
        PortfolioProfile $profile,
        float $periodReturnFraction,
        float $benchmarkReturnFraction,
        int $holdingCalendarDays,
    ): array {
        return $this->evaluate(
            $periodReturnFraction,
            $benchmarkReturnFraction,
            $holdingCalendarDays,
            $this->opportunityCostRateFor($profile),
        );
    }
}
