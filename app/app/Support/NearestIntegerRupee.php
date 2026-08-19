<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * OD-24 nearest-integer rupee rounding.
 * Exact .5 rounds upward. Not language round() / banker's rounding.
 * Non-negative domain equivalent: floor(value + 0.5).
 */
final class NearestIntegerRupee
{
    public static function round(float $value): int
    {
        if ($value < 0) {
            throw new InvalidArgumentException('OD-24 nearest-integer rupee rounding is defined for non-negative amounts.');
        }

        return (int) floor($value + 0.5);
    }
}
