<?php

namespace App\Services\Lending;

use App\Models\CapitalRequest;
use App\Models\PortfolioProfile;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Support\CommittedLoanAmount;
use Illuminate\Validation\ValidationException;

/**
 * Explicit capital-request creation and eligible-lender listing. Does not select a lender.
 */
final class CapitalRequestService
{
    public function __construct(
        protected LenderEligibilityService $eligibility,
    ) {}

    public function createRequest(
        PortfolioProfile $profile,
        TradingRecommendation $recommendation,
        TradingStrategy $borrower,
        float $amount,
    ): CapitalRequest {
        if ((int) $recommendation->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'recommendation_id' => ['Recommendation must belong to the active portfolio.'],
            ]);
        }
        if ((int) $borrower->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'borrower_strategy_id' => ['Borrower strategy must belong to the active portfolio.'],
            ]);
        }
        if ($recommendation->owningStrategyId() !== (int) $borrower->id) {
            throw ValidationException::withMessages([
                'borrower_strategy_id' => ['Borrower strategy must own the recommendation.'],
            ]);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Requested amount must be greater than 0.'],
            ]);
        }
        if (! CommittedLoanAmount::isValid($amount)) {
            throw ValidationException::withMessages([
                'amount' => ['Requested amount must be at least ₹5,000 and a whole multiple of ₹5,000.'],
            ]);
        }

        $existing = CapitalRequest::query()
            ->where('recommendation_id', $recommendation->id)
            ->whereIn('status', CapitalRequest::ACTIVE_FUNDING_STATUSES)
            ->orderBy('id')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return CapitalRequest::query()->create([
            'profile_id' => $profile->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => null,
            'recommendation_id' => $recommendation->id,
            'amount' => round($amount, 4),
            'status' => CapitalRequest::STATUS_DISPLAYED,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eligibleLenders(CapitalRequest $request): array
    {
        $profile = $request->profile()->firstOrFail();

        return $this->eligibleLendersFor(
            $profile,
            (int) $request->borrower_strategy_id,
            (float) $request->amount,
        );
    }

    /**
     * Eligible lenders for a proposed loan amount before a capital request exists.
     *
     * @return list<array<string, mixed>>
     */
    public function eligibleLendersFor(PortfolioProfile $profile, int $borrowerStrategyId, float $amount): array
    {
        return $this->eligibility->eligibleLenders($profile, $borrowerStrategyId, $amount);
    }
}
