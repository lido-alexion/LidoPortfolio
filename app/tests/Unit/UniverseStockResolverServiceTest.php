<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Models\Stock;
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

    public function test_all_nse_scope_includes_active_nse_equities_only(): void
    {
        Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'NSE',
            'name' => 'Infosys',
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
            'symbol' => 'TCS',
            'exchange' => 'BSE',
            'name' => 'TCS BSE',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $service = app(UniverseStockResolverService::class);
        $symbols = $service->stockQuery(UniverseStockResolverService::SCOPE_ALL_NSE)->pluck('symbol')->all();

        $this->assertSame(['INFY'], $symbols);
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
