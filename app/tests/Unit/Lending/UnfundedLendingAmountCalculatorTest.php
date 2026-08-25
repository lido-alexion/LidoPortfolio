<?php

namespace Tests\Unit\Lending;

use App\Engines\Recommendation\Allocation\ReturnQualityCapitalAllocator;
use App\Services\Lending\UnfundedLendingAmountCalculator;
use App\Support\CeilToRupee5000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnfundedLendingAmountCalculatorTest extends TestCase
{
    private UnfundedLendingAmountCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new UnfundedLendingAmountCalculator;
    }

    #[DataProvider('gapCases')]
    public function test_calculate_for_unfunded_gap(float $gap, float $expectedLoan): void
    {
        $this->assertSame($expectedLoan, $this->calculator->calculateForUnfundedGap($gap));
        $this->assertSame($expectedLoan, CeilToRupee5000::ceil($gap));
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    public static function gapCases(): array
    {
        return [
            [0.0, 0.0],
            [1.0, 5000.0],
            [3000.0, 5000.0],
            [4999.0, 5000.0],
            [5000.0, 5000.0],
            [5001.0, 10000.0],
            [20000.0, 20000.0],
        ];
    }

    public function test_does_not_apply_od06_one_percent_uplift(): void
    {
        $this->assertSame(10000.0, $this->calculator->calculateForUnfundedGap(10000.0));
        $this->assertSame(15000.0, ReturnQualityCapitalAllocator::atomicAllocation(10000.0));
        $this->assertNotEquals(
            ReturnQualityCapitalAllocator::atomicAllocation(10000.0),
            $this->calculator->calculateForUnfundedGap(10000.0)
        );
    }
}
