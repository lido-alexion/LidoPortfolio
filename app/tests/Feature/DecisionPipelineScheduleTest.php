<?php

namespace Tests\Feature;

use App\Engines\Pipeline\DailyDecisionPipeline;
use App\Jobs\DailyMarketDataJob;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\User;
use App\Services\AdminOperationalAlertService;
use App\Services\AlertExpirationService;
use App\Services\Alerts\AlertPolicyEvaluationService;
use App\Services\Analytics\MarketDepthService;
use App\Services\BenchmarkPriceSyncService;
use App\Services\DailyMarketSyncService;
use App\Services\DecisionPipelineScheduleService;
use App\Services\MetricsUpdateService;
use App\Services\PortfolioCalculationService;
use App\Services\PriceFetchService;
use App\Services\SyncLogService;
use App\Services\SystemLogService;
use App\Services\TelegramNotificationService;
use App\Support\TradingOsConfig;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DecisionPipelineScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-07 19:05:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_scheduled_pipeline_skips_when_automatic_run_already_completed_today(): void
    {
        app(DecisionPipelineScheduleService::class)->markAutomaticRunToday('post-sync');

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful()
            ->expectsOutputToContain('already completed automatically today');
    }

    public function test_manual_pipeline_command_runs_even_when_automatic_run_completed_today(): void
    {
        app(DecisionPipelineScheduleService::class)->markAutomaticRunToday('scheduled');

        $user = User::query()->create([
            'name' => 'Pipeline User',
            'email' => 'pipe-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $this->mock(DailyDecisionPipeline::class, function ($mock) use ($profile): void {
            $mock->shouldReceive('run')
                ->once()
                ->with(
                    Mockery::on(fn ($p) => $p instanceof PortfolioProfile && $p->id === $profile->id),
                    Mockery::on(fn (array $opts) => ($opts['trigger'] ?? null) === 'manual'),
                )
                ->andReturn([
                    'pipeline_run' => (object) ['id' => 1],
                    'stages' => ['discovery' => ['candidates' => 0]],
                ]);
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'manual'])
            ->assertSuccessful();
    }

    public function test_successful_daily_sync_triggers_pipeline_when_hook_enabled(): void
    {
        config([
            TradingOsConfig::KEY_ENABLED => true,
            TradingOsConfig::KEY_PIPELINE.'.run_after_daily_sync' => true,
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('portfolio:decision-pipeline', ['--trigger' => 'post-sync'])
            ->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('OK');

        $this->runSuccessfulDailyMarketJob();
    }

    public function test_successful_daily_sync_does_not_trigger_pipeline_when_hook_disabled(): void
    {
        config([
            TradingOsConfig::KEY_ENABLED => true,
            TradingOsConfig::KEY_PIPELINE.'.run_after_daily_sync' => false,
        ]);

        Artisan::shouldReceive('call')->never();

        $this->runSuccessfulDailyMarketJob();
    }

    public function test_partial_daily_sync_does_not_trigger_pipeline(): void
    {
        config([
            TradingOsConfig::KEY_ENABLED => true,
            TradingOsConfig::KEY_PIPELINE.'.run_after_daily_sync' => true,
        ]);

        Artisan::shouldReceive('call')->never();

        $this->runPartialDailyMarketJob();
    }

    public function test_post_sync_trigger_marks_automatic_daily_guard_on_success(): void
    {
        $user = User::query()->create([
            'name' => 'Post Sync User',
            'email' => 'post-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $this->mock(DailyDecisionPipeline::class, function ($mock) use ($profile): void {
            $mock->shouldReceive('run')
                ->once()
                ->with(
                    Mockery::on(fn ($p) => $p instanceof PortfolioProfile && $p->id === $profile->id),
                    Mockery::on(fn (array $opts) => ($opts['trigger'] ?? null) === 'post-sync'),
                )
                ->andReturn([
                    'pipeline_run' => (object) ['id' => 1],
                    'stages' => ['discovery' => ['candidates' => 0]],
                ]);
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'post-sync'])
            ->assertSuccessful();

        $this->assertTrue(app(DecisionPipelineScheduleService::class)->hasAutomaticRunToday());
        $this->assertSame('post-sync', app(DecisionPipelineScheduleService::class)->lastAutomaticTrigger());
    }

    protected function runSuccessfulDailyMarketJob(): void
    {
        $priceFetch = Mockery::mock(PriceFetchService::class);
        $metrics = Mockery::mock(MetricsUpdateService::class);
        $portfolio = Mockery::mock(PortfolioCalculationService::class);
        $telegram = Mockery::mock(TelegramNotificationService::class);
        $logger = Mockery::mock(SystemLogService::class);
        $syncLog = Mockery::mock(SyncLogService::class);
        $dailySyncStatus = Mockery::mock(DailyMarketSyncService::class);
        $alertExpiration = Mockery::mock(AlertExpirationService::class);
        $alertPolicyEvaluation = Mockery::mock(AlertPolicyEvaluationService::class);
        $benchmarkSync = Mockery::mock(BenchmarkPriceSyncService::class);
        $adminAlerts = Mockery::mock(AdminOperationalAlertService::class);
        $marketDepth = Mockery::mock(MarketDepthService::class);

        $this->app->instance(AdminOperationalAlertService::class, $adminAlerts);
        $this->app->instance(MarketDepthService::class, $marketDepth);

        $benchmarkSync->shouldReceive('syncIfNeeded')->once()->with(true)->andReturn(['success' => true]);
        $metrics->shouldReceive('updateAllTrackedStocks')->once();
        $alertPolicyEvaluation->shouldReceive('evaluateAllProfiles')->once()->andReturn(['profiles' => 0]);
        $portfolio->shouldReceive('storeSnapshot')->zeroOrMoreTimes();
        $dailySyncStatus->shouldReceive('markSuccessful')->once();
        $dailySyncStatus->shouldReceive('clearInProgress')->once();
        $alertExpiration->shouldReceive('latestPortfolioPriceDate')->andReturn('2026-08-07');
        $marketDepth->shouldReceive('refreshLatest')->once()->with(true);
        $adminAlerts->shouldReceive('syncAndNotify')->once();

        $syncLog->shouldReceive('beginRun')->once()->andReturn('run-test');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once()->with('run-test', 'success', Mockery::type('array'));

        $job = new DailyMarketDataJob();
        $job->handle(
            $priceFetch,
            $metrics,
            $portfolio,
            $telegram,
            $logger,
            $syncLog,
            $dailySyncStatus,
            $alertExpiration,
            $alertPolicyEvaluation,
            $benchmarkSync,
        );
    }

    protected function runPartialDailyMarketJob(): void
    {
        $user = User::query()->create([
            'name' => 'Partial Sync User',
            'email' => 'partial-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'P'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Partial Sync Stock',
            'is_active' => true,
        ]);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 1,
            'avg_buy_price' => 100,
            'invested_amount' => 100,
            'updated_at' => now(),
        ]);

        $priceFetch = Mockery::mock(PriceFetchService::class);
        $metrics = Mockery::mock(MetricsUpdateService::class);
        $portfolio = Mockery::mock(PortfolioCalculationService::class);
        $telegram = Mockery::mock(TelegramNotificationService::class);
        $logger = Mockery::mock(SystemLogService::class);
        $syncLog = Mockery::mock(SyncLogService::class);
        $dailySyncStatus = Mockery::mock(DailyMarketSyncService::class);
        $alertExpiration = Mockery::mock(AlertExpirationService::class);
        $alertPolicyEvaluation = Mockery::mock(AlertPolicyEvaluationService::class);
        $benchmarkSync = Mockery::mock(BenchmarkPriceSyncService::class);
        $adminAlerts = Mockery::mock(AdminOperationalAlertService::class);

        $this->app->instance(AdminOperationalAlertService::class, $adminAlerts);

        $benchmarkSync->shouldReceive('syncIfNeeded')->once()->with(true)->andReturn(['success' => true]);
        $priceFetch->shouldReceive('syncStock')->once()->andReturn(['success' => false, 'errors' => ['no rows']]);
        $metrics->shouldReceive('updateAllTrackedStocks')->once();
        $alertPolicyEvaluation->shouldReceive('evaluateAllProfiles')->once()->andReturn([]);
        $portfolio->shouldReceive('storeSnapshot')->zeroOrMoreTimes();
        $dailySyncStatus->shouldReceive('markIncomplete')->once();
        $dailySyncStatus->shouldReceive('clearInProgress')->once();
        $alertExpiration->shouldReceive('latestPortfolioPriceDate')->andReturn('2026-08-07');
        $telegram->shouldReceive('sendSyncFailureAlert')->once();
        $adminAlerts->shouldReceive('syncAndNotify')->once();

        $syncLog->shouldReceive('beginRun')->once()->andReturn('run-test');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once()->with('run-test', 'partial', Mockery::type('array'), Mockery::type('string'));

        $job = new DailyMarketDataJob();
        $job->handle(
            $priceFetch,
            $metrics,
            $portfolio,
            $telegram,
            $logger,
            $syncLog,
            $dailySyncStatus,
            $alertExpiration,
            $alertPolicyEvaluation,
            $benchmarkSync,
        );
    }
}
