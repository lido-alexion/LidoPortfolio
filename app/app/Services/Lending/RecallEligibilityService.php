<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\PortfolioProfile;
use App\Models\TradingStrategy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Dynamic recall eligibility (OD-07) + follow-up cooldown (DEP-RECALL-FOLLOWUP).
 * Does not use min_recall_at as source of truth.
 */
final class RecallEligibilityService
{
    public function __construct(
        protected RecallPeriodResolver $periods,
    ) {}

    public function isLoanEligible(
        CapitalLoan $loan,
        PortfolioProfile $profile,
        ?CarbonInterface $asOf = null,
    ): bool {
        if ((float) $loan->outstanding <= 0) {
            return false;
        }
        if ($loan->status === CapitalLoan::STATUS_RETURNED) {
            return false;
        }

        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $committed = Carbon::parse($loan->committed_at)->startOfDay();
        $elapsedDays = $committed->diffInDays($asOf->copy()->startOfDay());
        $period = $this->periods->effectivePeriodDays($profile);

        return $elapsedDays >= $period;
    }

    public function hasActiveRecall(PortfolioProfile $profile): bool
    {
        return CapitalRecall::query()
            ->where('profile_id', $profile->id)
            ->whereIn('state', CapitalRecall::ACTIVE_STATES)
            ->exists();
    }

    public function hasActiveRecallForLoan(CapitalLoan $loan): bool
    {
        return CapitalRecall::query()
            ->where('loan_id', $loan->id)
            ->whereIn('state', CapitalRecall::ACTIVE_STATES)
            ->exists();
    }

    /**
     * Follow-up allowed only after previous completed AND cooldown elapsed.
     */
    public function isFollowUpCooldownElapsed(
        PortfolioProfile $profile,
        ?CarbonInterface $asOf = null,
    ): bool {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $last = CapitalRecall::query()
            ->where('profile_id', $profile->id)
            ->where('state', CapitalRecall::STATE_COMPLETED)
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->first();

        if ($last === null) {
            return true;
        }

        $completed = Carbon::parse($last->completed_at)->startOfDay();
        $elapsed = $completed->diffInDays($asOf->copy()->startOfDay());
        $cooldown = $this->periods->followUpCooldownDays($profile);

        return $elapsed >= $cooldown;
    }

    public function canInitiateRecall(
        PortfolioProfile $profile,
        CapitalLoan $loan,
        ?CarbonInterface $asOf = null,
    ): bool {
        if ($this->hasActiveRecall($profile)) {
            return false;
        }
        if ($this->hasActiveRecallForLoan($loan)) {
            return false;
        }
        if (! $this->isFollowUpCooldownElapsed($profile, $asOf)) {
            return false;
        }

        return $this->isLoanEligible($loan, $profile, $asOf);
    }

    /**
     * OD-09: eligible normal loans for a lender strategy, oldest committed_at first.
     *
     * @return list<CapitalLoan>
     */
    public function eligibleLoansForLender(
        PortfolioProfile $profile,
        TradingStrategy $lender,
        ?CarbonInterface $asOf = null,
    ): array {
        $loans = CapitalLoan::query()
            ->where('profile_id', $profile->id)
            ->where('lender_strategy_id', $lender->id)
            ->where('outstanding', '>', 0)
            ->whereIn('status', [
                CapitalLoan::STATUS_OUTSTANDING,
                CapitalLoan::STATUS_PARTIALLY_RETURNED,
            ])
            ->orderBy('committed_at')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($loans as $loan) {
            if ($this->isLoanEligible($loan, $profile, $asOf)) {
                $out[] = $loan;
            }
        }

        return $out;
    }
}
