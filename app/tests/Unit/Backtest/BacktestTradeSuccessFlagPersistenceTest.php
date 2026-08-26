<?php

namespace Tests\Unit\Backtest;

use App\Models\BacktestRun;
use App\Models\BacktestTrade;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\Backtest\BacktestPersistenceService;
use App\Services\IndexCatalogService;
use App\Services\ProfileSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacktestTradeSuccessFlagPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_trade_persists_is_success_true_when_beats_nifty_and_opp_cost(): void
    {
        [$run, $stock] = $this->seedRunAndBenchmark(
            niftyBuy: 100.0,
            niftySell: 102.0, // +2%
        );

        app(BacktestPersistenceService::class)->persistDayResults(
            $run,
            [],
            [[
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'buy_date' => '2024-01-02',
                'sell_date' => '2024-02-01',
                'holding_days' => 30,
                'buy_price' => 100.0,
                'sell_price' => 110.0,
                'quantity' => 10,
                'profit_loss' => 100.0,
                'return_pct' => 10.0, // +10% beats nifty + opp cost
                'cagr' => null,
                'exit_reason' => 'EXIT',
                'entry_score' => 80.0,
                'is_open' => false,
            ]],
            $this->snapshot('2024-02-01'),
        );

        $trade = BacktestTrade::query()->where('backtest_run_id', $run->id)->first();
        $this->assertNotNull($trade);
        $this->assertFalse((bool) $trade->is_open);
        $this->assertNotNull($trade->benchmark_return_pct);
        $this->assertEqualsWithDelta(2.0, (float) $trade->benchmark_return_pct, 0.0001);
        $this->assertTrue($trade->is_success);
    }

    public function test_closed_trade_persists_is_success_false_on_negative_return(): void
    {
        [$run, $stock] = $this->seedRunAndBenchmark(
            niftyBuy: 100.0,
            niftySell: 95.0, // −5%
        );

        app(BacktestPersistenceService::class)->persistDayResults(
            $run,
            [],
            [[
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'buy_date' => '2024-01-02',
                'sell_date' => '2024-02-01',
                'holding_days' => 30,
                'buy_price' => 100.0,
                'sell_price' => 90.0,
                'quantity' => 10,
                'profit_loss' => -100.0,
                'return_pct' => -10.0,
                'cagr' => null,
                'exit_reason' => 'EXIT',
                'entry_score' => 50.0,
                'is_open' => false,
            ]],
            $this->snapshot('2024-02-01'),
        );

        $trade = BacktestTrade::query()->where('backtest_run_id', $run->id)->first();
        $this->assertNotNull($trade);
        $this->assertNotNull($trade->benchmark_return_pct);
        $this->assertEqualsWithDelta(-5.0, (float) $trade->benchmark_return_pct, 0.0001);
        $this->assertFalse($trade->is_success);
    }

    public function test_open_trade_leaves_is_success_null(): void
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'Flags', true);
        $stock = Stock::query()->create([
            'symbol' => 'OPENCO',
            'exchange' => 'NSE',
            'name' => 'Open Co',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $run = BacktestRun::query()->create([
            'profile_id' => $profile->id,
            'name' => 'open lots',
            'from_date' => '2024-01-01',
            'to_date' => '2024-06-01',
            'initial_capital' => 1_000_000,
            'status' => BacktestRun::STATUS_RUNNING,
            'stage' => BacktestRun::STAGE_SIMULATING_DAYS,
        ]);

        BacktestTrade::query()->create([
            'backtest_run_id' => $run->id,
            'stock_id' => $stock->id,
            'symbol' => $stock->symbol,
            'buy_date' => '2024-01-02',
            'sell_date' => null,
            'holding_days' => 10,
            'buy_price' => 100.0,
            'sell_price' => 105.0,
            'quantity' => 5,
            'profit_loss' => 25.0,
            'return_pct' => 5.0,
            'cagr' => null,
            'exit_reason' => 'open_at_end',
            'is_open' => true,
        ]);

        $trade = BacktestTrade::query()->where('backtest_run_id', $run->id)->first();
        $this->assertNull($trade->is_success);
        $this->assertNull($trade->benchmark_return_pct);
    }

    /**
     * @return array{0: BacktestRun, 1: Stock}
     */
    private function seedRunAndBenchmark(float $niftyBuy, float $niftySell): array
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'SuccessFlags', true);
        app(ProfileSettingsService::class)->set($profile, 'opportunity_cost_rate', '0.12');

        $stock = Stock::query()->create([
            'symbol' => 'ABC',
            'exchange' => 'NSE',
            'name' => 'ABC Ltd',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $nifty = app(IndexCatalogService::class)->primaryBenchmarkStock();
        StockPrice::query()->create([
            'stock_id' => $nifty->id,
            'price_date' => '2024-01-02',
            'close_price' => $niftyBuy,
            'adjusted_close_price' => $niftyBuy,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $nifty->id,
            'price_date' => '2024-02-01',
            'close_price' => $niftySell,
            'adjusted_close_price' => $niftySell,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $run = BacktestRun::query()->create([
            'profile_id' => $profile->id,
            'name' => 'section19 flags',
            'from_date' => '2024-01-01',
            'to_date' => '2024-06-01',
            'initial_capital' => 1_000_000,
            'status' => BacktestRun::STATUS_RUNNING,
            'stage' => BacktestRun::STAGE_SIMULATING_DAYS,
        ]);

        return [$run, $stock];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $date): array
    {
        return [
            'snapshot_date' => $date,
            'cash' => 1_000_000,
            'invested_value' => 0,
            'portfolio_value' => 1_000_000,
            'realized_profit' => 0,
            'unrealized_profit' => 0,
            'drawdown_pct' => 0,
            'holdings_count' => 0,
        ];
    }
}
