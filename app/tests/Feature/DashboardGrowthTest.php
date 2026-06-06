<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardGrowthTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_lazy_rebuilds_portfolio_growth_when_snapshots_missing(): void
    {
        $user = User::query()->create([
            'name' => 'Dashboard Growth',
            'email' => 'dash-growth-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'DG'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Dashboard Growth Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'brokerage' => 0,
            'transaction_date' => '2026-02-01',
        ]);

        foreach (['2026-02-01', '2026-02-02', '2026-02-03'] as $date) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'open_price' => 100,
                'high_price' => 105,
                'low_price' => 95,
                'close_price' => 102,
                'volume' => 1000,
                'data_source' => 'test',
                'provider_source' => 'test',
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertOk();
        $growth = $response->json('portfolio_growth');
        $this->assertIsArray($growth);
        $this->assertNotEmpty($growth);
        $this->assertArrayHasKey('snapshot_date', $growth[0]);
        $this->assertGreaterThan(0, (float) $growth[array_key_last($growth)]['portfolio_value']);
    }
}
