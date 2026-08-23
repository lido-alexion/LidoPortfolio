<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\PortfolioProfile;
use App\Models\TradingStrategy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * §6.15 — automatic voluntary repayment of normal CapitalLoan when surplus exists.
 * No ₹5,000 restriction. No interest. Never repay more than outstanding.
 * Uses strategy_available_capital (excludes reserved/pending-execution commitments).
 */
final class GoodFaithCapitalLoanRepaymentService
{
    public function __construct(
        protected CapitalResolutionService $capitalResolution,
        protected CapitalLoanRepaymentService $repayments,
    ) {}

    /**
     * @return array{repaid_total: float, loans: list<array<string, mixed>>}
     */
    public function repayAvailable(
        PortfolioProfile $profile,
        TradingStrategy $borrower,
        ?CarbonInterface $asOf = null,
        ?float $availableOverride = null,
    ): array {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $available = $availableOverride !== null
            ? round(max(0.0, $availableOverride), 4)
            : $this->capitalResolution->strategyAvailableCapital($profile, $borrower);

        $loans = CapitalLoan::query()
            ->where('profile_id', $profile->id)
            ->where('borrower_strategy_id', $borrower->id)
            ->where('outstanding', '>', 0)
            ->whereIn('status', [
                CapitalLoan::STATUS_OUTSTANDING,
                CapitalLoan::STATUS_PARTIALLY_RETURNED,
            ])
            ->orderBy('committed_at')
            ->orderBy('id')
            ->get();

        $repaidTotal = 0.0;
        $details = [];

        foreach ($loans as $loan) {
            if ($available <= 0.0001) {
                break;
            }
            $outstanding = round((float) $loan->outstanding, 4);
            $pay = round(min($available, $outstanding), 4);
            if ($pay <= 0) {
                continue;
            }
            $this->repayments->repay($loan, $pay);
            $available = round($available - $pay, 4);
            $repaidTotal = round($repaidTotal + $pay, 4);
            $details[] = [
                'loan_id' => $loan->id,
                'paid' => $pay,
                'outstanding_after' => (float) $loan->fresh()->outstanding,
            ];
        }

        return [
            'repaid_total' => $repaidTotal,
            'loans' => $details,
            'as_of' => $asOf->toIso8601String(),
        ];
    }

    /**
     * @return array{profiles: int, repaid_total: float}
     */
    public function repayAllProfiles(?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $profileIds = CapitalLoan::query()
            ->where('outstanding', '>', 0)
            ->whereIn('status', [
                CapitalLoan::STATUS_OUTSTANDING,
                CapitalLoan::STATUS_PARTIALLY_RETURNED,
            ])
            ->distinct()
            ->pluck('profile_id');

        $repaid = 0.0;
        $count = 0;
        foreach ($profileIds as $profileId) {
            $profile = PortfolioProfile::query()->find($profileId);
            if (! $profile) {
                continue;
            }
            $count++;
            $borrowerIds = CapitalLoan::query()
                ->where('profile_id', $profileId)
                ->where('outstanding', '>', 0)
                ->distinct()
                ->pluck('borrower_strategy_id');
            foreach ($borrowerIds as $borrowerId) {
                $borrower = TradingStrategy::query()->find($borrowerId);
                if (! $borrower) {
                    continue;
                }
                $result = $this->repayAvailable($profile, $borrower, $asOf);
                $repaid += $result['repaid_total'];
            }
        }

        return [
            'profiles' => $count,
            'repaid_total' => round($repaid, 4),
        ];
    }
}
