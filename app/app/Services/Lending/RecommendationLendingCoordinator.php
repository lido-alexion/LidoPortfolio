<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalRequest;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\Transaction;
use App\Support\CommittedLoanAmount;
use Illuminate\Validation\ValidationException;

/**
 * WS4 Steps 5–6 — capital requests, commitment, and execution eligibility.
 * Capital resolution (own → recall → borrow) runs before partial lending / trade modes.
 */
final class RecommendationLendingCoordinator
{
    public function __construct(
        protected CapitalRequestService $requests,
        protected PartialLendingAmountCalculator $partialAmount,
        protected UnfundedLendingAmountCalculator $unfundedAmount,
        protected CommittedLendingExecutionAmounts $executionAmounts,
        protected CapitalResolutionService $capitalResolution,
        protected CapitalResolutionStatusService $resolutionStatus,
    ) {}

    public function syncAfterGenerated(TradingRecommendation $recommendation): void
    {
        if (! $recommendation->requiresCashReservation()) {
            return;
        }

        $this->applyCapitalResolutionBeforeTradeMode($recommendation);
        $recommendation = $recommendation->fresh() ?? $recommendation;

        $status = $recommendation->capitalAllocationStatus();
        $own = round((float) ($recommendation->ownAllocatedAmount() ?? 0), 4);

        if ($own <= 0.0001 && (
            $status === TradingRecommendation::ALLOCATION_UNFUNDED
            || $status === TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION
        )) {
            $this->ensureUnfundedCapitalRequest($recommendation);

            return;
        }

        if ($status === TradingRecommendation::ALLOCATION_UNFUNDED) {
            return;
        }
        if ($status !== TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED
            && $status !== TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION) {
            return;
        }

        $this->ensurePartialCapitalRequest($recommendation);
    }

    /**
     * §6.16 — capital resolution before Manual / Semi-Auto / Auto trade handling.
     * Updates allocated/suggested amounts to actual_available (never claims unfunded target).
     */
    public function applyCapitalResolutionBeforeTradeMode(TradingRecommendation $recommendation): void
    {
        $evidence = is_array($recommendation->evidence) ? $recommendation->evidence : [];
        if (isset($evidence['capital_resolution']['recorded_at'])) {
            return;
        }

        $profile = $recommendation->profile()->first();
        $strategyId = $recommendation->owningStrategyId();
        if ($profile === null || $strategyId === null) {
            return;
        }
        $strategy = TradingStrategy::query()->find($strategyId);
        if ($strategy === null) {
            return;
        }

        $target = round((float) ($recommendation->capitalTargetAmount() ?? 0), 4);
        if ($target <= 0.0001) {
            return;
        }

        // Preserve allocator-assigned own capital (competition among recommendations).
        // Capital resolution only adds recalled capital on top; it must not inflate own
        // from unused strategy capacity after the allocator already constrained the pack.
        $ownAlready = round((float) ($recommendation->ownAllocatedAmount() ?? 0), 4);

        $result = $this->capitalResolution->resolveForStrategy($profile, $strategy, $target, [
            'own_available_override' => $ownAlready,
        ]);
        $this->resolutionStatus->attachSnapshot($recommendation, $result);
        $recommendation = $recommendation->fresh() ?? $recommendation;

        $actual = round(min($target, (float) $result['actual_available']), 4);
        $unfunded = round(max(0.0, $target - $actual), 4);

        if ($actual <= 0.0001) {
            $status = TradingRecommendation::ALLOCATION_UNFUNDED;
        } elseif ($unfunded <= 0.0001) {
            $status = TradingRecommendation::ALLOCATION_FUNDED;
        } else {
            $status = TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED;
        }

        $plan = is_array($recommendation->execution_plan) ? $recommendation->execution_plan : [];
        $plan['suggested_investment_amount'] = $actual;
        $plan['target_investment_amount'] = $target;
        $ca = is_array($plan['capital_allocation'] ?? null) ? $plan['capital_allocation'] : [];
        $ca = array_merge($ca, [
            'status' => $status,
            'desired_amount' => $target,
            'target_amount' => $target,
            'allocated_amount' => $actual,
            'unfunded_amount' => $unfunded,
            'own_used' => (float) $result['own_used'],
            'recalled_amount' => (float) $result['recalled_amount'],
            'borrow_shortfall' => (float) $result['borrow_shortfall'],
            'actual_execution_amount' => $actual,
            'close_at_actual' => true,
            'hold_for_remainder' => false,
        ]);
        $plan['capital_allocation'] = $ca;
        $evidence = is_array($recommendation->evidence) ? $recommendation->evidence : [];
        $evidence['capital_allocation'] = $ca;
        $recommendation->forceFill([
            'execution_plan' => $plan,
            'evidence' => $evidence,
            'suggested_allocation_amount' => $actual,
        ])->save();
    }

    /**
     * Zero-own UNFUNDED OPEN/INCREASE: offer lending only when eligible lenders exist.
     * Loan size is DEP-PARTIAL-ATOMIC on the this-cycle gap. Does not change actual_execution_amount.
     */
    public function ensureUnfundedCapitalRequest(TradingRecommendation $recommendation): ?CapitalRequest
    {
        $existing = $this->activeRequestFor($recommendation);
        if ($existing !== null) {
            $meta = $recommendation->capitalAllocationMeta() ?? [];
            $this->patchCapitalAllocation($recommendation, [
                'status' => $existing->status === CapitalRequest::STATUS_COMMITTED
                    ? TradingRecommendation::ALLOCATION_CAPITAL_COMMITTED
                    : TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION,
                'capital_request_id' => $existing->id,
                'own_funding_status' => TradingRecommendation::ALLOCATION_UNFUNDED,
                'close_at_actual' => (bool) ($meta['close_at_actual'] ?? false),
                'actual_execution_amount' => $meta['actual_execution_amount']
                    ?? $recommendation->ownAllocatedAmount()
                    ?? 0,
                'hold_for_remainder' => false,
            ]);

            return $existing;
        }

        $target = $recommendation->capitalTargetAmount();
        $own = round((float) ($recommendation->ownAllocatedAmount() ?? 0), 4);
        if ($target === null || $target <= 0.0001 || $own > 0.0001) {
            return null;
        }

        $gap = round(max(0.0, $target - $own), 4);
        $loanAmount = $this->unfundedAmount->calculateForUnfundedGap($gap);
        if ($loanAmount <= 0 || ! CommittedLoanAmount::isValid($loanAmount)) {
            return null;
        }

        $borrowerId = $recommendation->owningStrategyId();
        if ($borrowerId === null) {
            return null;
        }
        $borrower = TradingStrategy::query()->find($borrowerId);
        $profile = $recommendation->profile()->first();
        if ($borrower === null || $profile === null) {
            return null;
        }

        if ($this->requests->eligibleLendersFor($profile, (int) $borrower->id, $loanAmount) === []) {
            return null;
        }

        $request = $this->requests->createRequest($profile, $recommendation, $borrower, $loanAmount);
        $meta = $recommendation->capitalAllocationMeta() ?? [];
        $this->patchCapitalAllocation($recommendation, [
            'status' => TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION,
            'own_funding_status' => TradingRecommendation::ALLOCATION_UNFUNDED,
            'capital_request_id' => $request->id,
            'lending_loan_amount' => $loanAmount,
            'unfunded_remainder' => $gap,
            'close_at_actual' => (bool) ($meta['close_at_actual'] ?? false),
            'actual_execution_amount' => $meta['actual_execution_amount'] ?? $own,
            'hold_for_remainder' => false,
        ]);

        return $request;
    }

    public function ensurePartialCapitalRequest(TradingRecommendation $recommendation): ?CapitalRequest
    {
        $existing = $this->activeRequestFor($recommendation);
        if ($existing !== null) {
            $meta = $recommendation->capitalAllocationMeta() ?? [];
            $this->patchCapitalAllocation($recommendation, [
                'status' => $existing->status === CapitalRequest::STATUS_COMMITTED
                    ? TradingRecommendation::ALLOCATION_CAPITAL_COMMITTED
                    : TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION,
                'capital_request_id' => $existing->id,
                'own_funding_status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
                'close_at_actual' => (bool) ($meta['close_at_actual'] ?? false),
                'actual_execution_amount' => $meta['actual_execution_amount']
                    ?? $recommendation->ownAllocatedAmount(),
                'hold_for_remainder' => false,
            ]);

            return $existing;
        }

        $target = $recommendation->capitalTargetAmount();
        $own = $recommendation->ownAllocatedAmount();
        if ($target === null || $own === null) {
            return null;
        }

        $loanAmount = $this->partialAmount->calculateForPartialRemainder($target, $own);
        if ($loanAmount <= 0 || ! CommittedLoanAmount::isValid($loanAmount)) {
            return null;
        }

        $borrowerId = $recommendation->owningStrategyId();
        if ($borrowerId === null) {
            return null;
        }
        $borrower = TradingStrategy::query()->find($borrowerId);
        $profile = $recommendation->profile()->first();
        if ($borrower === null || $profile === null) {
            return null;
        }

        $request = $this->requests->createRequest($profile, $recommendation, $borrower, $loanAmount);
        // Keep close_at_actual / actual_execution_amount from capital resolution.
        // Awaiting lender is an optional top-up path — it must not erase executable actual.
        $meta = $recommendation->capitalAllocationMeta() ?? [];
        $this->patchCapitalAllocation($recommendation, [
            'status' => TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION,
            'own_funding_status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'capital_request_id' => $request->id,
            'lending_loan_amount' => $loanAmount,
            'unfunded_remainder' => $this->partialAmount->remainderFromTargetAndOwn($target, $own),
            'close_at_actual' => (bool) ($meta['close_at_actual'] ?? false),
            'actual_execution_amount' => $meta['actual_execution_amount'] ?? $own,
            'hold_for_remainder' => false,
        ]);

        return $request;
    }

    public function markCapitalCommitted(CapitalRequest $request): void
    {
        $recommendation = $request->recommendation()->first();
        if ($recommendation === null) {
            return;
        }

        $loan = $request->loan;
        $amounts = $this->executionAmounts->forRecommendation($recommendation, $loan);
        // Intended = own (post-resolution allocated) + remainder needed, not own + full ₹5k loan.
        // Never above requested target; excess borrowed stays available, not invested.
        $executable = round(min(
            (float) ($amounts['target_amount'] > 0 ? $amounts['target_amount'] : $amounts['intended_amount']),
            (float) $amounts['intended_amount'],
        ), 4);

        $this->patchCapitalAllocation($recommendation, [
            'status' => TradingRecommendation::ALLOCATION_CAPITAL_COMMITTED,
            'own_funding_status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'capital_request_id' => $request->id,
            'capital_loan_id' => $loan?->id,
            'target_amount' => $amounts['target_amount'],
            'allocated_amount' => $amounts['own_amount'],
            'borrowed_amount' => $amounts['borrowed_amount'],
            'intended_execution_amount' => $executable,
            'actual_execution_amount' => $executable,
            'excess_borrowed_amount' => $amounts['excess_borrowed_amount'],
            'close_at_actual' => true,
            'hold_for_remainder' => false,
        ]);

        $recommendation = $recommendation->fresh() ?? $recommendation;
        $plan = is_array($recommendation->execution_plan) ? $recommendation->execution_plan : [];
        $plan['suggested_investment_amount'] = $executable;
        $recommendation->forceFill([
            'execution_plan' => $plan,
            'suggested_allocation_amount' => $executable,
        ])->save();
    }

    public function assertCanExecute(TradingRecommendation $recommendation): void
    {
        if (! $this->canEnterPendingExecution($recommendation)) {
            throw ValidationException::withMessages([
                'recommendation_id' => [
                    'This recommendation cannot be executed until capital is funded or lending is committed.',
                ],
            ]);
        }
    }

    public function committedLoanFor(TradingRecommendation $recommendation): ?CapitalLoan
    {
        $request = CapitalRequest::query()
            ->where('recommendation_id', $recommendation->id)
            ->where('status', CapitalRequest::STATUS_COMMITTED)
            ->with('loan')
            ->orderBy('id')
            ->first();

        return $request?->loan;
    }

    public function recordExecution(TradingRecommendation $recommendation, Transaction $transaction): void
    {
        $loan = $this->committedLoanFor($recommendation);
        $meta = $recommendation->capitalAllocationMeta() ?? [];
        $existingTx = isset($meta['execution_transaction_id']) ? (int) $meta['execution_transaction_id'] : null;
        if ($existingTx !== null && $existingTx !== (int) $transaction->id) {
            throw ValidationException::withMessages([
                'recommendation_id' => ['This committed loan has already been used for an execution.'],
            ]);
        }

        $amounts = $this->executionAmounts->forRecommendation($recommendation, $loan);
        $this->patchCapitalAllocation($recommendation, [
            'execution_transaction_id' => $transaction->id,
            'executed_amount' => round(
                ((float) $transaction->quantity * (float) $transaction->price) + (float) ($transaction->fees ?? 0),
                4,
            ),
            'intended_execution_amount' => $amounts['intended_amount'],
            'borrowed_amount' => $amounts['borrowed_amount'],
            'excess_borrowed_amount' => $amounts['excess_borrowed_amount'],
            'target_amount' => $amounts['target_amount'] > 0
                ? $amounts['target_amount']
                : ($meta['target_amount'] ?? $amounts['target_amount']),
        ]);
    }

    public function canEnterPendingExecution(TradingRecommendation $recommendation): bool
    {
        if (! $recommendation->requiresCashReservation()) {
            return true;
        }

        $status = $recommendation->capitalAllocationStatus();
        if ($status === null
            || $status === TradingRecommendation::ALLOCATION_FUNDED
            || $status === TradingRecommendation::ALLOCATION_CAPITAL_COMMITTED) {
            return true;
        }

        // DEP-CAPITAL-PRIORITY §6.0: capital resolution closed at actual funded amount.
        // Optional residual lending (awaiting_lender / partially_funded) must not block
        // execution of already-funded capital.
        if ($this->isExecutableAtResolvedActual($recommendation)) {
            return true;
        }

        return $this->hasCommittedLoan($recommendation);
    }

    /**
     * True when capital resolution recorded a positive actual_execution_amount and
     * marked close_at_actual (do not hold for residual shortfall).
     */
    public function isExecutableAtResolvedActual(TradingRecommendation $recommendation): bool
    {
        $meta = $recommendation->capitalAllocationMeta() ?? [];
        if (! (bool) ($meta['close_at_actual'] ?? false)) {
            return false;
        }

        return round((float) ($meta['actual_execution_amount'] ?? 0), 4) > 0.0001;
    }

    public function activeRequestFor(TradingRecommendation $recommendation): ?CapitalRequest
    {
        return CapitalRequest::query()
            ->where('recommendation_id', $recommendation->id)
            ->whereIn('status', CapitalRequest::ACTIVE_FUNDING_STATUSES)
            ->orderBy('id')
            ->first();
    }

    public function hasCommittedLoan(TradingRecommendation $recommendation): bool
    {
        return CapitalRequest::query()
            ->where('recommendation_id', $recommendation->id)
            ->where('status', CapitalRequest::STATUS_COMMITTED)
            ->whereHas('loan')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function patchCapitalAllocation(TradingRecommendation $recommendation, array $patch): void
    {
        $plan = is_array($recommendation->execution_plan) ? $recommendation->execution_plan : [];
        $ca = is_array($plan['capital_allocation'] ?? null) ? $plan['capital_allocation'] : [];
        $ca = array_merge($ca, $patch);
        $plan['capital_allocation'] = $ca;
        $evidence = is_array($recommendation->evidence) ? $recommendation->evidence : [];
        $evidence['capital_allocation'] = $ca;
        $recommendation->forceFill([
            'execution_plan' => $plan,
            'evidence' => $evidence,
        ])->save();
    }
}
