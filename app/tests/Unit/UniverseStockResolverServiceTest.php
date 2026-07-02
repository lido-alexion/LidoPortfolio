<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Models\Stock;
use App\Services\EquityUniverseService;
use App\Services\Nifty500ConstituentService;
use App\Services\UniverseStockResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UniverseStockResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_all_equities_scope_includes_nse_and_bse_only(): void
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
            'symbol' => 'INACTIVE',
            'exchange' => 'NSE',
            'name' => 'Inactive',
            'is_active' => false,
            'is_benchmark' => false,
        ]);
        Stock::query()->create([
            'symbol' => 'NIFTY50',
            'exchange' => 'NSE',
            'name' => 'Index',
            'is_active' => true,
            'is_benchmark' => true,
        ]);
        Stock::query()->create([
            'symbol' => 'BSEONLY',
            'exchange' => 'BSE',
            'name' => 'BSE Only',
            'isin' => 'INE000B01001',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'BSE',
            'name' => 'Infosys BSE dup',
            'isin' => 'INE009A01021',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $service = app(UniverseStockResolverService::class);
        $symbols = $service->stockQuery(UniverseStockResolverService::SCOPE_ALL_EQUITIES)->pluck('symbol')->all();

        sort($symbols);
        $this->assertSame(['BSEONLY', 'INFY'], $symbols);
    }

    public function test_deprecated_all_nse_scope_alias_includes_bse_only(): void
    {
        Stock::query()->create([
            'symbol' => 'ONLYBSE',
            'exchange' => 'BSE',
            'name' => 'Only BSE',
            'isin' => 'INE111B01011',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $service = app(UniverseStockResolverService::class);
        $symbols = $service->stockQuery('all_nse')->pluck('symbol')->all();

        $this->assertSame(['ONLYBSE'], $symbols);
    }

    public function test_nifty500_scope_filters_to_cached_constituents(): void
    {
        Stock::query()->create([
            'symbol' => 'RELIANCE',
            'exchange' => 'NSE',
            'name' => 'Reliance',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Stock::query()->create([
            'symbol' => 'SMALLCO',
            'exchange' => 'NSE',
            'name' => 'Small Co',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Setting::setValue(Nifty500ConstituentService::CACHE_KEY, json_encode(['RELIANCE']));
        Setting::setValue(Nifty500ConstituentService::CACHE_AT_KEY, now()->toIso8601String());

        $service = app(UniverseStockResolverService::class);
        $symbols = $service->stockQuery(UniverseStockResolverService::SCOPE_NIFTY500)->pluck('symbol')->all();

        $this->assertSame(['RELIANCE'], $symbols);
    }
}
