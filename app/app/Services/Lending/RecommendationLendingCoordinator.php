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
 * Does not size UNFUNDED loans, repay loans, or create a second cash pool.
 */
final class RecommendationLendingCoordinator
{
    public function __construct(
        protected CapitalRequestService $requests,
        protected PartialLendingAmountCalculator $partialAmount,
        protected CommittedLendingExecutionAmounts $executionAmounts,
    ) {}

    public function syncAfterGenerated(TradingRecommendation $recommendation): void
    {
        if (! $recommendation->requiresCashReservation()) {
            return;
        }

        $status = $recommendation->capitalAllocationStatus();
        if ($status === TradingRecommendation::ALLOCATION_UNFUNDED) {
            return;
        }
        if ($status !== TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED
            && $status !== TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION) {
            return;
        }

        $this->ensurePartialCapitalRequest($recommendation);
    }

    public function ensurePartialCapitalRequest(TradingRecommendation $recommendation): ?CapitalRequest
    {
        $existing = $this->activeRequestFor($recommendation);
        if ($existing !== null) {
            $this->patchCapitalAllocation($recommendation, [
                'status' => $existing->status === CapitalRequest::STATUS_COMMITTED
                    ? TradingRecommendation::ALLOCATION_CAPITAL_COMMITTED
                    : TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION,
                'capital_request_id' => $existing->id,
                'own_funding_status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
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
        $this->patchCapitalAllocation($recommendation, [
            'status' => TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION,
            'own_funding_status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'capital_request_id' => $request->id,
            'lending_loan_amount' => $loanAmount,
            'unfunded_remainder' => $this->partialAmount->remainderFromTargetAndOwn($target, $own),
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
        $this->patchCapitalAllocation($recommendation, [
            'status' => TradingRecommendation::ALLOCATION_CAPITAL_COMMITTED,
            'own_funding_status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'capital_request_id' => $request->id,
            'capital_loan_id' => $loan?->id,
            'target_amount' => $amounts['target_amount'],
            'allocated_amount' => $amounts['own_amount'],
            'borrowed_amount' => $amounts['borrowed_amount'],
            'intended_execution_amount' => $amounts['intended_amount'],
            'excess_borrowed_amount' => $amounts['excess_borrowed_amount'],
        ]);
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

        return $this->hasCommittedLoan($recommendation);
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
