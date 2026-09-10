<?php

namespace Tests\Feature;

use App\Models\PortfolioReconciliationRun;
use App\Models\Stock;
use App\Models\User;
use App\Services\Reconciliation\PortfolioReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class V5PortfolioReconciliationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_instrument_union_and_exact_quantity_mismatch_block_execution_but_unsupported_instruments_do_not(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'AAA', 'exchange' => 'NSE', 'name' => 'AAA', 'is_active' => true]);
        Stock::query()->create(['symbol' => 'BBB', 'exchange' => 'NSE', 'name' => 'BBB', 'is_active' => true]);

        $run = app(PortfolioReconciliationService::class)->recordSuccessful($profile, 'manual', [
            'holdings' => [
                ['symbol' => 'AAA', 'quantity' => 10, 'cost' => 1000],
                ['symbol' => 'UNSUPPORTED', 'quantity' => 5, 'cost' => 500],
            ],
            'current_cash' => 1000,
        ], [
            'holdings' => [
                ['symbol' => 'AAA', 'quantity' => 9, 'cost' => 900],
                ['symbol' => 'BBB', 'quantity' => 2, 'cost' => 200],
            ],
            'cash_balance' => 1000,
        ], ['holding_cost' => 5, 'funds' => 1]);

        $this->assertSame('mismatch', $run->holdings_status);
        $this->assertSame('reconciled', $run->funds_status);
        $this->assertCount(2, $run->discrepancies['holdings']);
        $this->assertCount(1, $run->unsupported_instruments);
        $this->assertTrue($profile->fresh()->execution_blocked_by_reconciliation);
    }

    public function test_cash_only_mismatch_requires_attention_without_execution_block_and_runs_are_immutable(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        Stock::query()->create(['symbol' => 'AAA', 'exchange' => 'NSE', 'name' => 'AAA', 'is_active' => true]);

        $run = app(PortfolioReconciliationService::class)->recordSuccessful($profile, 'scheduled', [
            'holdings' => [['symbol' => 'AAA', 'quantity' => 10]], 'current_cash' => 1100,
        ], [
            'holdings' => [['symbol' => 'AAA', 'quantity' => 10]], 'cash_balance' => 1000,
        ], ['holding_cost' => 5, 'funds' => 10]);

        $this->assertSame('reconciled', $run->holdings_status);
        $this->assertSame('mismatch', $run->funds_status);
        $this->assertSame('attention_required', $run->overall_status);
        $this->assertFalse($profile->fresh()->execution_blocked_by_reconciliation);
        $this->expectException(LogicException::class);
        PortfolioReconciliationRun::query()->findOrFail($run->id)->update(['trigger' => 'manual']);
    }
}
