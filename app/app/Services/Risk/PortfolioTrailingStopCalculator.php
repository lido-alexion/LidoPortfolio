<?php

namespace App\Services\Risk;

use InvalidArgumentException;

/**
 * V3 portfolio trailing-stop domain calculator (§15 / OD-14 / OD-22).
 *
 * Formula: max(raw close_price since holding entry) × (1 − portfolio_trailing_percent/100)
 *
 * Does NOT use:
 * - adjusted_close_price
 * - intraday high/low
 * - unrealized percentage (V1 ExitStrategyEvaluator trailing_stop proxy)
 * - portfolio stop-loss percent
 * - strategy JSON trailing/stop values
 */
final class PortfolioTrailingStopCalculator
{
    /**
     * Peak raw daily close since the ownership episode entry.
     *
     * @param  list<float|int|null>  $rawClosePrices
     */
    public function peakRawClose(array $rawClosePrices): ?float
    {
        $peak = null;
        foreach ($rawClosePrices as $close) {
            if ($close === null || ! is_numeric($close)) {
                continue;
            }
            $value = (float) $close;
            if ($peak === null || $value > $peak) {
                $peak = $value;
            }
        }

        return $peak;
    }

    /**
     * Trailing stop from peak raw close and independent portfolio trailing percent.
     */
    public function trailingStopPrice(?float $peakRawClose, float $portfolioTrailingPercent): ?float
    {
        if ($peakRawClose === null || $peakRawClose <= 0) {
            return null;
        }
        if ($portfolioTrailingPercent < 0) {
            throw new InvalidArgumentException('Portfolio trailing percent must be non-negative.');
        }

        return $peakRawClose * (1 - ($portfolioTrailingPercent / 100));
    }
}
