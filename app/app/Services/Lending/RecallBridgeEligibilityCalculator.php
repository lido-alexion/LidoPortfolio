<?php

namespace App\Services\Lending;

/**
 * DEP-RECALL-BRIDGE — eligibility after own cash; 10% liquidatable stock cushion.
 */
final class RecallBridgeEligibilityCalculator
{
    public const CUSHION_FACTOR = 1.10;

    /**
     * @return array{
     *   recall_amount: float,
     *   borrower_own_cash: float,
     *   bridge_need: float,
     *   liquidatable_stock_value: float,
     *   max_eligible_bridge: float,
     *   eligible_bridge: float
     * }
     */
    public function evaluate(
        float $recallAmount,
        float $borrowerOwnCash,
        float $liquidatableStockValue,
    ): array {
        $recallAmount = round(max(0.0, $recallAmount), 4);
        $borrowerOwnCash = round(max(0.0, $borrowerOwnCash), 4);
        $liquidatableStockValue = round(max(0.0, $liquidatableStockValue), 4);

        $bridgeNeed = round(max(0.0, $recallAmount - $borrowerOwnCash), 4);
        $maxEligible = $this->maxBridgeSupportedByStock($liquidatableStockValue);
        $eligible = round(min($bridgeNeed, $maxEligible), 4);

        return [
            'recall_amount' => $recallAmount,
            'borrower_own_cash' => $borrowerOwnCash,
            'bridge_need' => $bridgeNeed,
            'liquidatable_stock_value' => $liquidatableStockValue,
            'max_eligible_bridge' => $maxEligible,
            'eligible_bridge' => $eligible,
        ];
    }

    /** Required stock value for bridge amount X: X * 1.10 */
    public function requiredStockValueForBridge(float $bridgeAmount): float
    {
        return round(max(0.0, $bridgeAmount) * self::CUSHION_FACTOR, 4);
    }

    /** Max bridge X such that stock >= X * 1.10 → X = stock / 1.10 */
    public function maxBridgeSupportedByStock(float $liquidatableStockValue): float
    {
        if ($liquidatableStockValue <= 0) {
            return 0.0;
        }

        return round($liquidatableStockValue / self::CUSHION_FACTOR, 4);
    }
}
