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
