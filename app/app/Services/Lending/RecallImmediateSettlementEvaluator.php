<?php

namespace App\Services\Lending;

/**
 * Pure evaluation for DEP-RECALL-IMMEDIATE-75 (no persistence).
 */
final class RecallImmediateSettlementEvaluator
{
    public const THRESHOLD_RATIO = 0.75;

    public function __construct(
        protected RecallBridgeEligibilityCalculator $bridgeEligibility = new RecallBridgeEligibilityCalculator,
    ) {}

    /**
     * @return array{
     *   recall_amount: float,
     *   threshold: float,
     *   borrower_own_cash: float,
     *   eligible_bridge: float,
     *   immediate_available: float,
     *   allows_immediate: bool,
     *   settle_amount: float,
     *   use_bridge_amount: float,
     *   bridge_need: float
     * }
     */
    public function evaluate(
        float $recallAmountR,
        float $borrowerOwnCash,
        float $liquidatableStockValue,
    ): array {
        $r = round(max(0.0, $recallAmountR), 4);
        $own = round(max(0.0, $borrowerOwnCash), 4);
        $bridgeEval = $this->bridgeEligibility->evaluate($r, $own, $liquidatableStockValue);
        $eligibleBridge = (float) $bridgeEval['eligible_bridge'];
        $immediate = round(min($r, $own + $eligibleBridge), 4);
        $threshold = round($r * self::THRESHOLD_RATIO, 4);
        $allows = $r > 0 && ($immediate + 0.0001 >= $threshold);

        $settleAmount = 0.0;
        $useBridge = 0.0;
        if ($allows) {
            $settleAmount = $immediate;
            $fromOwn = min($own, $settleAmount);
            $useBridge = round(max(0.0, $settleAmount - $fromOwn), 4);
            if ($useBridge > $eligibleBridge + 0.0001) {
                $useBridge = $eligibleBridge;
                $settleAmount = round(min($r, $fromOwn + $useBridge), 4);
            }
        }

        return [
            'recall_amount' => $r,
            'threshold' => $threshold,
            'borrower_own_cash' => $own,
            'eligible_bridge' => $eligibleBridge,
            'immediate_available' => $immediate,
            'allows_immediate' => $allows,
            'settle_amount' => $settleAmount,
            'use_bridge_amount' => $allows ? $useBridge : 0.0,
            'bridge_need' => (float) $bridgeEval['bridge_need'],
        ];
    }
}
