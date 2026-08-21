<?php

namespace Tests\Unit\Lending;

use App\Services\Lending\UnfundedLendingAmountCalculator;
use LogicException;
use PHPUnit\Framework\TestCase;

class UnfundedLendingAmountCalculatorTest extends TestCase
{
    public function test_does_not_choose_a_loan_size_policy(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('UNFUNDED full-gap loan sizing is not frozen');

        (new UnfundedLendingAmountCalculator)->calculateForUnfundedGap(18000.0);
    }
}
