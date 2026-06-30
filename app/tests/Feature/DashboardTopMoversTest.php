<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTopMoversTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_top_movers_rank_all_time_and_latest_day_separately(): void
    {
        $user = User::query()->create([
            'name' => 'Dashboard Top Movers',
            'email' => 'dash-movers-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $lifetimeWinner = Stock::query()->create([
            'symbol' => 'LIFE'.strtoupper(Str::random(2)),
            'exchange' => 'NSE',
            'name' => 'Lifetime Winner',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $dailyWinner = Stock::query()->create([
            'symbol' => 'DAY'.strtoupper(Str::random(2)),
            'exchange' => 'NSE',
            'name' => 'Daily Winner',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $lifetimeWinner->id,
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'total_fees' => 0,
            'realized_profit' => 0,
        ]);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $dailyWinner->id,
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'total_fees' => 0,
            'realized_profit' => 0,
        ]);

        foreach ([
            [$lifetimeWinner->id, '2026-06-19', 118, '2026-06-20', 120],
            [$dailyWinner->id, '2026-06-19', 100, '2026-06-20', 105],
        ] as [$stockId, $prevDate, $prevClose, $latestDate, $latestClose]) {
            StockPrice::query()->create([
                'stock_id' => $stockId,
                'price_date' => $prevDate,
                'open_price' => $prevClose,
                'high_price' => $prevClose,
                'low_price' => $prevClose,
                'close_price' => $prevClose,
                'volume' => 1000,
                'data_source' => 'test',
                'provider_source' => 'test',
            ]);
            StockPrice::query()->create([
                'stock_id' => $stockId,
                'price_date' => $latestDate,
                'open_price' => $latestClose,
                'high_price' => $latestClose,
                'low_price' => $latestClose,
                'close_price' => $latestClose,
                'volume' => 1000,
                'data_source' => 'test',
                'provider_source' => 'test',
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertOk();
        $topMovers = $response->json('top_movers');
        $this->assertSame($lifetimeWinner->symbol, $topMovers['all_time']['gainer']['symbol']);
        $this->assertEqualsWithDelta(20.0, $topMovers['all_time']['gainer']['change_percent'], 0.001);
        $this->assertSame($dailyWinner->symbol, $topMovers['latest_day']['gainer']['symbol']);
        $this->assertEqualsWithDelta(5.0, $topMovers['latest_day']['gainer']['change_percent'], 0.001);
        $this->assertSame($lifetimeWinner->symbol, $topMovers['latest_day']['loser']['symbol']);
        $this->assertEqualsWithDelta(1.69, $topMovers['latest_day']['loser']['change_percent'], 0.001);
    }
}
