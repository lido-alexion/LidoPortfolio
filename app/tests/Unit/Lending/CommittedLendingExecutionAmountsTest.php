<?php

namespace Tests\Unit\Lending;

use App\Models\CapitalLoan;
use App\Models\TradingRecommendation;
use App\Services\Lending\CommittedLendingExecutionAmounts;
use PHPUnit\Framework\TestCase;

class CommittedLendingExecutionAmountsTest extends TestCase
{
    public function test_intended_amount_is_target_not_own_plus_full_loan(): void
    {
        $rec = new TradingRecommendation;
        $rec->execution_plan = [
            'target_investment_amount' => 18000.0,
            'suggested_investment_amount' => 10000.0,
            'capital_allocation' => [
                'status' => TradingRecommendation::ALLOCATION_CAPITAL_COMMITTED,
                'target_amount' => 18000.0,
                'allocated_amount' => 10000.0,
            ],
        ];
        $loan = new CapitalLoan;
        $loan->principal = 10000.0;

        $amounts = (new CommittedLendingExecutionAmounts)->forRecommendation($rec, $loan);

        $this->assertEquals(18000.0, $amounts['target_amount']);
        $this->assertEquals(10000.0, $amounts['own_amount']);
        $this->assertEquals(8000.0, $amounts['remainder']);
        $this->assertEquals(10000.0, $amounts['borrowed_amount']);
        $this->assertEquals(18000.0, $amounts['intended_amount']);
        $this->assertEquals(2000.0, $amounts['excess_borrowed_amount']);
        $this->assertNotEquals(20000.0, $amounts['intended_amount']);
    }
}
