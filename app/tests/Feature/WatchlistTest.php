<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\WatchlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WatchlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_manage_watchlists_and_items(): void
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

        $lists = $this->getJson('/api/watchlists');
        $lists->assertOk();
        $lists->assertJsonPath('count', 1);
        $watchlistId = $lists->json('data.0.id');

        $createSecond = $this->postJson('/api/watchlists', ['name' => 'Breakouts']);
        $createSecond->assertCreated();
        $secondId = $createSecond->json('data.id');

        $create = $this->postJson("/api/watchlists/{$watchlistId}/items", [
            'stock_id' => $stock->id,
            'note' => 'Track for breakout',
        ]);

        $create->assertCreated();
        $create->assertJsonPath('data.note', 'Track for breakout');
        $create->assertJsonPath('data.stock.symbol', $stock->symbol);

        $this->assertDatabaseHas('portfolio_watchlist_items', [
            'profile_id' => $profile->id,
            'watchlist_id' => $watchlistId,
            'stock_id' => $stock->id,
            'note' => 'Track for breakout',
        ]);

        $list = $this->getJson("/api/watchlists/{$watchlistId}/items");
        $list->assertOk();
        $list->assertJsonCount(1, 'data');

        $itemId = $list->json('data.0.id');

        $this->putJson("/api/watchlist-items/{$itemId}", ['note' => 'Updated thesis'])
            ->assertOk()
            ->assertJsonPath('data.note', 'Updated thesis');

        $membership = $this->getJson('/api/watchlist/membership?stock_id='.$stock->id);
        $membership->assertOk();
        $membership->assertJsonPath('watchlist_ids', [$watchlistId]);

        $this->postJson("/api/watchlists/{$secondId}/items", ['stock_id' => $stock->id])
            ->assertCreated();

        $this->deleteJson("/api/watchlist-items/{$itemId}")
            ->assertOk();

        $this->assertDatabaseMissing('portfolio_watchlist_items', ['id' => $itemId]);
        $this->assertDatabaseHas('portfolio_watchlist_items', [
            'watchlist_id' => $secondId,
            'stock_id' => $stock->id,
        ]);

        $this->putJson("/api/watchlists/{$secondId}", ['name' => 'Momentum'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Momentum');

        $this->deleteJson("/api/watchlists/{$secondId}")
            ->assertOk();

        $this->assertDatabaseMissing('portfolio_watchlists', ['id' => $secondId]);
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

        $watchlist = app(WatchlistService::class)->ensureDefaultWatchlist($profile);

        WatchlistItem::query()->create([
            'profile_id' => $profile->id,
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
            'note' => null,
        ]);

        $this->actingAs($user)
            ->postJson("/api/watchlists/{$watchlist->id}/items", ['stock_id' => $stock->id])
            ->assertUnprocessable();
    }

    public function test_cannot_delete_only_watchlist(): void
    {
        $user = User::query()->create([
            'name' => 'Watchlist Solo',
            'email' => 'watch-solo-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $watchlist = app(WatchlistService::class)->ensureDefaultWatchlist($profile);

        $this->actingAs($user)
            ->deleteJson("/api/watchlists/{$watchlist->id}")
            ->assertUnprocessable();
    }

    public function test_watchlist_items_support_search_and_sort(): void
    {
        $user = User::query()->create([
            'name' => 'Watchlist Sort',
            'email' => 'watch-sort-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $watchlist = app(WatchlistService::class)->ensureDefaultWatchlist($profile);

        $alpha = Stock::query()->create([
            'symbol' => 'AAA',
            'exchange' => 'NSE',
            'name' => 'Alpha Corp',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $beta = Stock::query()->create([
            'symbol' => 'ZZZ',
            'exchange' => 'NSE',
            'name' => 'Zeta Corp',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        WatchlistItem::query()->create([
            'profile_id' => $profile->id,
            'watchlist_id' => $watchlist->id,
            'stock_id' => $beta->id,
            'note' => 'beta note',
        ]);
        WatchlistItem::query()->create([
            'profile_id' => $profile->id,
            'watchlist_id' => $watchlist->id,
            'stock_id' => $alpha->id,
            'note' => null,
        ]);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $alpha->id,
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'total_fees' => 0,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $alpha->id,
            'price_date' => now()->subDay()->toDateString(),
            'open_price' => 118,
            'high_price' => 122,
            'low_price' => 117,
            'close_price' => 120,
            'volume' => 1000,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);

        $this->actingAs($user);

        $sorted = $this->getJson("/api/watchlists/{$watchlist->id}/items?sort=-symbol");
        $sorted->assertOk();
        $sorted->assertJsonPath('data.0.stock.symbol', 'ZZZ');

        $search = $this->getJson("/api/watchlists/{$watchlist->id}/items?search=beta");
        $search->assertOk();
        $search->assertJsonCount(1, 'data');
        $search->assertJsonPath('data.0.stock.symbol', 'ZZZ');

        $holding = $this->getJson("/api/watchlists/{$watchlist->id}/items?search=alpha");
        $holding->assertOk();
        $holding->assertJsonPath('data.0.holding.quantity', 10);
        $holding->assertJsonPath('data.0.holding.avg_buy_price', 100);
        $holding->assertJsonPath('data.0.holding.invested_amount', 1000);
        $holding->assertJsonPath('data.0.holding.unrealized_profit', 200);
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
