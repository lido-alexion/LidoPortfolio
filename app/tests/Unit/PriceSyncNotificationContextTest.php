<?php

namespace Tests\Unit;

use App\Services\PortfolioLoggerService;
use App\Services\PriceFetchService;
use App\Services\PriceProviders\AlphaVantagePriceProvider;
use App\Services\PriceProviders\BseBhavcopyPriceProvider;
use App\Services\PriceProviders\NsePriceProvider;
use App\Services\PriceProviders\YahooPriceProvider;
use App\Services\PriceSyncNotificationContext;
use App\Services\ProviderResolverService;
use App\Services\SystemLogService;
use App\Services\TelegramNotificationService;
use Carbon\Carbon;
use Mockery;
use PHPUnit\Framework\TestCase;

class PriceSyncNotificationContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_batch_context_suppresses_telegram_on_provider_failure(): void
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

        $nse->shouldReceive('fetchHistorical')->andThrow(new \RuntimeException('NSE down'));
        $nse->shouldReceive('getName')->andReturn('nse');
        $yahoo->shouldReceive('fetchHistorical')->andReturn([]);
        $yahoo->shouldReceive('getName')->andReturn('yahoo');
        $alpha->shouldReceive('fetchHistorical')->andReturn([]);
        $alpha->shouldReceive('getName')->andReturn('alpha_vantage');
        $logger->shouldReceive('log')->atLeast()->once();
        $portfolioLogger->shouldReceive('provider')->atLeast()->once();
        $telegram->shouldNotReceive('sendSyncFailureAlert');

        $bseBhavcopy = Mockery::mock(BseBhavcopyPriceProvider::class);
        $bseBhavcopy->shouldReceive('getName')->andReturn('bse_bhavcopy');
        $bseBhavcopy->shouldReceive('fetchHistorical')->andReturn([]);

        $service = new PriceFetchService($nse, $bseBhavcopy, $yahoo, $alpha, $logger, $portfolioLogger, $providerResolver, $telegram);

        PriceSyncNotificationContext::withoutTelegram(function () use ($service, $from, $to) {
            $result = $service->fetchHistoricalWithFallback('FAIL', $from, $to);
            $this->assertSame('none', $result['provider']);
        });
    }
}
