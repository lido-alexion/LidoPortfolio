<?php

namespace App\Services\Lending;

use App\Support\CeilToRupee5000;

/**
 * DEP-PARTIAL-ATOMIC loan size for a PARTIALLY_FUNDED remainder only.
 *
 * remainder = max(0, target_amount − own_allocated_amount)
 * loan_amount = ceil(remainder / 5000) × 5000
 *
 * Does not size UNFUNDED full-gap loans. Does not apply OD-06 1% reservation.
 * Does not create capital requests or loans.
 */
final class PartialLendingAmountCalculator
{
    /**
     * Loan amount for an already-computed PARTIALLY_FUNDED remainder.
     */
    public function calculateForRemainder(float $remainder): float
    {
        return CeilToRupee5000::ceil($remainder);
    }

    /**
     * Remainder then loan amount: target − own allocation (not atomic_allocation − own).
     */
    public function calculateForPartialRemainder(float $targetAmount, float $ownAllocatedAmount): float
    {
        return $this->calculateForRemainder($this->remainderFromTargetAndOwn($targetAmount, $ownAllocatedAmount));
    }

    public function remainderFromTargetAndOwn(float $targetAmount, float $ownAllocatedAmount): float
    {
        return round(max(0.0, $targetAmount - $ownAllocatedAmount), 4);
    }
}
