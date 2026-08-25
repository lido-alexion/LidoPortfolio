<?php

namespace App\Services\Risk;

use InvalidArgumentException;

/**
 * V3 portfolio stop-loss domain calculator (OD-13 / OD-14).
 *
 * Pure / independently testable. Does not read strategy JSON, target amounts,
 * recommendation amounts, adjusted close, or intraday low.
 *
 * V1 ExitStrategyEvaluator unrealized-% trailing_stop proxy is intentionally
 * separate and is NOT used here (Phase 2 wires live EXIT precedence).
 */
final class PortfolioStopLossCalculator
{
    /**
     * OD-13: weighted-average actual fill cost of the current ownership episode.
     *
     * @param  list<array{quantity: float|int, price: float|int}>  $fills
     */
    public function weightedAverageFillCost(array $fills): float
    {
        $totalQty = 0.0;
        $totalCost = 0.0;

        foreach ($fills as $fill) {
            $qty = (float) ($fill['quantity'] ?? 0);
            $price = (float) ($fill['price'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $totalQty += $qty;
            $totalCost += $qty * $price;
        }

        if ($totalQty <= 0.0) {
            throw new InvalidArgumentException('OD-13 weighted-average fill cost requires at least one positive-quantity fill.');
        }

        return $totalCost / $totalQty;
    }

    /**
     * Stop price from OD-13 entry cost and portfolio stop-loss percent.
     */
    public function stopPrice(float $weightedAverageFillCost, float $stoplossPercent): float
    {
        if ($weightedAverageFillCost < 0) {
            throw new InvalidArgumentException('Weighted-average fill cost must be non-negative.');
        }
        if ($stoplossPercent < 0) {
            throw new InvalidArgumentException('Stop-loss percent must be non-negative.');
        }

        return $weightedAverageFillCost * (1 - ($stoplossPercent / 100));
    }

    /**
     * OD-14: hit test uses latest applicable raw daily close_price only.
     */
    public function isHitByRawClose(float $latestRawClosePrice, float $stopPrice): bool
    {
        return $latestRawClosePrice <= $stopPrice;
    }
}
