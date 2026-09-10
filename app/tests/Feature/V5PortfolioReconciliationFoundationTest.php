<?php

namespace Tests\Feature;

use App\Engines\Execution\ExecutionGate;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\PortfolioReconciliationRun;
use App\Models\Stock;
use App\Models\User;
use App\Services\Broker\BrokerGateway;
use App\Services\Broker\FakeBrokerGateway;
use App\Services\CashManagementService;
use App\Services\Reconciliation\PortfolioReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class V5PortfolioReconciliationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_orchestration_snapshots_kite_and_stox_without_mutating_ledgers_and_failure_preserves_last_success(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $profile->forceFill(['execution_mode' => PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC])->save();
        $stock = Stock::query()->create(['symbol' => 'AAA', 'exchange' => 'NSE', 'name' => 'AAA', 'is_active' => true]);
        Holding::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id,
            'quantity' => 10, 'avg_buy_price' => 100, 'invested_amount' => 1000,
        ]);
        app(CashManagementService::class)->deposit($profile, 500, 'Opening cash', $user);
        /** @var FakeBrokerGateway $broker */
        $broker = app(BrokerGateway::class);
        $broker->reconciliationSnapshot = [
            'provider' => 'kite', 'captured_at' => now()->toISOString(),
            'holdings' => [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 10, 'cost' => 1000]],
            'positions' => [['symbol' => 'AAA', 'quantity' => 1]], 'current_cash' => 500,
        ];
        $transactionCount = $profile->transactions()->count();

        $success = app(PortfolioReconciliationService::class)->run($profile->fresh(), 'manual');

        $this->assertSame('reconciled', $success->overall_status);
        $this->assertSame($transactionCount, $profile->transactions()->count());
        $this->assertSame(1, count($success->broker_snapshot['positions']));
        $successfulAt = $profile->fresh()->last_successful_reconciliation_at;

        $broker->reconciliationSnapshotUnavailable = true;
        $failed = app(PortfolioReconciliationService::class)->run($profile->fresh(), 'post_trade');
        $this->assertSame('sync_failed', $failed->status);
        $this->assertSame('reconciled', $profile->fresh()->reconciliation_overall_status);
        $this->assertFalse($profile->fresh()->execution_blocked_by_reconciliation);
        $this->assertTrue($successfulAt->equalTo($profile->fresh()->last_successful_reconciliation_at));
        $this->assertNotNull($profile->fresh()->last_reconciliation_failure);
    }

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
        $this->assertContains(
            'reconciliation_holdings_mismatch',
            app(ExecutionGate::class)->blockers($user, $profile->fresh())
        );
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
