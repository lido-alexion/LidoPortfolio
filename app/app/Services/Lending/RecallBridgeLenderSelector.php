<?php

namespace App\Services\Lending;

use App\Models\CapitalRecall;
use App\Models\PortfolioProfile;
use App\Models\TradingStrategy;
use App\Services\Strategy\PortfolioCapitalAccountingService;

/**
 * Deterministic Recall Bridge Loan lender selection (v0.28 automated good-faith).
 *
 * Preference: lowest strategy id among active strategies that can fund the bridge
 * amount under borrower cushion + lender available capital. Targets max settlement
 * up to 100% of R (never merely 75% when more is eligible).
 */
final class RecallBridgeLenderSelector
{
    public function __construct(
        protected RecallImmediateSettlementEvaluator $evaluator,
        protected PortfolioCapitalAccountingService $accounting,
    ) {}

    /**
     * @return array{
     *   lender: TradingStrategy,
     *   evaluation: array<string, mixed>,
     *   lender_capacity: float
     * }|null
     */
    public function select(
        PortfolioProfile $profile,
        CapitalRecall $recall,
        float $borrowerOwnCash,
        float $liquidatableStockValue,
    ): ?array {
        $r = round(max(0.0, (float) $recall->outstanding_recall_amount), 4);
        if ($r <= 0.0001) {
            return null;
        }

        $own = round(max(0.0, $borrowerOwnCash), 4);
        $stock = round(max(0.0, $liquidatableStockValue), 4);
        $baseline = $this->evaluator->evaluate($r, $own, $stock);
        if ((float) $baseline['use_bridge_amount'] <= 0.0001 && $baseline['allows_immediate']) {
            return null;
        }

        $exclude = [
            (int) $recall->borrower_strategy_id,
            (int) $recall->lender_strategy_id,
        ];
        $capacityById = $this->strategyAvailableById($profile);
        $candidates = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('status', TradingStrategy::STATUS_ACTIVE)
            ->whereNotIn('id', $exclude)
            ->orderBy('id')
            ->get();

        $best = null;
        foreach ($candidates as $candidate) {
            $capacity = round((float) ($capacityById[(int) $candidate->id] ?? 0.0), 4);
            if ($capacity <= 0.0001) {
                continue;
            }

            // Lender capacity caps effective bridge; cushion still applies via stock/1.10.
            $cappedStock = round(min($stock, $capacity * RecallBridgeEligibilityCalculator::CUSHION_FACTOR), 4);
            $evaluation = $this->evaluator->evaluate($r, $own, $cappedStock);
            $useBridge = (float) $evaluation['use_bridge_amount'];
            if (! $evaluation['allows_immediate']) {
                continue;
            }
            if ($useBridge > $capacity + 0.0001) {
                continue;
            }

            $settle = (float) $evaluation['settle_amount'];
            if ($best === null
                || $settle > (float) $best['evaluation']['settle_amount'] + 0.0001
                || (
                    abs($settle - (float) $best['evaluation']['settle_amount']) <= 0.0001
                    && (int) $candidate->id < (int) $best['lender']->id
                )
            ) {
                $best = [
                    'lender' => $candidate,
                    'evaluation' => $evaluation,
                    'lender_capacity' => $capacity,
                ];
            }
        }

        return $best;
    }

    /**
     * @return array<int, float>
     */
    protected function strategyAvailableById(PortfolioProfile $profile): array
    {
        $out = [];
        foreach ($this->accounting->snapshot($profile)['strategies'] as $row) {
            $out[(int) $row['strategy_id']] = (float) $row['strategy_available_capital'];
        }

        return $out;
    }
}
