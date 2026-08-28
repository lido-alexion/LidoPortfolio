<?php

namespace Tests\Feature;

use App\Jobs\DailyMarketDataJob;
use App\Models\Alert;
use App\Models\AlertPolicy;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AdminOperationalAlertService;
use App\Services\AlertExpirationService;
use App\Services\Alerts\AlertPolicyEvaluationService;
use App\Services\Analytics\MarketDepthService;
use App\Services\BenchmarkPriceSyncService;
use App\Services\DailyMarketSyncService;
use App\Services\HoldingsCalculationService;
use App\Services\MetricsUpdateService;
use App\Services\PortfolioCalculationService;
use App\Services\PriceFetchService;
use App\Services\SyncLogService;
use App\Services\SystemLogService;
use App\Services\TelegramNotificationService;
use App\Support\TradingOsConfig;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AlertLifecycleOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: Stock, 3: AlertPolicy}
     */
    protected function seedUserHoldingAndPolicy(float $compareConstant = 50): array
    {
        $user = User::query()->create([
            'name' => 'Lifecycle User',
            'email' => 'life-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'LIFE'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Lifecycle Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);
        app(HoldingsCalculationService::class)->recalculateForProfile($profile);

        $policy = AlertPolicy::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Avg buy gt constant',
            'stock_universe' => 'holdings',
            'condition_column' => 'avg_buy_price',
            'condition_operator' => 'gt',
            'compare_type' => 'constant',
            'compare_constant' => $compareConstant,
            'message_template' => '{{symbol}} avg buy alert',
            'action_type' => 'track',
            'context_columns' => [],
            'is_enabled' => true,
        ]);

        return [$user, $profile, $stock, $policy];
    }

    public function test_active_alert_dedup_does_not_create_duplicate_when_condition_still_true(): void
    {
        [$user, $profile, $stock, $policy] = $this->seedUserHoldingAndPolicy();
        $evaluation = app(AlertPolicyEvaluationService::class);

        $first = $evaluation->evaluateProfile($profile);
        $this->assertSame(1, $first['generated']);

        $activeId = Alert::query()->whereNull('expired_at')->value('id');
        $this->assertNotNull($activeId);

        $second = $evaluation->evaluateProfile($profile);
        $this->assertSame(0, $second['generated']);
        $this->assertSame(1, Alert::query()->whereNull('expired_at')->count());
        $this->assertSame($activeId, Alert::query()->whereNull('expired_at')->value('id'));
    }

    public function test_expire_then_evaluate_recreates_alert_when_condition_still_true(): void
    {
        [$user, $profile, $stock, $policy] = $this->seedUserHoldingAndPolicy();
        $evaluation = app(AlertPolicyEvaluationService::class);
        $expiration = app(AlertExpirationService::class);

        $evaluation->evaluateProfile($profile);
        $old = Alert::query()->whereNull('expired_at')->first();
        $this->assertNotNull($old);
        $old->forceFill(['created_at' => Carbon::parse('2026-05-28 10:00:00')])->save();

        $expired = $expiration->expireBeforeTradingDay(Carbon::parse('2026-05-29')->startOfDay());
        $this->assertSame(1, $expired);
        $this->assertNotNull($old->fresh()->expired_at);
        $this->assertSame(AlertExpirationService::REASON_DATA_REFRESH, $old->fresh()->expiration_reason);

        $result = $evaluation->evaluateProfile($profile);
        $this->assertSame(1, $result['generated']);

        $active = Alert::query()->whereNull('expired_at')->get();
        $this->assertCount(1, $active);
        $this->assertNotSame($old->id, $active->first()->id);
        $this->assertSame(
            $evaluation->buildInstanceKey($user->id, $profile->id, $stock->id, $policy->id),
            $active->first()->instance_key,
        );
    }

    public function test_holding_closed_expires_alert_and_evaluation_does_not_recreate(): void
    {
        [$user, $profile, $stock, $policy] = $this->seedUserHoldingAndPolicy();
        $evaluation = app(AlertPolicyEvaluationService::class);

        $evaluation->evaluateProfile($profile);
        $this->assertSame(1, Alert::query()->whereNull('expired_at')->count());

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 10,
            'price' => 90,
            'fees' => 0,
            'transaction_date' => '2026-05-10',
        ]);
        app(HoldingsCalculationService::class)->recalculateForProfile($profile);

        $alert = Alert::query()->first();
        $this->assertNotNull($alert->fresh()->expired_at);
        $this->assertSame(AlertExpirationService::REASON_HOLDING_CLOSED, $alert->fresh()->expiration_reason);

        $result = $evaluation->evaluateProfile($profile);
        $this->assertSame(0, $result['generated']);
        $this->assertSame(0, Alert::query()->whereNull('expired_at')->count());
    }

    public function test_recreated_position_establishes_new_alert_lifecycle(): void
    {
        [$user, $profile, $stock, $policy] = $this->seedUserHoldingAndPolicy();
        $evaluation = app(AlertPolicyEvaluationService::class);

        $evaluation->evaluateProfile($profile);
        $firstAlertId = Alert::query()->whereNull('expired_at')->value('id');

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 10,
            'price' => 90,
            'fees' => 0,
            'transaction_date' => '2026-05-10',
        ]);
        app(HoldingsCalculationService::class)->recalculateForProfile($profile);
        $this->assertNotNull(Alert::query()->find($firstAlertId)?->expired_at);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 4,
            'price' => 110,
            'fees' => 0,
            'transaction_date' => '2026-05-20',
        ]);
        app(HoldingsCalculationService::class)->recalculateForProfile($profile);

        $result = $evaluation->evaluateProfile($profile);
        $this->assertSame(1, $result['generated']);
        $newId = Alert::query()->whereNull('expired_at')->value('id');
        $this->assertNotNull($newId);
        $this->assertNotSame($firstAlertId, $newId);
    }

    public function test_daily_market_job_expires_before_evaluating_on_new_trading_day(): void
    {
        config([TradingOsConfig::KEY_PIPELINE.'.run_after_daily_sync' => false]);

        $lifecycleSequence = [];

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

        $alertExpiration->shouldReceive('latestPortfolioPriceDate')
            ->once()
            ->andReturn('2026-05-28');
        $alertExpiration->shouldReceive('latestPortfolioPriceDate')
            ->once()
            ->andReturn('2026-05-29');

        $alertExpiration->shouldReceive('expireBeforeTradingDay')
            ->once()
            ->ordered()
            ->andReturnUsing(function () use (&$lifecycleSequence) {
                $lifecycleSequence[] = 'expire';

                return 1;
            });
        $alertPolicyEvaluation->shouldReceive('evaluateAllProfiles')
            ->once()
            ->ordered()
            ->andReturnUsing(function () use (&$lifecycleSequence) {
                $lifecycleSequence[] = 'evaluate';

                return ['profiles' => 0, 'policies' => 0, 'generated' => 0, 'skipped' => 0, 'holdings_checked' => 0];
            });

        $portfolio->shouldReceive('storeSnapshot')->zeroOrMoreTimes();
        $dailySyncStatus->shouldReceive('markSuccessful')->once();
        $dailySyncStatus->shouldReceive('clearInProgress')->once();
        $marketDepth->shouldReceive('refreshLatest')->once()->with(true);
        $adminAlerts->shouldReceive('syncAndNotify')->once();

        $syncLog->shouldReceive('beginRun')->once()->andReturn('run-order');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once()->with('run-order', 'success', Mockery::type('array'));

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

        $this->assertSame(['expire', 'evaluate'], $lifecycleSequence);
    }
}
