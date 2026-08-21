<?php

namespace App\Support;

/**
 * Ceiling to the next whole ₹5,000 block (DEP-PARTIAL-ATOMIC remainder sizing).
 * Not OD-06 (no 1% uplift). Not FloorToRupee5000 (lendable surplus).
 */
final class CeilToRupee5000
{
    public const BLOCK = 5000;

    public static function ceil(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        $rupees = (int) ceil($amount - 1e-9);
        if ($rupees < 1) {
            return 0.0;
        }

        return (float) (intdiv($rupees + self::BLOCK - 1, self::BLOCK) * self::BLOCK);
    }
}
