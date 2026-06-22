<?php

namespace Tests\Feature;

use App\Jobs\DailyMarketDataJob;
use App\Services\AlertExpirationService;
use App\Services\DailyMarketSyncService;
use App\Services\MetricsUpdateService;
use App\Services\PriceFetchService;
use App\Services\SyncLogService;
use App\Services\SystemLogService;
use App\Services\TelegramNotificationService;
use Mockery;
use PHPUnit\Framework\TestCase;

class DailyMarketDataJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_daily_job_logs_and_notifies_on_failure(): void
    {
        $priceFetch = Mockery::mock(PriceFetchService::class);
        $metrics = Mockery::mock(MetricsUpdateService::class);
        $portfolio = Mockery::mock(\App\Services\PortfolioCalculationService::class);
        $telegram = Mockery::mock(TelegramNotificationService::class);
        $logger = Mockery::mock(SystemLogService::class);
        $syncLog = Mockery::mock(SyncLogService::class);
        $dailySyncStatus = Mockery::mock(DailyMarketSyncService::class);
        $dailySyncStatus->shouldNotReceive('markSuccessful');
        $dailySyncStatus->shouldReceive('clearInProgress')->once();
        $alertExpiration = Mockery::mock(AlertExpirationService::class);
        $alertExpiration->shouldReceive('latestPortfolioPriceDate')->andReturn('2026-01-01');

        $priceFetch->shouldReceive('syncBenchmark')->once()->andThrow(new \RuntimeException('sync failed'));
        $telegram->shouldReceive('sendSyncFailureAlert')->once();
        $logger->shouldReceive('log')->once();
        $syncLog->shouldReceive('beginRun')->once()->andReturn('run-test');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once();

        $job = new DailyMarketDataJob();
        $this->expectException(\RuntimeException::class);
        $job->handle($priceFetch, $metrics, $portfolio, $telegram, $logger, $syncLog, $dailySyncStatus, $alertExpiration);
    }
}
