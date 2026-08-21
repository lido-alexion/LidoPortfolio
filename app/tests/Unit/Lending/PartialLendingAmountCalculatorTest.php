<?php

namespace Tests\Unit\Lending;

use App\Engines\Recommendation\Allocation\ReturnQualityCapitalAllocator;
use App\Services\Lending\PartialLendingAmountCalculator;
use App\Support\CeilToRupee5000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PartialLendingAmountCalculatorTest extends TestCase
{
    private PartialLendingAmountCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PartialLendingAmountCalculator;
    }

    #[DataProvider('remainderCases')]
    public function test_calculate_for_remainder(float $remainder, float $expectedLoan): void
    {
        $this->assertSame($expectedLoan, $this->calculator->calculateForRemainder($remainder));
        $this->assertSame($expectedLoan, CeilToRupee5000::ceil($remainder));
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    public static function remainderCases(): array
    {
        return [
            [0.0, 0.0],
            [1.0, 5000.0],
            [3000.0, 5000.0],
            [4999.0, 5000.0],
            [5000.0, 5000.0],
            [5001.0, 10000.0],
            [14000.0, 15000.0],
            [15000.0, 15000.0],
            [50001.0, 55000.0],
        ];
    }

    public function test_remainder_is_target_minus_own_not_atomic_minus_own(): void
    {
        $target = 18000.0;
        $own = 3000.0;
        $atomic = ReturnQualityCapitalAllocator::atomicAllocation($target);

        $this->assertSame(20000.0, $atomic);
        $this->assertSame(15000.0, $this->calculator->remainderFromTargetAndOwn($target, $own));
        $this->assertSame(15000.0, $this->calculator->calculateForPartialRemainder($target, $own));
        $this->assertNotEquals(17000.0, $this->calculator->calculateForPartialRemainder($target, $own));
        $this->assertNotEquals(
            CeilToRupee5000::ceil($atomic - $own),
            $this->calculator->calculateForPartialRemainder($target, $own)
        );
    }

    public function test_does_not_apply_od06_one_percent_uplift(): void
    {
        $remainder = 10000.0;
        $this->assertSame(10000.0, $this->calculator->calculateForRemainder($remainder));
        $this->assertSame(15000.0, ReturnQualityCapitalAllocator::atomicAllocation($remainder));
        $this->assertNotEquals(
            ReturnQualityCapitalAllocator::atomicAllocation($remainder),
            $this->calculator->calculateForRemainder($remainder)
        );
    }

    public function test_zero_remainder_does_not_emit_minimum_loan(): void
    {
        $this->assertSame(0.0, $this->calculator->calculateForPartialRemainder(18000.0, 18000.0));
        $this->assertSame(0.0, $this->calculator->calculateForRemainder(0.0));
    }

    public function test_has_no_generic_unfunded_loan_api(): void
    {
        $methods = array_map(
            fn ($m) => $m->getName(),
            (new ReflectionClass(PartialLendingAmountCalculator::class))->getMethods()
        );

        $this->assertNotContains('calculateLoanAmount', $methods);
        $this->assertNotContains('calculateForUnfunded', $methods);
        $this->assertNotContains('calculateForGap', $methods);
        $this->assertContains('calculateForRemainder', $methods);
        $this->assertContains('calculateForPartialRemainder', $methods);
    }
}
