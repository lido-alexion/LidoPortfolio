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

        // Anchor rows must fall on trading sessions: getCloseOnOrBeforeDate skips
        // weekends/holidays, so raw "X months ago minus 2 days" dates made this
        // test fail whenever they landed on a Saturday/Sunday.
        $sixMonthsAgo = \App\Support\TradingCalendar::normalizeToSessionDate(now()->subMonths(6)->subDays(2))->toDateString();
        $threeMonthsAgo = \App\Support\TradingCalendar::normalizeToSessionDate(now()->subMonths(3)->subDays(2))->toDateString();
        $twelveMonthsAgo = \App\Support\TradingCalendar::normalizeToSessionDate(now()->subMonths(12)->subDays(2))->toDateString();
        $latestSession = \App\Support\TradingCalendar::normalizeToSessionDate(now()->subDay())->toDateString();

        foreach ([$stock, $benchmark] as $s) {
            foreach ([$twelveMonthsAgo, $sixMonthsAgo, $threeMonthsAgo] as $date) {
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
                'price_date' => $latestSession,
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
                'growth_percent' => ['1m', '3m', '6m', '12m'],
                'benchmark_growth_percent' => ['1m', '3m', '6m', '12m'],
                'relative_strength' => ['1m', '3m', '6m', '12m'],
                'period_closes' => [
                    '1m',
                    '3m',
                    '6m',
                    '12m',
                ],
                'chart',
                'normalized_gain_chart',
            ],
        ]);
        $response->assertJsonPath('data.benchmark.latest_close', 110);
        $response->assertJsonPath('data.relative_strength.3m', 10);
        $response->assertJsonCount(4, 'data.chart');
        $this->assertNotEmpty($response->json('data.normalized_gain_chart'));
        Http::assertNothingSent();
    }

    public function test_explore_uses_selected_index_benchmark(): void
    {
        config(['portfolio.indexes.enabled' => true]);

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

        // NIFTYBANK is a configured index; ensureIndexStock will create the benchmark row.
        $bankIndex = Stock::query()->create([
            'symbol' => 'NIFTYBANK',
            'exchange' => 'NSE',
            'name' => 'Nifty Bank',
            'is_active' => true,
            'is_benchmark' => true,
            'yahoo_symbol' => '^NSEBANK',
        ]);

        $dates = [
            \App\Support\TradingCalendar::normalizeToSessionDate(now()->subMonths(12)->subDays(2))->toDateString(),
            \App\Support\TradingCalendar::normalizeToSessionDate(now()->subMonths(6)->subDays(2))->toDateString(),
            \App\Support\TradingCalendar::normalizeToSessionDate(now()->subMonths(3)->subDays(2))->toDateString(),
        ];
        $latestSession = \App\Support\TradingCalendar::normalizeToSessionDate(now()->subDay())->toDateString();

        foreach ([$stock, $bankIndex] as $s) {
            foreach ($dates as $date) {
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
                'price_date' => $latestSession,
                'close_price' => $s->symbol === 'CACHED' ? 120 : 105,
                'adjusted_close_price' => $s->symbol === 'CACHED' ? 120 : 105,
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
            'benchmark_symbol' => 'NIFTYBANK',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.benchmark.symbol', 'NIFTYBANK');
        $response->assertJsonPath('data.benchmark.latest_close', 105);
        Http::assertNothingSent();
    }

    public function test_indexes_endpoint_lists_enabled_indexes(): void
    {
        config(['portfolio.indexes.enabled' => true]);

        $user = User::query()->create([
            'name' => 'Explorer User',
            'email' => 'exp-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/indexes')
            ->assertOk();

        $response->assertJsonPath('data.primary_symbol', 'NIFTY50');
        $symbols = collect($response->json('data.indexes'))->pluck('symbol')->all();
        $this->assertContains('NIFTY50', $symbols);
        $this->assertContains('NIFTYBANK', $symbols);
        $this->assertContains('SENSEX', $symbols);
    }
}
