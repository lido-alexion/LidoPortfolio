<?php

namespace App\Support;

/**
 * V3 §8.2 lendable surplus alignment: floor to whole ₹5,000 blocks.
 * Not OD-06 (ceil × 1.01) and not OD-12 minimum actionable amount.
 */
final class FloorToRupee5000
{
    public const BLOCK = 5000;

    public static function floor(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        $rupees = (int) floor($amount + 1e-9);
        if ($rupees < self::BLOCK) {
            return 0.0;
        }

        return (float) (intdiv($rupees, self::BLOCK) * self::BLOCK);
    }
}
