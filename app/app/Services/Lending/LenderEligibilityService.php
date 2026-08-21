<?php

namespace App\Services\Lending;

use App\Models\PortfolioProfile;
use App\Services\Strategy\PortfolioCapitalAccountingService;
use App\Support\CommittedLoanAmount;

/**
 * Eligible lenders for a requested loan amount (spec §8.1).
 * Availability is PortfolioCapitalAccountingService available_for_lending only.
 */
final class LenderEligibilityService
{
    public function __construct(
        protected PortfolioCapitalAccountingService $capital,
        protected LenderRankingService $ranking,
    ) {}

    /**
     * @return list<array{
     *     strategy_id: int,
     *     name: string,
     *     available_for_lending: float,
     *     maximum_lendable_amount: float,
     *     strategy_capital_allocation: float,
     *     available_for_lending_pct: float
     * }>
     */
    public function eligibleLenders(PortfolioProfile $profile, int $borrowerStrategyId, float $amount): array
    {
        if (! CommittedLoanAmount::isValid($amount)) {
            return [];
        }

        $snapshot = $this->capital->snapshot($profile);
        $candidates = [];
        foreach ($snapshot['strategies'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sid = (int) ($row['strategy_id'] ?? 0);
            if ($sid < 1 || $sid === $borrowerStrategyId) {
                continue;
            }
            $afl = (float) ($row['available_for_lending'] ?? 0);
            if ($afl + 0.0001 < $amount) {
                continue;
            }
            $allocated = (float) ($row['strategy_capital_allocation'] ?? 0);
            $candidates[] = [
                'strategy_id' => $sid,
                'name' => (string) ($row['name'] ?? ''),
                'available_for_lending' => $afl,
                'maximum_lendable_amount' => $afl,
                'strategy_capital_allocation' => $allocated,
                'available_for_lending_pct' => $allocated > 0 ? $afl / $allocated : 0.0,
            ];
        }

        return $this->ranking->rank($candidates);
    }
}
