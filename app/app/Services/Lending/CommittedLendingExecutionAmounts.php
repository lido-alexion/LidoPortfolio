<?php

namespace App\Services\Lending;

use App\Models\CapitalLoan;
use App\Models\TradingRecommendation;

/**
 * Distinguishes target / own / borrowed / intended execution for a CAPITAL_COMMITTED BUY.
 *
 * Intended amount is own + unfunded remainder (the original target), not own + full loan.
 * ₹5,000 loan ceiling excess is not invested and is not a new product policy.
 */
final class CommittedLendingExecutionAmounts
{
    /**
     * @return array{
     *     target_amount: float,
     *     own_amount: float,
     *     remainder: float,
     *     borrowed_amount: float,
     *     intended_amount: float,
     *     excess_borrowed_amount: float
     * }
     */
    public function forRecommendation(TradingRecommendation $recommendation, ?CapitalLoan $loan): array
    {
        $target = round((float) ($recommendation->capitalTargetAmount() ?? 0), 4);
        $own = round((float) ($recommendation->ownAllocatedAmount() ?? 0), 4);
        $remainder = round(max(0.0, $target - $own), 4);
        $borrowed = $loan !== null ? round((float) $loan->principal, 4) : 0.0;
        $intended = round($own + $remainder, 4);
        $excess = round(max(0.0, $borrowed - $remainder), 4);

        return [
            'target_amount' => $target,
            'own_amount' => $own,
            'remainder' => $remainder,
            'borrowed_amount' => $borrowed,
            'intended_amount' => $intended,
            'excess_borrowed_amount' => $excess,
        ];
    }
}
