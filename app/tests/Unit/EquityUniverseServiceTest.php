<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Services\EquityUniverseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquityUniverseServiceTest extends TestCase
{
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
}
