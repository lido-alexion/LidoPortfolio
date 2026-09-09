<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\MoneyWeightedReturnCalculator;
use PHPUnit\Framework\TestCase;

class MoneyWeightedReturnCalculatorTest extends TestCase
{
    public function test_one_year_growth_produces_xirr(): void
    {
        $result = (new MoneyWeightedReturnCalculator)->calculate(
            '2025-01-01', '2026-01-01', 1000, 1100, [],
        );

        $this->assertEqualsWithDelta(10.0, $result, 0.0001);
    }

    public function test_contributions_and_withdrawals_use_actual_dates(): void
    {
        $calculator = new MoneyWeightedReturnCalculator;
        $withoutContribution = $calculator->calculate('2025-01-01', '2026-01-01', 1000, 1600, []);
        $withContribution = $calculator->calculate('2025-01-01', '2026-01-01', 1000, 1600, [
            ['date' => '2025-07-01', 'amount' => 500],
        ]);

        $this->assertNotNull($withContribution);
        $this->assertLessThan($withoutContribution, $withContribution);
    }

    public function test_invalid_or_one_sided_cash_flows_are_not_fabricated(): void
    {
        $calculator = new MoneyWeightedReturnCalculator;

        $this->assertNull($calculator->calculate('2026-01-01', '2026-01-01', 100, 110, []));
        $this->assertNull($calculator->calculate('2025-01-01', '2026-01-01', 0, 0, []));
    }
}
