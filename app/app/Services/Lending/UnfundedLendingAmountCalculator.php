<?php

namespace App\Services\Lending;

use LogicException;

/**
 * Placeholder for UNFUNDED full-gap loan sizing.
 *
 * Not frozen: spec/audit leaves both
 *   ceil(gap / 5000) × 5000
 * and
 *   ceil(gap × 1.01 / 5000) × 5000
 * unresolved. Do not call this to create capital requests.
 */
final class UnfundedLendingAmountCalculator
{
    public function calculateForUnfundedGap(float $gap): float
    {
        throw new LogicException(
            'UNFUNDED full-gap loan sizing is not frozen (DEP-PARTIAL-ATOMIC vs OD-06 1%+ceil). Gap='.$gap
        );
    }
}
