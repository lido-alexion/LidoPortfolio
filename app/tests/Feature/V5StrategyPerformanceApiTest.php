<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingStrategy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5StrategyPerformanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_xirr_requires_owner_attributed_history_and_invents_no_cash(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id, 'name' => 'Strategy Performance', 'slug' => 'strategy-performance',
            'status' => 'active', 'allocation_pct' => 100,
        ]);
        $stock = Stock::query()->create(['symbol' => 'SXIRR', 'exchange' => 'NSE', 'name' => 'Strategy XIRR']);
        Transaction::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 1, 'price' => 100, 'fees' => 0, 'transaction_date' => '2025-01-01',
            'owner_key' => 'strategy:'.$strategy->id,
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => '2026-01-01', 'close_price' => 110,
            'adjusted_close_price' => 110, 'data_source' => 'test', 'created_at' => now(),
        ]);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/analysis/strategies/'.$strategy->id.'/performance?to=2026-01-01')
            ->assertOk()->assertJsonPath('data.xirr_percent', 10)
            ->assertJsonPath('data.twr_percent', null)->assertJsonPath('data.cash_account_invented', false);
    }

    public function test_strategy_from_another_portfolio_is_not_visible(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $other = $user->portfolios()->create(['name' => 'Other']);
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $other->id, 'name' => 'Other Strategy', 'slug' => 'other-strategy',
            'status' => 'active', 'allocation_pct' => 100,
        ]);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/analysis/strategies/'.$strategy->id.'/performance?to=2026-01-01')
            ->assertNotFound();
    }
}
