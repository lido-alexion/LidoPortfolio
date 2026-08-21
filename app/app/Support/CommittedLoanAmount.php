<?php

namespace App\Support;

/**
 * Persisted capital-request / loan principal constraints (spec §6.4 / §28.5).
 * Minimum loan ₹5,000 and a whole ₹5,000 multiple. Not OD-06. Not UNFUNDED sizing.
 */
final class CommittedLoanAmount
{
    public static function isValid(float $amount): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $rupees = (int) round($amount);
        if (abs($amount - $rupees) > 0.0001) {
            return false;
        }

        return $rupees >= FloorToRupee5000::BLOCK
            && ($rupees % FloorToRupee5000::BLOCK) === 0;
    }
}
