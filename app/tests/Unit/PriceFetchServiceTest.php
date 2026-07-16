<?php

namespace Tests\Unit;

use App\Services\PriceFetchService;
use App\Services\PriceProviders\AlphaVantagePriceProvider;
use App\Services\PriceProviders\BseBhavcopyPriceProvider;
use App\Services\PriceProviders\NsePriceProvider;
use App\Services\PriceProviders\YahooPriceProvider;
use App\Services\PortfolioLoggerService;
use App\Services\ProviderResolverService;
use App\Services\SystemLogService;
use App\Services\TelegramNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PriceFetchServiceTest extends TestCase
{
    use RefreshDatabase;
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fallback_uses_secondary_provider_when_primary_fails(): void
    {
        $nse = Mockery::mock(NsePriceProvider::class);
        $bseBhavcopy = Mockery::mock(BseBhavcopyPriceProvider::class);
        $yahoo = Mockery::mock(YahooPriceProvider::class);
        $alpha = Mockery::mock(AlphaVantagePriceProvider::class);
        $logger = Mockery::mock(SystemLogService::class);
        $portfolioLogger = Mockery::mock(PortfolioLoggerService::class);
        $providerResolver = new ProviderResolverService;
        $telegram = Mockery::mock(TelegramNotificationService::class);

        $from = Carbon::parse('2024-01-01');
        $to = Carbon::parse('2024-01-02');
        $rows = [[
            'price_date' => '2024-01-02',
            'open_price' => 100.0,
            'high_price' => 105.0,
            'low_price' => 99.0,
            'close_price' => 104.0,
            'volume' => 1000,
        ]];

        $nse->shouldReceive('fetchHistorical')->twice()->andThrow(new \RuntimeException('NSE down'));
        $nse->shouldReceive('getName')->andReturn('nse');
        $bseBhavcopy->shouldReceive('getName')->andReturn('bse_bhavcopy');
        $bseBhavcopy->shouldReceive('fetchHistorical')->never();
        $yahoo->shouldReceive('fetchHistorical')->once()->andReturn($rows);
        $yahoo->shouldReceive('getName')->andReturn('yahoo');
        $alpha->shouldReceive('fetchHistorical')->never();
        $logger->shouldReceive('log')->atLeast()->times(2);
        $portfolioLogger->shouldReceive('provider')->atLeast()->once();
        $telegram->shouldNotReceive('sendSyncFailureAlert');

        $service = new PriceFetchService($nse, $bseBhavcopy, $yahoo, $alpha, $logger, $portfolioLogger, $providerResolver, $telegram);
        $result = $service->fetchHistoricalWithFallback('INFY', $from, $to);

        $this->assertSame('yahoo', $result['provider']);
        $this->assertCount(1, $result['rows']);
    }

    public function test_fallback_continues_when_primary_returns_rows_outside_requested_range(): void
    {
        $nse = Mockery::mock(NsePriceProvider::class);
        $bseBhavcopy = Mockery::mock(BseBhavcopyPriceProvider::class);
        $yahoo = Mockery::mock(YahooPriceProvider::class);
        $alpha = Mockery::mock(AlphaVantagePriceProvider::class);
        $logger = Mockery::mock(SystemLogService::class);
        $portfolioLogger = Mockery::mock(PortfolioLoggerService::class);
        $providerResolver = new ProviderResolverService;
        $telegram = Mockery::mock(TelegramNotificationService::class);

        $from = Carbon::parse('2024-01-01');
        $to = Carbon::parse('2024-01-02');
        $rows = [[
            'price_date' => '2024-01-02',
            'open_price' => 100.0,
            'high_price' => 105.0,
            'low_price' => 99.0,
            'close_price' => 104.0,
            'volume' => 1000,
        ]];

        $nse->shouldReceive('fetchHistorical')->twice()->andReturn([[
            'price_date' => '2023-06-01',
            'open_price' => 1.0,
            'high_price' => 1.0,
            'low_price' => 1.0,
            'close_price' => 1.0,
            'volume' => 1,
        ]]);
        $nse->shouldReceive('getName')->andReturn('nse');
        $bseBhavcopy->shouldReceive('getName')->andReturn('bse_bhavcopy');
        $bseBhavcopy->shouldReceive('fetchHistorical')->never();
        $yahoo->shouldReceive('fetchHistorical')->once()->andReturn($rows);
        $yahoo->shouldReceive('getName')->andReturn('yahoo');
        $alpha->shouldReceive('getName')->andReturn('alpha_vantage');
        $portfolioLogger->shouldReceive('provider')->atLeast()->once();
        $telegram->shouldNotReceive('sendSyncFailureAlert');

        $service = new PriceFetchService($nse, $bseBhavcopy, $yahoo, $alpha, $logger, $portfolioLogger, $providerResolver, $telegram);
        $result = $service->fetchHistoricalWithFallback('INFY', $from, $to);

        $this->assertSame('yahoo', $result['provider']);
        $this->assertCount(1, $result['rows']);
    }

    public function test_provider_chain_skips_nse_for_bse_stock(): void
    {
        $bseBhavcopy = Mockery::mock(BseBhavcopyPriceProvider::class);
        $bseBhavcopy->shouldReceive('getName')->andReturn('bse_bhavcopy');

        $service = new PriceFetchService(
            Mockery::mock(NsePriceProvider::class),
            $bseBhavcopy,
            Mockery::mock(YahooPriceProvider::class),
            Mockery::mock(AlphaVantagePriceProvider::class),
            Mockery::mock(SystemLogService::class),
            Mockery::mock(PortfolioLoggerService::class),
            new ProviderResolverService,
            Mockery::mock(TelegramNotificationService::class),
        );

        $bse = new \App\Models\Stock(['symbol' => 'INFY', 'exchange' => 'BSE']);
        $nse = new \App\Models\Stock(['symbol' => 'INFY', 'exchange' => 'NSE']);
        $nifty = new \App\Models\Stock(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'is_benchmark' => true]);
        $sensex = new \App\Models\Stock(['symbol' => 'SENSEX', 'exchange' => 'BSE', 'is_benchmark' => true]);

        $this->assertSame(['bse_bhavcopy', 'yahoo', 'alpha_vantage'], $service->providerChainForStock($bse));
        $this->assertSame(['nse', 'yahoo', 'alpha_vantage'], $service->providerChainForStock($nse));
        $this->assertSame(['nse', 'yahoo', 'alpha_vantage'], $service->providerChainForStock($nifty));
        $this->assertSame(['yahoo', 'alpha_vantage'], $service->providerChainForStock($sensex));
    }

    public function test_yahoo_tries_bo_when_ns_returns_no_rows(): void
    {
        $nse = Mockery::mock(NsePriceProvider::class);
        $bseBhavcopy = Mockery::mock(BseBhavcopyPriceProvider::class);
        $yahoo = Mockery::mock(YahooPriceProvider::class);
        $alpha = Mockery::mock(AlphaVantagePriceProvider::class);
        $logger = Mockery::mock(SystemLogService::class);
        $portfolioLogger = Mockery::mock(PortfolioLoggerService::class);
        $providerResolver = new ProviderResolverService;
        $telegram = Mockery::mock(TelegramNotificationService::class);

        $from = Carbon::parse('2025-07-09');
        $to = Carbon::parse('2026-04-19');
        $rows = [[
            'price_date' => '2025-07-10',
            'open_price' => 100.0,
            'high_price' => 105.0,
            'low_price' => 99.0,
            'close_price' => 104.0,
            'volume' => 1000,
        ]];

        $nse->shouldReceive('getName')->andReturn('nse');
        $nse->shouldReceive('fetchHistorical')->andReturn([]);
        $bseBhavcopy->shouldReceive('getName')->andReturn('bse_bhavcopy');
        $yahoo->shouldReceive('getName')->andReturn('yahoo');
        $yahoo->shouldReceive('fetchHistorical')
            ->once()
            ->with('3BBLACKBIO', $from, $to, '3BBLACKBIO.NS')
            ->andReturn([]);
        $yahoo->shouldReceive('fetchHistorical')
            ->once()
            ->with('3BBLACKBIO', $from, $to, '3BBLACKBIO.BO')
            ->andReturn($rows);
        $alpha->shouldReceive('getName')->andReturn('alpha_vantage');
        $telegram->shouldNotReceive('sendSyncFailureAlert');

        $stock = new \App\Models\Stock(['symbol' => '3BBLACKBIO', 'exchange' => 'NSE']);
        $service = new PriceFetchService($nse, $bseBhavcopy, $yahoo, $alpha, $logger, $portfolioLogger, $providerResolver, $telegram);
        $result = $service->fetchFromProvider('yahoo', '3BBLACKBIO', $from, $to, $stock);

        $this->assertCount(1, $result['rows']);
        $this->assertSame([], $result['errors']);
    }

    public function test_normalize_volume_null_for_benchmark(): void
    {
        $service = app(PriceFetchService::class);
        $benchmark = new \App\Models\Stock(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'is_benchmark' => true]);

        $this->assertNull($service->normalizeVolumeForStorage(2709069677, $benchmark));
    }

    public function test_normalize_volume_rejects_overflow_for_equity(): void
    {
        $service = app(PriceFetchService::class);
        $equity = new \App\Models\Stock(['symbol' => 'INFY', 'exchange' => 'NSE', 'is_benchmark' => false]);

        $this->assertNull($service->normalizeVolumeForStorage(5000000000, $equity));
        $this->assertSame(1000, $service->normalizeVolumeForStorage(1000, $equity));
    }

    public function test_store_historical_rows_omits_benchmark_volume(): void
    {
        $stock = \App\Models\Stock::query()->create([
            'symbol' => 'NIFTYBANK',
            'exchange' => 'NSE',
            'name' => 'Nifty Bank',
            'is_active' => true,
            'is_benchmark' => true,
        ]);

        $service = app(PriceFetchService::class);
        $stored = $service->storeHistoricalRows($stock, [[
            'price_date' => '2025-07-15',
            'open_price' => 23334.55,
            'high_price' => 23469.45,
            'low_price' => 23332.8,
            'close_price' => 23449.15,
            'volume' => 2709069677,
        ]], 'nse');

        $this->assertSame(1, $stored);
        $row = \App\Models\StockPrice::query()->where('stock_id', $stock->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->volume);
    }
}
