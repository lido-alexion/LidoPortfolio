<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockPriceHistoryRangeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_range_returns_full_available_history_not_since_buy(): void
    {
        $user = User::query()->create([
            'name' => 'Range API User',
            'email' => 'range-api-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'RNG'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Range API Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        foreach ([
            '2023-01-03', '2024-01-03', '2025-01-03', '2026-01-03',
        ] as $date) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'open_price' => 100,
                'high_price' => 101,
                'low_price' => 99,
                'close_price' => 100,
                'volume' => 100,
                'provider_source' => 'test',
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-03',
        ]);

        $all = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson("/api/stocks/{$stock->id}/prices?range=all");

        $all->assertOk();
        $all->assertJsonPath('range', 'all');
        $all->assertJsonPath('from_date', '2023-01-03');
        $all->assertJsonPath('all_price_count', 4);
        $all->assertJsonPath('since_buy_price_count', 1);

        $sinceBuy = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson("/api/stocks/{$stock->id}/prices?range=since_buy");

        $sinceBuy->assertOk();
        $sinceBuy->assertJsonPath('range', 'since_buy');
        $sinceBuy->assertJsonPath('from_date', '2026-01-03');
        $sinceBuy->assertJsonPath('price_count', 1);
    }
}

