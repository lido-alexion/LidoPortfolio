<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WatchlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_add_list_and_remove_watchlist_items(): void
    {
        $user = User::query()->create([
            'name' => 'Watchlist User',
            'email' => 'watch-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'W'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Watchlist Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->actingAs($user);

        $create = $this->postJson('/api/watchlist', [
            'stock_id' => $stock->id,
            'note' => 'Track for breakout',
        ]);

        $create->assertCreated();
        $create->assertJsonPath('data.note', 'Track for breakout');
        $create->assertJsonPath('data.stock.symbol', $stock->symbol);

        $this->assertDatabaseHas('portfolio_watchlist_items', [
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'note' => 'Track for breakout',
        ]);

        $list = $this->getJson('/api/watchlist');
        $list->assertOk();
        $list->assertJsonCount(1, 'data');

        $itemId = $list->json('data.0.id');

        $this->putJson("/api/watchlist/{$itemId}", ['note' => 'Updated thesis'])
            ->assertOk()
            ->assertJsonPath('data.note', 'Updated thesis');

        $this->deleteJson("/api/watchlist/{$itemId}")
            ->assertOk();

        $this->assertDatabaseMissing('portfolio_watchlist_items', ['id' => $itemId]);
    }

    public function test_duplicate_watchlist_add_is_rejected(): void
    {
        $user = User::query()->create([
            'name' => 'Watchlist Dup',
            'email' => 'watch-dup-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'DUP'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Dup Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        WatchlistItem::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'note' => null,
        ]);

        $this->actingAs($user)
            ->postJson('/api/watchlist', ['stock_id' => $stock->id])
            ->assertUnprocessable();
    }

    public function test_market_prices_endpoint_returns_cached_ohlcv_without_holding(): void
    {
        $user = User::query()->create([
            'name' => 'Market Prices',
            'email' => 'market-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'MKT'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Market Price Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        foreach (['2026-06-01', '2026-06-02'] as $index => $date) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'open_price' => 100 + $index,
                'high_price' => 105 + $index,
                'low_price' => 99 + $index,
                'close_price' => 102 + $index,
                'volume' => 1000 + $index,
                'data_source' => 'test',
                'provider_source' => 'test',
            ]);
        }

        $response = $this->actingAs($user)->getJson("/api/stocks/{$stock->id}/market-prices");

        $response->assertOk();
        $response->assertJsonPath('price_count', 2);
        $response->assertJsonPath('has_price_history', true);
        $this->assertEqualsWithDelta(103.0, $response->json('latest_close'), 0.001);
        $response->assertJsonCount(2, 'data');
    }
}
