<?php

namespace App\Services\Lending;

use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use App\Models\TradingStrategy;
use App\Services\Strategy\PortfolioCapitalAccountingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Good-faith voluntary repayment of Recall Bridge Loans when liquid funds are available.
 * Any amount ≤ outstanding; no ₹5,000 blocks; no interest; bridge cannot be recalled.
 */
final class GoodFaithBridgeRepaymentService
{
    public function __construct(
        protected PortfolioCapitalAccountingService $accounting,
        protected RecallBridgeLoanService $bridges,
        protected CapitalResolutionService $capitalResolution,
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

        $loans = RecallBridgeLoan::query()
            ->where('profile_id', $profile->id)
            ->where('borrower_strategy_id', $borrower->id)
            ->where('outstanding', '>', 0)
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
            $this->bridges->repay($loan, $pay);
            $available = round($available - $pay, 4);
            $repaidTotal = round($repaidTotal + $pay, 4);
            $details[] = [
                'bridge_loan_id' => $loan->id,
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
        $profileIds = RecallBridgeLoan::query()
            ->where('outstanding', '>', 0)
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
            $borrowerIds = RecallBridgeLoan::query()
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
