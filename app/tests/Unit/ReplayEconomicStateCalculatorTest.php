<?php

namespace Tests\Unit;

use App\Services\Simulation\ReplayEconomicStateCalculator;
use PHPUnit\Framework\TestCase;

class ReplayEconomicStateCalculatorTest extends TestCase
{
    public function test_values_owned_holdings_reserve_reservations_and_loans_deterministically(): void
    {
        $state = [
            'cash_balance' => 50000,
            'holdings' => [
                ['stock_id' => 10, 'quantity' => 100, 'avg_buy_price' => 80, 'strategy_id' => 1],
                ['stock_id' => 20, 'quantity' => 10, 'avg_buy_price' => 50, 'strategy_id' => null],
            ],
            'reservations' => [['strategy_id' => 1, 'amount' => 1000]],
            'loans' => [['lender_strategy_id' => 1, 'borrower_strategy_id' => 2, 'outstanding' => 5000]],
            'recall_bridge_loans' => [],
            'strategies' => [
                ['strategy_id' => 1, 'allocation_pct' => 60],
                ['strategy_id' => 2, 'allocation_pct' => 40],
            ],
        ];

        $result = (new ReplayEconomicStateCalculator())->advance(
            $state,
            ['portfolio_cash_reserve_pct' => '10'],
            [10 => 100, 20 => 60],
        );

        $this->assertSame(60600.0, $result['valuation']['total_value']);
        $this->assertSame(1060.0, $result['capital']['required_cash_reserve']);
        $this->assertSame(57940.0, $result['capital']['investable_capital']);
        $this->assertSame(10000.0, $result['strategies'][0]['owned_market_value']);
        $this->assertSame(5000.0, $result['strategies'][0]['lent']);
        $this->assertSame(5000.0, $result['strategies'][1]['borrowed']);
        $this->assertSame(18764.0, $result['strategies'][0]['unused_allocation']);
        $this->assertSame(23176.0, $result['strategies'][1]['available_capital']);
    }
}
