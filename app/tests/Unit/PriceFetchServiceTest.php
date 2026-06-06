<?php

namespace Tests\Unit;

use App\Services\PriceFetchService;
use App\Services\PriceProviders\AlphaVantagePriceProvider;
use App\Services\PriceProviders\NsePriceProvider;
use App\Services\PriceProviders\YahooPriceProvider;
use App\Services\PortfolioLoggerService;
use App\Services\ProviderResolverService;
use App\Services\SystemLogService;
use App\Services\TelegramNotificationService;
use Carbon\Carbon;
use Mockery;
use PHPUnit\Framework\TestCase;

class PriceFetchServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fallback_uses_secondary_provider_when_primary_fails(): void
    {
        $nse = Mockery::mock(NsePriceProvider::class);
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
        $yahoo->shouldReceive('fetchHistorical')->once()->andReturn($rows);
        $yahoo->shouldReceive('getName')->andReturn('yahoo');
        $alpha->shouldReceive('fetchHistorical')->never();
        $logger->shouldReceive('log')->atLeast()->times(2);
        $portfolioLogger->shouldReceive('provider')->atLeast()->once();
        $telegram->shouldNotReceive('sendSyncFailureAlert');

        $service = new PriceFetchService($nse, $yahoo, $alpha, $logger, $portfolioLogger, $providerResolver, $telegram);
        $result = $service->fetchHistoricalWithFallback('INFY', $from, $to);

        $this->assertSame('yahoo', $result['provider']);
        $this->assertCount(1, $result['rows']);
    }
}
