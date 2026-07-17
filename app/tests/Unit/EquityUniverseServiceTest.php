<?php

namespace Tests\Unit;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\EquityUniverseService;
use App\Services\WatchlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortfolioProfiles;
use Tests\TestCase;

class EquityUniverseServiceTest extends TestCase
{
    use CreatesPortfolioProfiles;
    use RefreshDatabase;

    public function test_all_equities_includes_nse_and_bse_only(): void
    {
        Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'NSE',
            'name' => 'Infosys',
            'isin' => 'INE009A01021',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Stock::query()->create([
            'symbol' => 'BSEONLY',
            'exchange' => 'BSE',
            'name' => 'BSE Only Co',
            'isin' => 'INE000B01001',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'BSE',
            'name' => 'Infosys BSE duplicate',
            'isin' => 'INE009A01021',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $service = app(EquityUniverseService::class);
        $symbols = $service->universeStockQuery(EquityUniverseService::SCOPE_ALL_EQUITIES)
            ->pluck('symbol')
            ->all();

        sort($symbols);
        $this->assertSame(['BSEONLY', 'INFY'], $symbols);
    }

    public function test_exchange_label_for_dual_listed_nse(): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'NSE',
            'name' => 'Infosys',
            'is_dual_listed' => true,
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $service = app(EquityUniverseService::class);
        $this->assertSame('NSE+', $service->exchangeLabel($stock));
    }

    public function test_deprecated_all_nse_scope_maps_to_all_equities(): void
    {
        $service = app(EquityUniverseService::class);
        $this->assertSame(
            EquityUniverseService::SCOPE_ALL_EQUITIES,
            $service->normalizeScope('all_nse'),
        );
    }

    public function test_resolve_canonical_stock_maps_dual_listed_bse_request_to_nse_row(): void
    {
        Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'NSE',
            'name' => 'Infosys',
            'is_dual_listed' => true,
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $service = app(EquityUniverseService::class);
        $stock = $service->resolveCanonicalStock('INFY', 'BSE');

        $this->assertNotNull($stock);
        $this->assertSame('NSE', $stock->exchange);
    }

    public function test_universe_query_orders_holdings_then_watchlists_then_others(): void
    {
        $user = User::query()->create([
            'name' => 'Universe Priority',
            'email' => 'univ-prio-'.uniqid().'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        Stock::query()->create([
            'symbol' => 'OTHER1',
            'exchange' => 'NSE',
            'name' => 'Other Early',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $holding = Stock::query()->create([
            'symbol' => 'HOLD1',
            'exchange' => 'NSE',
            'name' => 'Holding',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $watch = Stock::query()->create([
            'symbol' => 'WATCH1',
            'exchange' => 'NSE',
            'name' => 'Watch',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $otherLate = Stock::query()->create([
            'symbol' => 'OTHER2',
            'exchange' => 'NSE',
            'name' => 'Other Late',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $holding->id,
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'total_fees' => 0,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        $watchlist = app(WatchlistService::class)->ensureDefaultWatchlist($profile);
        WatchlistItem::query()->create([
            'profile_id' => $profile->id,
            'watchlist_id' => $watchlist->id,
            'stock_id' => $watch->id,
            'note' => null,
        ]);
        // Holding also on watchlist should still count as holding priority only once.
        WatchlistItem::query()->create([
            'profile_id' => $profile->id,
            'watchlist_id' => $watchlist->id,
            'stock_id' => $holding->id,
            'note' => null,
        ]);

        $service = app(EquityUniverseService::class);
        $symbols = $service->universeStockQuery(EquityUniverseService::SCOPE_ALL_EQUITIES)
            ->pluck('symbol')
            ->all();

        $this->assertSame(
            ['HOLD1', 'WATCH1', 'OTHER1', 'OTHER2'],
            $symbols,
        );

        $this->assertSame(
            EquityUniverseService::SYNC_PRIORITY_HOLDING,
            $service->syncPriorityForStockId($holding->id),
        );
        $this->assertSame(
            EquityUniverseService::SYNC_PRIORITY_WATCHLIST,
            $service->syncPriorityForStockId($watch->id),
        );
        $this->assertSame(
            EquityUniverseService::SYNC_PRIORITY_OTHER,
            $service->syncPriorityForStockId($otherLate->id),
        );

        $this->assertSame(1, $service->countThroughCursor(null, $holding->id));
        $this->assertSame(2, $service->countThroughCursor(null, $watch->id));
        $this->assertFalse($service->hasStocksAfterCursor(null, $otherLate->id));
        $this->assertTrue($service->hasStocksAfterCursor(null, $holding->id));

        $afterHolding = $service->applyAfterCursor(
            $service->universeStockQuery(),
            $holding->id,
        )->pluck('symbol')->all();
        $this->assertSame(['WATCH1', 'OTHER1', 'OTHER2'], $afterHolding);
    }
}
