<?php

namespace Tests\Unit\Backtest;

use App\Services\Backtest\BacktestMath;
use App\Services\Backtest\PaperTradeExecutor;
use App\Services\Backtest\SimulationContext;
use PHPUnit\Framework\TestCase;

class EntryScorePersistenceTest extends TestCase
{
    private function makeExecutor(float $cash = 1_000_000): PaperTradeExecutor
    {
        $ctx = SimulationContext::blank($cash, ['2025-01-02']);

        return new PaperTradeExecutor($ctx);
    }

    private function ctx(PaperTradeExecutor $executor): SimulationContext
    {
        $ref = new \ReflectionProperty(PaperTradeExecutor::class, 'ctx');

        return $ref->getValue($executor);
    }

    // ---------------------------------------------------------------
    // A. BUY entry score is captured on the open lot.
    // ---------------------------------------------------------------

    public function test_buy_stores_entry_score_on_open_lot(): void
    {
        $exec = $this->makeExecutor();
        $result = $exec->buy('2025-01-02', 42, 'RELIANCE', 10, 100.0, 'recommendation', 'OPEN_POSITION', 73.25);

        $this->assertTrue($result['ok']);

        $lots = $this->ctx($exec)->get('open_lots');
        $this->assertCount(1, $lots['42']);
        $this->assertSame(73.25, $lots['42'][0]['entry_score']);
    }

    public function test_buy_without_entry_score_stores_null(): void
    {
        $exec = $this->makeExecutor();
        $exec->buy('2025-01-02', 42, 'RELIANCE', 10, 100.0, 'recommendation', 'OPEN_POSITION');

        $lots = $this->ctx($exec)->get('open_lots');
        $this->assertNull($lots['42'][0]['entry_score']);
    }

    // ---------------------------------------------------------------
    // B. Entry score survives closing (appears on closed trade).
    // ---------------------------------------------------------------

    public function test_entry_score_survives_close(): void
    {
        $exec = $this->makeExecutor();
        $exec->buy('2025-01-02', 42, 'RELIANCE', 10, 100.0, 'recommendation', 'OPEN_POSITION', 73.25);

        $sellResult = $exec->sell('2025-02-15', 42, 'RELIANCE', 10, 120.0, 'exit_strategy', 'EXIT_POSITION');

        $this->assertTrue($sellResult['ok']);
        $this->assertCount(1, $sellResult['closed_trades']);
        $this->assertSame(73.25, $sellResult['closed_trades'][0]['entry_score']);
    }

    // ---------------------------------------------------------------
    // C. Entry score is independent of exit-time score.
    // ---------------------------------------------------------------

    public function test_entry_score_independent_of_exit_time_score(): void
    {
        $exec = $this->makeExecutor();
        $exec->buy('2025-01-02', 42, 'RELIANCE', 10, 100.0, 'recommendation', 'OPEN_POSITION', 73.25);

        $sellResult = $exec->sell('2025-02-15', 42, 'RELIANCE', 10, 80.0, 'recommendation', 'EXIT_POSITION');

        $trade = $sellResult['closed_trades'][0];
        $this->assertSame(73.25, $trade['entry_score']);
        $this->assertSame(false, $trade['is_open']);
        $this->assertSame(80.0, $trade['sell_price']);
    }

    // ---------------------------------------------------------------
    // D. Existing backtest trade fields remain unchanged.
    // ---------------------------------------------------------------

    public function test_closed_trade_preserves_all_existing_fields(): void
    {
        $exec = $this->makeExecutor();
        $exec->buy('2025-01-02', 42, 'RELIANCE', 10, 100.0, 'recommendation', 'OPEN_POSITION', 73.25);

        $sellResult = $exec->sell('2025-03-03', 42, 'RELIANCE', 10, 110.0, 'recommendation', 'EXIT_POSITION');
        $trade = $sellResult['closed_trades'][0];

        $this->assertSame(42, $trade['stock_id']);
        $this->assertSame('RELIANCE', $trade['symbol']);
        $this->assertSame('2025-01-02', $trade['buy_date']);
        $this->assertSame('2025-03-03', $trade['sell_date']);
        $this->assertSame(100.0, $trade['buy_price']);
        $this->assertSame(110.0, $trade['sell_price']);
        $this->assertEquals(10.0, $trade['quantity']);
        $this->assertFalse($trade['is_open']);
        $this->assertArrayHasKey('holding_days', $trade);
        $this->assertArrayHasKey('profit_loss', $trade);
        $this->assertArrayHasKey('return_pct', $trade);
        $this->assertArrayHasKey('cagr', $trade);
        $this->assertArrayHasKey('exit_reason', $trade);
    }

    // ---------------------------------------------------------------
    // E. Historical rows can legitimately have NULL entry_score.
    // ---------------------------------------------------------------

    public function test_null_entry_score_is_valid_for_legacy_lots(): void
    {
        $exec = $this->makeExecutor();
        $ctx = $this->ctx($exec);

        $ctx->set('open_lots', [
            '42' => [
                ['qty' => 5, 'price' => 100.0, 'buy_date' => '2025-01-02'],
            ],
        ]);
        $ctx->setHoldings([
            '42' => ['qty' => 5, 'avg_cost' => 100.0, 'buy_date' => '2025-01-02', 'symbol' => 'RELIANCE', 'invested' => 500.0],
        ]);

        $sellResult = $exec->sell('2025-02-15', 42, 'RELIANCE', 5, 110.0, 'recommendation', 'EXIT_POSITION');

        $this->assertTrue($sellResult['ok']);
        $this->assertNull($sellResult['closed_trades'][0]['entry_score']);
    }

    // ---------------------------------------------------------------
    // Entry score preserved on partial lot remainder.
    // ---------------------------------------------------------------

    public function test_partial_lot_close_preserves_entry_score_on_remainder(): void
    {
        $exec = $this->makeExecutor();
        $exec->buy('2025-01-02', 42, 'RELIANCE', 20, 100.0, 'recommendation', 'OPEN_POSITION', 85.0);

        $exec->sell('2025-02-15', 42, 'RELIANCE', 10, 110.0, 'recommendation', 'REDUCE_POSITION');

        $lots = $this->ctx($exec)->get('open_lots');
        $this->assertCount(1, $lots['42']);
        $this->assertEquals(10.0, $lots['42'][0]['qty']);
        $this->assertSame(85.0, $lots['42'][0]['entry_score']);
    }

    // ---------------------------------------------------------------
    // Multiple lots with different entry scores.
    // ---------------------------------------------------------------

    public function test_fifo_close_uses_correct_entry_score_per_lot(): void
    {
        $exec = $this->makeExecutor();
        $exec->buy('2025-01-02', 42, 'RELIANCE', 10, 100.0, 'recommendation', 'OPEN_POSITION', 60.0);
        $exec->buy('2025-01-10', 42, 'RELIANCE', 10, 105.0, 'recommendation', 'INCREASE_POSITION', 80.0);

        $sellResult = $exec->sell('2025-02-15', 42, 'RELIANCE', 15, 120.0, 'recommendation', 'EXIT_POSITION');

        $trades = $sellResult['closed_trades'];
        $this->assertCount(2, $trades);
        $this->assertSame(60.0, $trades[0]['entry_score']);
        $this->assertEquals(10.0, $trades[0]['quantity']);
        $this->assertSame(80.0, $trades[1]['entry_score']);
        $this->assertEquals(5.0, $trades[1]['quantity']);

        $lots = $this->ctx($exec)->get('open_lots');
        $this->assertCount(1, $lots['42']);
        $this->assertEquals(5.0, $lots['42'][0]['qty']);
        $this->assertSame(80.0, $lots['42'][0]['entry_score']);
    }
}
