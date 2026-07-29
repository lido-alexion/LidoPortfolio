<?php

namespace Tests\Unit;

use App\Services\PriceProviders\NsePriceProvider;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class NsePriceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fetch_equity_historical_uses_charting_api(): void
    {
        Http::fake([
            'charting.nseindia.com/*' => Http::sequence()
                ->push('', 200)
                ->push([
                    'status' => true,
                    'data' => [[
                        'symbol' => 'RELIANCE-EQ',
                        'scripcode' => '2885',
                        'description' => 'RELIANCE INDUSTRIES LTD',
                    ]],
                ], 200)
                ->push([
                    'status' => true,
                    'data' => [[
                        'time' => Carbon::parse('2025-07-10')->startOfDay()->timestamp * 1000,
                        'open' => 1500,
                        'high' => 1510,
                        'low' => 1490,
                        'close' => 1505,
                        'volume' => 1000,
                    ]],
                ], 200),
        ]);

        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('nse_retry_count')->andReturn('1');

        $provider = new NsePriceProvider($settings);
        $rows = $provider->fetchHistorical(
            'RELIANCE',
            Carbon::parse('2025-07-10'),
            Carbon::parse('2025-07-10'),
        );

        $this->assertCount(1, $rows);
        $this->assertSame('2025-07-10', $rows[0]['price_date']);
        $this->assertSame(1505.0, $rows[0]['close_price']);
    }

    public function test_charting_rows_outside_requested_range_fall_through_to_next_api(): void
    {
        Http::fake([
            'charting.nseindia.com/*' => Http::sequence()
                ->push('', 200)
                ->push([
                    'status' => true,
                    'data' => [[
                        'symbol' => 'THIN-EQ',
                        'scripcode' => '9999',
                        'description' => 'THIN STOCK',
                    ]],
                ], 200)
                ->push([
                    'status' => true,
                    'data' => [[
                        'time' => Carbon::parse('2026-04-20')->startOfDay()->timestamp * 1000,
                        'open' => 100,
                        'high' => 101,
                        'low' => 99,
                        'close' => 100,
                        'volume' => 10,
                    ]],
                ], 200),
            'www.nseindia.com/*' => Http::response([
                [
                    'CH_TIMESTAMP' => '10-Jul-2025',
                    'CH_OPENING_PRICE' => 90,
                    'CH_TRADE_HIGH_PRICE' => 95,
                    'CH_TRADE_LOW_PRICE' => 88,
                    'CH_CLOSING_PRICE' => 92,
                    'CH_TOT_TRADED_QTY' => 500,
                ],
            ], 200),
        ]);

        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('nse_retry_count')->andReturn('1');

        $provider = new NsePriceProvider($settings);
        $rows = $provider->fetchHistorical(
            'THIN',
            Carbon::parse('2025-07-09'),
            Carbon::parse('2026-04-19'),
        );

        $this->assertCount(1, $rows);
        $this->assertSame('2025-07-10', $rows[0]['price_date']);
    }

    public function test_fetch_equity_historical_resolves_be_series_trade_symbol(): void
    {
        Http::fake([
            'charting.nseindia.com/*' => Http::sequence()
                ->push('', 200)
                ->push([
                    'status' => true,
                    'data' => [[
                        'symbol' => 'TOKYOPLAST-BE',
                        'scripcode' => '9710',
                        'description' => 'TOKYO PLAST INTL LTD.',
                    ]],
                ], 200)
                ->push([
                    'status' => true,
                    'data' => [[
                        'time' => Carbon::parse('2025-12-24')->startOfDay()->timestamp * 1000,
                        'open' => 113,
                        'high' => 114,
                        'low' => 112,
                        'close' => 113.14,
                        'volume' => 100,
                    ]],
                ], 200),
        ]);

        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('nse_retry_count')->andReturn('1');

        $provider = new NsePriceProvider($settings);
        $rows = $provider->fetchHistorical(
            'TOKYOPLAST',
            Carbon::parse('2025-12-24'),
            Carbon::parse('2025-12-24'),
            'TOKYOPLAST-BE',
        );

        $this->assertCount(1, $rows);
        $this->assertSame('2025-12-24', $rows[0]['price_date']);
    }

    public function test_india_vix_charting_rows_scaled_by_100_are_normalized(): void
    {
        Http::fake([
            'charting.nseindia.com/*' => Http::sequence()
                ->push('', 200)
                ->push([
                    'status' => true,
                    'data' => [[
                        'symbol' => 'INDIA VIX',
                        'scripcode' => '26011',
                        'description' => 'INDIA VIX',
                    ]],
                ], 200)
                ->push([
                    'status' => true,
                    'data' => [[
                        'time' => Carbon::parse('2026-07-28')->startOfDay()->timestamp * 1000,
                        'open' => 1266.0,
                        'high' => 1282.0,
                        'low' => 1172.25,
                        'close' => 1264.5,
                        'volume' => 0,
                    ]],
                ], 200),
        ]);

        $catalog = Mockery::mock(\App\Services\IndexCatalogService::class);
        $catalog->shouldReceive('nseChartingNameForSymbol')
            ->with('INDIAVIX')
            ->andReturn('INDIA VIX');
        $this->app->instance(\App\Services\IndexCatalogService::class, $catalog);

        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('nse_retry_count')->andReturn('1');

        $provider = new NsePriceProvider($settings);
        $rows = $provider->fetchHistorical(
            'INDIAVIX',
            Carbon::parse('2026-07-28'),
            Carbon::parse('2026-07-28'),
        );

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(12.645, $rows[0]['close_price'], 0.0001);
        $this->assertEqualsWithDelta(12.66, $rows[0]['open_price'], 0.0001);
    }
}
