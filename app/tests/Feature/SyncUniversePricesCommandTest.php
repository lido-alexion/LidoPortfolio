<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Services\BenchmarkPriceSyncService;
use App\Services\PriceFetchService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Concerns\BhavcopyFixtures;
use Tests\TestCase;

class SyncUniversePricesCommandTest extends TestCase
{
    use BhavcopyFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TradingCalendar::clearHolidayCache();
        Mockery::close();
        parent::tearDown();
    }

    public function test_daily_command_collects_both_exchange_files_for_the_full_scope_despite_a_batch_limit(): void
    {
        Carbon::setTestNow('2026-10-26 20:00:00');
        TradingCalendar::clearHolidayCache();
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.daily_lookback_days' => 1,
            'portfolio.universe_price_sync.batch_size' => 1,
        ]);
        $nse = Stock::query()->create([
            'symbol' => 'INFY', 'exchange' => 'NSE', 'name' => 'Infosys',
            'is_active' => true, 'is_benchmark' => false,
        ]);
        $bse = Stock::query()->create([
            'symbol' => 'BSEONLY', 'exchange' => 'BSE', 'name' => 'BSE only',
            'bse_scrip_code' => '500001', 'is_active' => true, 'is_benchmark' => false,
        ]);
        $prices = Mockery::mock(PriceFetchService::class)->makePartial();
        $prices->shouldNotReceive('syncStock');
        $prices->shouldNotReceive('fetchHistoricalWithFallback');
        $this->app->instance(PriceFetchService::class, $prices);
        $benchmark = Mockery::mock(BenchmarkPriceSyncService::class);
        $benchmark->shouldReceive('syncIfNeeded')->once()->andReturn(['skipped' => true, 'success' => true]);
        $this->app->instance(BenchmarkPriceSyncService::class, $benchmark);
        Http::preventStrayRequests();
        Http::fake([
            'nsearchives.nseindia.com/*' => Http::response($this->bhavcopyZip($this->bhavcopyCsv(rows: [['TckrSymb' => 'INFY']]))),
            'www.bseindia.com/*' => Http::response($this->bhavcopyCsv('BSE', rows: [
                ['TckrSymb' => 'BSEONLY', 'FinInstrmId' => '500001', 'ISIN' => 'INE002A01010'],
            ])),
        ]);

        $this->artisan('portfolio:sync-universe-prices', ['--mode' => 'daily', '--batch' => 1])
            ->expectsOutputToContain('Daily source: NSE/BSE bhavcopy; full scope')
            ->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertDatabaseHas('portfolio_stock_prices', ['stock_id' => $nse->id, 'provider_source' => 'nse_bhavcopy']);
        $this->assertDatabaseHas('portfolio_stock_prices', ['stock_id' => $bse->id, 'provider_source' => 'bse_bhavcopy']);
    }
}
