<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use App\Models\TradingStrategy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DEP-RECALL-IMMEDIATE-75 — evaluate and apply immediate recall settlement.
 *
 * Target = 100% of R. Minimum threshold = 75% of R.
 * Settle maximum currently available (own cash + eligible bridge) up to 100% of R.
 * If total < 75%: pending_held; do not tiny-partial; do not take tiny bridge.
 * If 75% ≤ settle < 100%: apply immediate settle, then pending_held for remainder.
 * Automatically selects a bridge lender when needed. Chains fulfilment immediately.
 */
final class RecallImmediateSettlementService
{
    public function __construct(
        protected RecallImmediateSettlementEvaluator $evaluator,
        protected RecallBridgeLoanService $bridgeLoans,
        protected CapitalLoanRepaymentService $repayments,
        protected RecallService $recalls,
        protected RecallBridgeLenderSelector $bridgeLenderSelector,
        protected RecallFulfilmentService $fulfilment,
        protected SpecialCashMovementService $specialCash,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(
        float $recallAmountR,
        float $borrowerOwnCash,
        float $liquidatableStockValue,
    ): array {
        return $this->evaluator->evaluate($recallAmountR, $borrowerOwnCash, $liquidatableStockValue);
    }

    /**
     * @return array{
     *   recall: CapitalRecall,
     *   evaluation: array<string, mixed>,
     *   bridge_loan: ?RecallBridgeLoan,
     *   fulfilment: ?array<string, mixed>,
     *   skipped: bool
     * }
     */
    public function apply(
        PortfolioProfile $profile,
        CapitalRecall $recall,
        float $borrowerOwnCash,
        float $liquidatableStockValue,
        ?TradingStrategy $bridgeLender = null,
    ): array {
        $recall = $recall->fresh() ?? $recall;

        // Idempotent: do not re-settle recalls that already left the request/immediate phase.
        if (! in_array($recall->state, [
            CapitalRecall::STATE_REQUESTED,
            CapitalRecall::STATE_IMMEDIATE_SETTLEMENT,
        ], true)) {
            $existingBridge = RecallBridgeLoan::query()
                ->where('capital_recall_id', $recall->id)
                ->where('outstanding', '>', 0)
                ->orderBy('id')
                ->first();

            return [
                'recall' => $recall,
                'evaluation' => $this->evaluate(
                    (float) $recall->recall_amount,
                    $borrowerOwnCash,
                    $liquidatableStockValue,
                ),
                'bridge_loan' => $existingBridge,
                'fulfilment' => null,
                'skipped' => true,
            ];
        }

        $r = (float) $recall->outstanding_recall_amount;
        $evaluation = $this->evaluate($r, $borrowerOwnCash, $liquidatableStockValue);
        $useBridge = (float) $evaluation['use_bridge_amount'];

        if ($useBridge > 0.0001 && $bridgeLender === null) {
            $selected = $this->bridgeLenderSelector->select(
                $profile,
                $recall,
                $borrowerOwnCash,
                $liquidatableStockValue,
            );
            if ($selected !== null) {
                $bridgeLender = $selected['lender'];
                $evaluation = $selected['evaluation'];
                $useBridge = (float) $evaluation['use_bridge_amount'];
            }
        }

        if (! $evaluation['allows_immediate']) {
            $recall = $this->recalls->markPendingHeld($recall);
            $fulfilment = $this->fulfilment->afterImmediateSettlement($profile, [
                'recall' => $recall,
                'evaluation' => $evaluation,
                'bridge_loan' => null,
            ]);

            return [
                'recall' => $recall->fresh(),
                'evaluation' => $evaluation,
                'bridge_loan' => null,
                'fulfilment' => $fulfilment,
                'skipped' => false,
            ];
        }

        $useBridge = (float) $evaluation['use_bridge_amount'];
        if ($useBridge > 0.0001 && $bridgeLender === null) {
            // No eligible automated lender — fall back to own-only if own alone meets 75%.
            $ownOnly = round(min((float) $evaluation['recall_amount'], (float) $evaluation['borrower_own_cash']), 4);
            $threshold = (float) $evaluation['threshold'];
            if ($ownOnly + 0.0001 < $threshold) {
                $recall = $this->recalls->markPendingHeld($recall);
                $evaluation['allows_immediate'] = false;
                $evaluation['settle_amount'] = 0.0;
                $evaluation['use_bridge_amount'] = 0.0;
                $fulfilment = $this->fulfilment->afterImmediateSettlement($profile, [
                    'recall' => $recall,
                    'evaluation' => $evaluation,
                    'bridge_loan' => null,
                ]);

                return [
                    'recall' => $recall->fresh(),
                    'evaluation' => $evaluation,
                    'bridge_loan' => null,
                    'fulfilment' => $fulfilment,
                    'skipped' => false,
                ];
            }
            $evaluation['settle_amount'] = $ownOnly;
            $evaluation['use_bridge_amount'] = 0.0;
            $evaluation['immediate_available'] = $ownOnly;
            $useBridge = 0.0;
        }

        $applied = DB::transaction(function () use (
            $profile,
            $recall,
            $evaluation,
            $bridgeLender,
            $borrowerOwnCash,
            $liquidatableStockValue,
        ) {
            $recall = $this->recalls->markImmediateSettlement($recall);
            $settle = (float) $evaluation['settle_amount'];
            $useBridge = (float) $evaluation['use_bridge_amount'];
            $bridgeLoan = null;

            if ($useBridge > 0.0001) {
                if ($bridgeLender === null) {
                    throw ValidationException::withMessages([
                        'bridge_lender' => ['A bridge lender is required to create a Recall Bridge Loan.'],
                    ]);
                }
                $bridgeLoan = $this->bridgeLoans->create(
                    $profile,
                    $recall,
                    $bridgeLender,
                    $useBridge,
                    [
                        'borrower_own_cash' => $borrowerOwnCash,
                        'liquidatable_stock_value' => $liquidatableStockValue,
                    ],
                );
            }

            /** @var CapitalLoan $loan */
            $loan = CapitalLoan::query()->whereKey($recall->loan_id)->lockForUpdate()->firstOrFail();
            $this->repayments->repay($loan, $settle, false);
            $recall = $this->recalls->applySettlementAmount($recall->fresh(), $settle);
            if ($settle > 0.0001) {
                $this->specialCash->postRecallSettlement($profile, $recall, $settle);
            }

            if ((float) $recall->outstanding_recall_amount > 0.0001
                && $recall->state !== CapitalRecall::STATE_COMPLETED) {
                $recall = $this->recalls->markPendingHeld($recall);
            }

            return [
                'recall' => $recall,
                'evaluation' => $evaluation,
                'bridge_loan' => $bridgeLoan,
            ];
        });

        $fulfilment = $this->fulfilment->afterImmediateSettlement($profile, $applied);

        return [
            'recall' => $applied['recall']->fresh(),
            'evaluation' => $applied['evaluation'],
            'bridge_loan' => $applied['bridge_loan'],
            'fulfilment' => $fulfilment,
            'skipped' => false,
        ];
    }
}
