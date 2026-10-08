<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\Setting;
use App\Models\V7\FundamentalSetting;
use App\Services\Fundamentals\FundamentalDataProvider;
use App\Services\Fundamentals\FundamentalHistoricalIngestService;
use App\Services\Fundamentals\Historical\BseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\NseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\YahooFundamentalHistoricalSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class NseOfficialFundamentalHistoricalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_yahoo_rows_are_primary_and_exchange_is_not_queried_when_yahoo_has_data(): void
    {
        config([
            'fundamentals_bootstrap.nse_official_enabled' => true,
            'fundamentals_bootstrap.nse_official_feed_url' => 'https://feeds.example/nse-fundamentals',
        ]);

        Http::fake([
            'feeds.example/*' => Http::response([
                'facts' => [[
                    'statement_type' => 'income_statement',
                    'cadence' => 'quarterly',
                    'statement_basis' => 'consolidated',
                    'fact_key' => 'revenue',
                    'period_end' => '2024-03-31',
                    'value' => 500,
                    'availability_date' => '2024-05-15',
                ]],
            ], 200),
        ]);

        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn([
            [
                'statement_type' => 'income_statement',
                'cadence' => 'quarterly',
                'statement_basis' => 'consolidated',
                'fact_key' => 'revenue',
                'period_end' => '2024-03-31',
                'value' => 100,
            ],
        ]);

        $service = new FundamentalHistoricalIngestService(
            new NseOfficialFundamentalHistoricalSource,
            new BseOfficialFundamentalHistoricalSource,
            new YahooFundamentalHistoricalSource($provider),
        );

        $stock = Stock::query()->create(['symbol' => 'RELIANCE', 'exchange' => 'NSE', 'name' => 'Reliance']);
        $rows = $service->fetch($stock, 'quarterly');

        $this->assertCount(1, $rows);
        $this->assertSame('yahoo', $rows[0]['provider']);
        $this->assertSame(100.0, (float) $rows[0]['value']);
        Http::assertNothingSent();
    }
    public function test_automatic_nse_fallback_is_skipped_outside_fresh_nifty500_cache(): void
    {
        FundamentalSetting::query()->create(['nse_official_fallback_enabled' => true]);
        Setting::setValue('nifty500_constituents_json', json_encode(['RELIANCE']));
        Setting::setValue('nifty500_constituents_cached_at', now()->toIso8601String());
        config(['fundamentals_bootstrap.nse_official_feed_url' => 'https://feeds.example/nse-fundamentals']);
        Http::fake();

        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn([]);
        $service = new FundamentalHistoricalIngestService(
            new NseOfficialFundamentalHistoricalSource,
            new BseOfficialFundamentalHistoricalSource,
            new YahooFundamentalHistoricalSource($provider),
        );

        $stock = Stock::query()->create(['symbol' => 'OUTSIDE500', 'exchange' => 'NSE', 'name' => 'Outside 500']);
        $this->assertSame([], $service->fetch($stock, 'quarterly'));
        Http::assertNothingSent();
    }

    public function test_manual_request_can_check_one_nse_stock_outside_nifty500(): void
    {
        FundamentalSetting::query()->create(['nse_official_fallback_enabled' => true]);
        Setting::setValue('nifty500_constituents_json', json_encode(['RELIANCE']));
        Setting::setValue('nifty500_constituents_cached_at', now()->toIso8601String());
        config(['fundamentals_bootstrap.nse_official_feed_url' => 'https://feeds.example/nse-fundamentals']);
        Http::fake(['feeds.example/*' => Http::response(['facts' => [[
            'statement_type' => 'income_statement', 'cadence' => 'quarterly',
            'statement_basis' => 'consolidated', 'fact_key' => 'revenue',
            'period_end' => '2024-03-31', 'value' => 500,
        ]]], 200)]);

        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn([]);
        $service = new FundamentalHistoricalIngestService(
            new NseOfficialFundamentalHistoricalSource,
            new BseOfficialFundamentalHistoricalSource,
            new YahooFundamentalHistoricalSource($provider),
        );
        $stock = Stock::query()->create(['symbol' => 'OUTSIDE500', 'exchange' => 'NSE', 'name' => 'Outside 500']);

        $rows = $service->fetch($stock, 'quarterly', manualStockRequest: true);
        $this->assertCount(1, $rows);
        $this->assertSame('nse_official', $rows[0]['provider']);
        Http::assertSentCount(1);
    }

    public function test_nse_is_used_when_yahoo_throws_provider_error(): void
    {
        FundamentalSetting::query()->create(['nse_official_fallback_enabled' => true]);
        Setting::setValue('nifty500_constituents_json', json_encode(['RELIANCE']));
        Setting::setValue('nifty500_constituents_cached_at', now()->toIso8601String());
        config([
            'fundamentals_bootstrap.nse_official_enabled' => true,
            'fundamentals_bootstrap.nse_official_feed_url' => 'https://feeds.example/nse-fundamentals',
        ]);
        Http::fake(['feeds.example/*' => Http::response(['facts' => [[
            'statement_type' => 'income_statement',
            'cadence' => 'quarterly',
            'statement_basis' => 'consolidated',
            'fact_key' => 'revenue',
            'period_end' => '2024-03-31',
            'value' => 500,
        ]]], 200)]);

        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andThrow(
            new \RuntimeException('yfinance returned no fundamental statements')
        );
        $service = new FundamentalHistoricalIngestService(
            new NseOfficialFundamentalHistoricalSource,
            new BseOfficialFundamentalHistoricalSource,
            new YahooFundamentalHistoricalSource($provider),
        );
        $stock = Stock::query()->create(['symbol' => 'RELIANCE', 'exchange' => 'NSE', 'name' => 'Reliance']);

        $rows = $service->fetch($stock, 'quarterly');

        $this->assertCount(1, $rows);
        $this->assertSame('nse_official', $rows[0]['provider']);
        $this->assertSame(500.0, (float) $rows[0]['value']);
        Http::assertSentCount(1);
    }

    public function test_provider_error_is_not_treated_as_success_when_no_fallback_is_available(): void
    {
        config(['fundamentals_bootstrap.nse_official_enabled' => false]);

        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('provider unavailable'));
        $service = new FundamentalHistoricalIngestService(
            new NseOfficialFundamentalHistoricalSource,
            new BseOfficialFundamentalHistoricalSource,
            new YahooFundamentalHistoricalSource($provider),
        );
        $stock = Stock::query()->create(['symbol' => 'RELIANCE', 'exchange' => 'NSE', 'name' => 'Reliance']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider unavailable');
        $service->fetch($stock, 'quarterly');
    }

    public function test_nse_is_used_when_yahoo_returns_no_usable_rows(): void
    {
        FundamentalSetting::query()->create(['nse_official_fallback_enabled' => true]);
        Setting::setValue('nifty500_constituents_json', json_encode(['RELIANCE']));
        Setting::setValue('nifty500_constituents_cached_at', now()->toIso8601String());
        config([
            'fundamentals_bootstrap.nse_official_enabled' => true,
            'fundamentals_bootstrap.nse_official_feed_url' => 'https://feeds.example/nse-fundamentals',
        ]);

        Http::fake([
            'feeds.example/*' => Http::response([
                'facts' => [[
                    'statement_type' => 'income_statement',
                    'cadence' => 'quarterly',
                    'statement_basis' => 'consolidated',
                    'fact_key' => 'revenue',
                    'period_end' => '2024-03-31',
                    'value' => 500,
                    'availability_date' => '2024-05-15',
                ]],
            ], 200),
        ]);

        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn([
            ['value' => 999],
            ['statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'revenue', 'period_end' => '2024-03-31', 'value' => null],
        ]);
        $service = new FundamentalHistoricalIngestService(
            new NseOfficialFundamentalHistoricalSource,
            new BseOfficialFundamentalHistoricalSource,
            new YahooFundamentalHistoricalSource($provider),
        );

        $stock = Stock::query()->create(['symbol' => 'RELIANCE', 'exchange' => 'NSE', 'name' => 'Reliance']);
        $rows = $service->fetch($stock, 'quarterly');

        $this->assertCount(1, $rows);
        $this->assertSame('nse_official', $rows[0]['provider']);
        $this->assertSame(500.0, (float) $rows[0]['value']);
        Http::assertSentCount(1);
    }

}
