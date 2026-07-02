<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExplorerAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_explore_endpoint_returns_growth_and_rs_from_cache(): void
    {
        $user = User::query()->create([
            'name' => 'Explorer User',
            'email' => 'exp-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'CACHED',
            'exchange' => 'NSE',
            'name' => 'Cached Stock',
            'is_active' => true,
            'is_benchmark' => false,
            'yahoo_symbol' => 'CACHED.NS',
            'last_verified_at' => now(),
        ]);

        $benchmark = Stock::query()->create([
            'symbol' => 'NIFTY50',
            'exchange' => 'NSE',
            'name' => 'NIFTY 50',
            'is_active' => true,
            'is_benchmark' => true,
            'yahoo_symbol' => '^NSEI',
        ]);

        $sixMonthsAgo = now()->subMonths(6)->subDays(2)->toDateString();
        $threeMonthsAgo = now()->subMonths(3)->subDays(2)->toDateString();

        foreach ([$stock, $benchmark] as $s) {
            foreach ([$sixMonthsAgo, $threeMonthsAgo] as $date) {
                StockPrice::query()->create([
                    'stock_id' => $s->id,
                    'price_date' => $date,
                    'close_price' => 100,
                    'adjusted_close_price' => 100,
                    'provider_source' => 'test',
                    'data_source' => 'test',
                    'created_at' => now(),
                ]);
            }
            StockPrice::query()->create([
                'stock_id' => $s->id,
                'price_date' => now()->subDay()->toDateString(),
                'close_price' => $s->symbol === 'CACHED' ? 120 : 110,
                'adjusted_close_price' => $s->symbol === 'CACHED' ? 120 : 110,
                'provider_source' => 'test',
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        Http::fake();

        $this->actingAs($user);

        $response = $this->postJson('/api/analytics/explore', [
            'symbol' => 'CACHED',
            'exchange' => 'NSE',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.valid', true);
        $response->assertJsonStructure([
            'data' => [
                'growth_percent' => ['1m', '3m', '6m'],
                'benchmark_growth_percent' => ['1m', '3m', '6m'],
                'relative_strength' => ['1m', '3m', '6m'],
                'period_closes' => [
                    '1m',
                    '3m',
                    '6m',
                ],
                'chart',
            ],
        ]);
        $response->assertJsonPath('data.benchmark.latest_close', 110);
        $response->assertJsonPath('data.relative_strength.3m', 10);
        $response->assertJsonCount(3, 'data.chart');
        Http::assertNothingSent();
    }
}
