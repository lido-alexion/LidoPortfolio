<?php

namespace App\Services\Lending;

use App\Support\CeilToRupee5000;

/**
 * DEP-PARTIAL-ATOMIC loan size for an UNFUNDED this-cycle gap (own capital = 0).
 *
 * gap = this_cycle_target − own_capital (own = 0 ⇒ gap = this_cycle_target)
 * loan_amount = ceil(gap / 5000) × 5000
 *
 * Does not apply OD-06 1% reservation. Does not create capital requests or loans.
 */
final class UnfundedLendingAmountCalculator
{
    public function calculateForUnfundedGap(float $gap): float
    {
        return CeilToRupee5000::ceil($gap);
    }
}
