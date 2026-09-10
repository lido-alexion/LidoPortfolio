<?php

namespace Tests\Unit\Backtest;

use App\Services\Backtest\PaperTradeExecutor;
use App\Services\Backtest\SimulationContext;
use PHPUnit\Framework\TestCase;

class PaperPortfolioSimulationTest extends TestCase
{
    public function test_buy_and_sell_updates_cash_and_closes_lot(): void
    {
        $ctx = SimulationContext::blank(100000, ['2026-01-02', '2026-01-03']);
        $exec = new PaperTradeExecutor($ctx);

        $buy = $exec->buy('2026-01-02', 1, 'AAA', 10, 100, 'recommendation', 'OPEN_POSITION');
        $this->assertTrue($buy['ok']);
        $this->assertSame(99000.0, $ctx->cash());
        $this->assertSame(10.0, (float) $ctx->holdings()['1']['qty']);

        $sell = $exec->sell('2026-01-03', 1, 'AAA', 10, 110, 'exit_strategy', 'EXIT_POSITION');
        $this->assertTrue($sell['ok']);
        $this->assertSame(100100.0, $ctx->cash());
        $this->assertSame([], $ctx->holdings());
        $this->assertCount(1, $sell['closed_trades']);
        $this->assertSame(100.0, (float) $sell['closed_trades'][0]['profit_loss']);
        $this->assertSame(1, (int) $sell['closed_trades'][0]['holding_days']);
        $this->assertNull($sell['closed_trades'][0]['cagr']);
    }

    public function test_buy_clamps_to_affordable_shares(): void
    {
        $ctx = SimulationContext::blank(250, ['2026-01-02']);
        $exec = new PaperTradeExecutor($ctx);
        $buy = $exec->buy('2026-01-02', 2, 'BBB', 10, 100, 'recommendation', 'OPEN_POSITION');
        $this->assertTrue($buy['ok']);
        $this->assertSame(2.0, (float) $buy['transaction']['quantity']);
        $this->assertSame(50.0, $ctx->cash());
    }

    public function test_context_time_budget_constant(): void
    {
        $this->assertSame(20.0, SimulationContext::TIME_BUDGET_SECONDS);
    }

    public function test_pinned_simulated_fees_affect_cash_realized_profit_and_evidence(): void
    {
        $ctx = SimulationContext::blank(2000, ['2026-01-02', '2026-01-03']);
        $exec = new PaperTradeExecutor($ctx);
        $buy = $exec->buy('2026-01-02', 3, 'FEE', 10, 100, 'recommendation', 'OPEN_POSITION', null, 5, ['charge_model_version' => 'v1']);
        $this->assertTrue($buy['ok']);
        $this->assertSame(995.0, $ctx->cash());
        $this->assertSame(5.0, $buy['transaction']['meta_json']['fees']);

        $sell = $exec->sell('2026-01-03', 3, 'FEE', 10, 110, 'recommendation', 'EXIT_POSITION', 6, ['charge_model_version' => 'v1']);
        $this->assertTrue($sell['ok']);
        $this->assertSame(2089.0, $ctx->cash());
        $this->assertSame(94.0, $ctx->get('realized_profit'));
        $this->assertSame(11.0, $ctx->get('total_fees'));
        $this->assertSame(94.0, $sell['closed_trades'][0]['profit_loss']);
        $this->assertSame('v1', $sell['transaction']['meta_json']['execution_evidence']['charge_model_version']);
    }
}
