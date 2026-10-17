<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\CapitalRequest;
use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use App\Models\TradingRecommendation;

/**
 * Builds recommendation capital-resolution status for the UI contract (read-only assembly).
 */
final class CapitalResolutionStatusService
{
    public function __construct(
        protected CapitalRecallPresenter $presenter,
        protected CapitalResolutionService $resolution,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forRecommendation(PortfolioProfile $profile, TradingRecommendation $recommendation): array
    {
        $stored = $this->storedSnapshot($recommendation);
        $committedExecutionAmount = $this->committedNormalLoanExecutionAmount($profile, $recommendation);
        $requested = (float) (
            $stored['required_amount']
            ?? $recommendation->capitalTargetAmount()
            ?? $recommendation->suggestedInvestmentAmount()
            ?? 0
        );

        $ownUsed = (float) ($stored['own_used'] ?? $recommendation->ownAllocatedAmount() ?? 0);
        $recalledRequested = 0.0;
        $recalledReceived = (float) ($stored['recalled_amount'] ?? 0);
        $bridgeUsed = (float) ($stored['bridge_amount'] ?? 0);
        $recallsPayload = [];

        $strategyId = method_exists($recommendation, 'owningStrategyId')
            ? $recommendation->owningStrategyId()
            : null;

        $relatedRecalls = CapitalRecall::query()
            ->where('profile_id', $profile->id)
            ->when($strategyId, fn ($q) => $q->where('lender_strategy_id', $strategyId))
            ->where('requested_at', '>=', optional($recommendation->generated_at)?->copy()->subDay() ?? now()->subDays(30))
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        // Prefer recalls linked via capital request / loan for this recommendation
        $requestIds = CapitalRequest::query()
            ->where('profile_id', $profile->id)
            ->where('recommendation_id', $recommendation->id)
            ->pluck('id');
        $loanIds = CapitalLoan::query()
            ->whereIn('capital_request_id', $requestIds)
            ->pluck('id');
        if ($loanIds->isNotEmpty()) {
            $relatedRecalls = CapitalRecall::query()
                ->where('profile_id', $profile->id)
                ->where(function ($q) use ($loanIds, $strategyId, $recommendation) {
                    $q->whereIn('loan_id', $loanIds);
                    if ($strategyId) {
                        $q->orWhere(function ($q2) use ($strategyId, $recommendation) {
                            $q2->where('lender_strategy_id', $strategyId)
                                ->where('requested_at', '>=', optional($recommendation->generated_at)?->copy()->subDay() ?? now()->subDays(30));
                        });
                    }
                })
                ->orderByDesc('id')
                ->limit(10)
                ->get();
        }

        foreach ($relatedRecalls as $recall) {
            $recalledRequested = round($recalledRequested + (float) $recall->recall_amount, 4);
            if ($recalledReceived <= 0) {
                $recalledReceived = round($recalledReceived + (float) $recall->settled_amount, 4);
            }
            $bridgeUsed = round(
                $bridgeUsed + (float) RecallBridgeLoan::query()
                    ->where('capital_recall_id', $recall->id)
                    ->sum('principal'),
                4
            );
            $recallsPayload[] = $this->presenter->recall($recall, true);
        }

        if (isset($stored['recalled_amount'])) {
            $recalledReceived = (float) $stored['recalled_amount'];
        }
        if (isset($stored['own_used'])) {
            $ownUsed = (float) $stored['own_used'];
        }

        $immediatelyAvailable = round($ownUsed + $recalledReceived, 4);
        if (isset($stored['actual_available'])) {
            $immediatelyAvailable = (float) $stored['actual_available'];
        }
        if ($committedExecutionAmount !== null) {
            // This is recommendation-specific executable capital, not unreserved portfolio cash.
            $immediatelyAvailable = $committedExecutionAmount;
        }

        $executed = $recommendation->executed_amount !== null
            ? (float) $recommendation->executed_amount
            : ($committedExecutionAmount ?? $immediatelyAvailable);

        $unresolved = round(max(0.0, $requested - $executed), 4);
        if ($committedExecutionAmount !== null) {
            // The committed projection supersedes only the pre-commit snapshot's shortfall.
            $unresolved = round(max(0.0, $requested - $executed), 4);
        } elseif (isset($stored['borrow_shortfall'])) {
            $unresolved = (float) $stored['borrow_shortfall'];
        }

        $state = 'resolved_at_actual';
        if ($relatedRecalls->contains(fn (CapitalRecall $r) => $r->isActive())) {
            $state = 'recall_in_progress';
        } elseif ($unresolved > 0.0001 && $executed + 0.0001 < $requested) {
            $state = 'closed_at_actual_with_shortfall';
        } elseif ($executed <= 0.0001 && $requested > 0) {
            $state = 'unfunded';
        }

        return [
            'recommendation_id' => $recommendation->id,
            'requested_investment_amount' => round($requested, 4),
            'own_capital_used' => round($ownUsed, 4),
            'recalled_capital_requested' => round($recalledRequested, 4),
            'recalled_capital_received' => round($recalledReceived, 4),
            'bridge_capital_used' => round($bridgeUsed, 4),
            'total_immediately_available' => round($immediatelyAvailable, 4),
            'actual_execution_amount' => round($executed, 4),
            'unresolved_amount' => $unresolved,
            'close_at_actual' => true,
            'hold_for_remainder' => false,
            'capital_resolution_state' => $state,
            'capital_allocation_status' => $recommendation->capitalAllocationStatus(),
            'recalls' => $recallsPayload,
            'stored_snapshot' => $stored,
        ];
    }

    /**
     * Return the canonical per-recommendation executable amount only when the
     * persisted metadata identifies the matching committed normal request and loan.
     */
    private function committedNormalLoanExecutionAmount(
        PortfolioProfile $profile,
        TradingRecommendation $recommendation,
    ): ?float {
        if ($recommendation->capitalAllocationStatus() !== TradingRecommendation::ALLOCATION_CAPITAL_COMMITTED) {
            return null;
        }

        $meta = $recommendation->capitalAllocationMeta() ?? [];
        $requestId = $this->positiveIntegerId($meta['capital_request_id'] ?? null);
        $loanId = $this->positiveIntegerId($meta['capital_loan_id'] ?? null);
        $borrowerStrategyId = $recommendation->owningStrategyId();
        if ($requestId === null || $loanId === null || $borrowerStrategyId === null) {
            return null;
        }

        $request = CapitalRequest::query()
            ->whereKey($requestId)
            ->where('profile_id', $profile->id)
            ->where('recommendation_id', $recommendation->id)
            ->where('borrower_strategy_id', $borrowerStrategyId)
            ->where('status', CapitalRequest::STATUS_COMMITTED)
            ->with('loan')
            ->first();
        $loan = $request?->loan;
        if ($loan === null
            || (int) $loan->id !== $loanId
            || (int) $loan->capital_request_id !== $requestId
            || (int) $loan->profile_id !== (int) $profile->id) {
            return null;
        }

        $targetAmount = $recommendation->capitalTargetAmount();
        if ($targetAmount === null || ! is_finite($targetAmount) || $targetAmount <= 0) {
            return null;
        }

        foreach (['actual_execution_amount', 'intended_execution_amount'] as $key) {
            $amount = $meta[$key] ?? null;
            if (is_numeric($amount)
                && is_finite((float) $amount)
                && (float) $amount > 0
                && (float) $amount <= $targetAmount) {
                return round((float) $amount, 4);
            }
        }

        return null;
    }

    private function positiveIntegerId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/', $value)) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($parsed) ? $parsed : null;
    }

    /**
     * Persist a resolution result onto the recommendation for later UI reads.
     *
     * @param  array<string, mixed>  $resolution
     */
    public function attachSnapshot(TradingRecommendation $recommendation, array $resolution): TradingRecommendation
    {
        $evidence = is_array($recommendation->evidence) ? $recommendation->evidence : [];
        $evidence['capital_resolution'] = [
            'required_amount' => $resolution['required_amount'] ?? null,
            'own_available' => $resolution['own_available'] ?? null,
            'own_used' => $resolution['own_used'] ?? null,
            'recalled_amount' => $resolution['recalled_amount'] ?? null,
            'borrow_shortfall' => $resolution['borrow_shortfall'] ?? null,
            'actual_available' => $resolution['actual_available'] ?? null,
            'close_at_actual' => true,
            'hold_for_remainder' => false,
            'recalls' => $resolution['recalls'] ?? [],
            'recorded_at' => now()->toIso8601String(),
        ];
        $recommendation->forceFill(['evidence' => $evidence])->save();

        return $recommendation->fresh();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function storedSnapshot(TradingRecommendation $recommendation): ?array
    {
        $evidence = is_array($recommendation->evidence) ? $recommendation->evidence : [];
        if (isset($evidence['capital_resolution']) && is_array($evidence['capital_resolution'])) {
            return $evidence['capital_resolution'];
        }
        $plan = is_array($recommendation->execution_plan) ? $recommendation->execution_plan : [];
        if (isset($plan['capital_resolution']) && is_array($plan['capital_resolution'])) {
            return $plan['capital_resolution'];
        }

        return null;
    }
}
