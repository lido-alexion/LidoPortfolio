<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\WatchlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatternScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_pattern_scan_finds_matches_for_watchlist(): void
    {
        $user = User::query()->create([
            'name' => 'Pattern User',
            'email' => 'pattern-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $watchlist = app(WatchlistService::class)->ensureDefaultWatchlist($profile);

        $stock = Stock::query()->create([
            'symbol' => 'P'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Pattern Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        WatchlistItem::query()->create([
            'profile_id' => $profile->id,
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
            'note' => null,
        ]);

        $this->seedDowntrendWithHammer($stock);

        $this->actingAs($user);

        $response = $this->getJson('/api/patterns/scan?scope=watchlist&watchlist_id='.$watchlist->id.'&actionable_only=false');

        $response->assertOk();
        $response->assertJsonPath('scope', 'watchlist');
        $response->assertJsonPath('persisted', true);
        $response->assertJsonCount(1, 'results');
        $response->assertJsonPath('results.0.symbol', $stock->symbol);

        $matches = $response->json('results.0.matches');
        $this->assertNotEmpty($matches);
        $this->assertContains('hammer', array_column($matches, 'id'));

        $this->assertDatabaseHas('portfolio_watchlist_pattern_scans', [
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
        ]);

        $items = $this->getJson('/api/watchlists/'.$watchlist->id.'/items');
        $items->assertOk();
        $items->assertJsonPath('data.0.stock_id', $stock->id);
        $this->assertNotEmpty($items->json('data.0.pattern_matches'));
        $this->assertContains('hammer', array_column($items->json('data.0.pattern_matches'), 'id'));
    }

    public function test_holdings_scan_requires_holding(): void
    {
        $user = User::query()->create([
            'name' => 'Hold Pattern',
            'email' => 'hold-pat-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'H'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Held Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 10,
            'avg_cost' => 100,
        ]);

        $this->seedDowntrendWithHammer($stock);

        $this->actingAs($user);

        $response = $this->getJson('/api/patterns/scan?scope=holdings&actionable_only=true');

        $response->assertOk();
        $response->assertJsonPath('scope', 'holdings');
        $this->assertNotEmpty($response->json('results'));
    }

    public function test_single_stock_scan_computes_fresh_and_persists_for_member(): void
    {
        $user = User::query()->create([
            'name' => 'Single Scan',
            'email' => 'single-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $watchlist = app(WatchlistService::class)->ensureDefaultWatchlist($profile);

        $stock = Stock::query()->create([
            'symbol' => 'S'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Single Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        WatchlistItem::query()->create([
            'profile_id' => $profile->id,
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
            'note' => null,
        ]);

        $this->seedDowntrendWithHammer($stock);

        $this->actingAs($user);

        $response = $this->getJson('/api/stocks/'.$stock->id.'/pattern-scan');

        $response->assertOk();
        $response->assertJsonPath('stock_id', $stock->id);
        $response->assertJsonPath('source', 'fresh');
        $response->assertJsonPath('persisted', true);
        $this->assertContains('hammer', array_column($response->json('matches'), 'id'));

        $this->assertDatabaseHas('portfolio_watchlist_pattern_scans', [
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
        ]);
    }

    public function test_single_stock_scan_reuses_valid_watchlist_cache(): void
    {
        $user = User::query()->create([
            'name' => 'Cache Scan',
            'email' => 'cache-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $watchlist = app(WatchlistService::class)->ensureDefaultWatchlist($profile);

        $stock = Stock::query()->create([
            'symbol' => 'C'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Cached Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        WatchlistItem::query()->create([
            'profile_id' => $profile->id,
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
            'note' => null,
        ]);

        $this->seedDowntrendWithHammer($stock);

        // Sentinel matches prove the cache is served instead of a recompute.
        \App\Models\WatchlistPatternScan::query()->create([
            'profile_id' => $profile->id,
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
            'matches' => [['id' => 'doji', 'name' => 'Doji', 'category' => 'neutral', 'variant' => 'candle', 'bar_date' => now()->subDay()->toDateString()]],
            'price_as_of' => now()->subDay()->toDateString(),
            'expires_at' => now()->addDay(),
            'scanned_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/stocks/'.$stock->id.'/pattern-scan');

        $response->assertOk();
        $response->assertJsonPath('source', 'watchlist_cache');
        $response->assertJsonPath('persisted', true);
        $this->assertSame(['doji'], array_column($response->json('matches'), 'id'));
    }

    public function test_single_stock_scan_recomputes_when_newer_prices_exist(): void
    {
        $user = User::query()->create([
            'name' => 'Stale Scan',
            'email' => 'stale-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $watchlist = app(WatchlistService::class)->ensureDefaultWatchlist($profile);

        $stock = Stock::query()->create([
            'symbol' => 'T'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Stale Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        WatchlistItem::query()->create([
            'profile_id' => $profile->id,
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
            'note' => null,
        ]);

        $this->seedDowntrendWithHammer($stock);

        // Cached scan is not expired, but its price_as_of is older than the
        // latest OHLCV session — must be recomputed.
        \App\Models\WatchlistPatternScan::query()->create([
            'profile_id' => $profile->id,
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
            'matches' => [['id' => 'doji', 'name' => 'Doji', 'category' => 'neutral', 'variant' => 'candle', 'bar_date' => now()->subDays(5)->toDateString()]],
            'price_as_of' => now()->subDays(5)->toDateString(),
            'expires_at' => now()->addDay(),
            'scanned_at' => now()->subDays(4),
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/stocks/'.$stock->id.'/pattern-scan');

        $response->assertOk();
        $response->assertJsonPath('source', 'fresh');
        $this->assertContains('hammer', array_column($response->json('matches'), 'id'));
    }

    public function test_single_stock_scan_for_non_member_is_not_persisted(): void
    {
        $user = User::query()->create([
            'name' => 'Free Scan',
            'email' => 'free-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'F'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Free Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->seedDowntrendWithHammer($stock);

        $this->actingAs($user);

        $response = $this->getJson('/api/stocks/'.$stock->id.'/pattern-scan');

        $response->assertOk();
        $response->assertJsonPath('source', 'fresh');
        $response->assertJsonPath('persisted', false);
        $this->assertContains('hammer', array_column($response->json('matches'), 'id'));

        $this->assertDatabaseMissing('portfolio_watchlist_pattern_scans', [
            'stock_id' => $stock->id,
        ]);
    }

    private function seedDowntrendWithHammer(Stock $stock): void
    {
        $start = 120.0;
        for ($i = 0; $i < 8; $i++) {
            $close = $start - ($i * 2);
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => now()->subDays(9 - $i)->toDateString(),
                'open_price' => $close + 1,
                'high_price' => $close + 2,
                'low_price' => $close - 2,
                'close_price' => $close,
                'volume' => 1000,
                'data_source' => 'test',
            ]);
        }

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->subDay()->toDateString(),
            'open_price' => 102,
            'high_price' => 103,
            'low_price' => 94,
            'close_price' => 103,
            'volume' => 1000,
            'data_source' => 'test',
        ]);
    }
}
