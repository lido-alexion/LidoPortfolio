<?php

namespace Tests\Feature;

use App\Engines\Discovery\DiscoveryEngine;
use App\Engines\Evaluation\EvaluationEngine;
use App\Engines\Pipeline\DailyDecisionPipeline;
use App\Engines\Recommendation\RecommendationEngine;
use App\Exceptions\DomainException;
use App\Models\DiscoveryRun;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\PipelineRun;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Support\TradingOsConfig;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MarksDailyDatasetPublished;
use Tests\TestCase;

class DatasetPublishGateTest extends TestCase
{
    use MarksDailyDatasetPublished;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            TradingOsConfig::KEY_ENABLED => true,
            TradingOsConfig::KEY_NOTIFICATION.'.notify_on_generate' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_fresh_dataset_within_24_hours_allows_discovery_to_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 14:00:00', 'Asia/Kolkata'));
        $profile = $this->makeProfile();
        $this->markLastSuccessfulDatasetSyncAt(now()->subHours(20));
        $this->mockDownstreamAllow($profile);

        $result = app(DailyDecisionPipeline::class)->run($profile, [
            'notify' => false,
            'review' => false,
        ]);

        $this->assertSame('completed', $result['pipeline_run']->status);
        $this->assertTrue($result['stages']['publish_gate']['allowed']);
        $this->assertSame(24, $result['stages']['publish_gate']['max_age_hours']);
        $this->assertNotEmpty($result['stages']['discovery']['run_id'] ?? null);
        $this->assertSame(1, DiscoveryRun::query()->count());
    }

    public function test_monday_72_hour_window_allows_friday_sync_even_when_not_synced_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 10:00:00', 'Asia/Kolkata'));
        $profile = $this->makeProfile();
        $this->markLastSuccessfulDatasetSyncAt(Carbon::parse('2026-08-21 12:00:00', 'Asia/Kolkata'));
        $this->mockDownstreamAllow($profile);

        $result = app(DailyDecisionPipeline::class)->run($profile, [
            'notify' => false,
            'review' => false,
        ]);

        $this->assertTrue($result['stages']['publish_gate']['allowed']);
        $this->assertSame(72, $result['stages']['publish_gate']['max_age_hours']);
        $this->assertFalse($result['stages']['data_status']['published']);
        $this->assertSame(1, DiscoveryRun::query()->count());
    }

    public function test_stale_dataset_stops_before_discovery_and_records_reason(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 14:00:00', 'Asia/Kolkata'));
        $profile = $this->makeProfile();
        $this->markLastSuccessfulDatasetSyncAt(now()->subSeconds(24 * 3600 + 1));
        $this->mockDownstreamNever();

        try {
            app(DailyDecisionPipeline::class)->run($profile, ['notify' => false, 'review' => false]);
            $this->fail('Expected DomainException for stale dataset.');
        } catch (DomainException $e) {
            $this->assertSame('DATASET_NOT_FRESH', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
            $this->assertStringContainsString('freshness window', $e->getMessage());
        }

        $run = PipelineRun::query()->where('profile_id', $profile->id)->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('failed', $run->status);
        $this->assertSame('dataset_not_fresh', $run->stages_json['publish_gate']['reason'] ?? null);
        $this->assertFalse($run->stages_json['publish_gate']['allowed'] ?? true);
        $this->assertSame(24, $run->stages_json['publish_gate']['max_age_hours'] ?? null);
        $this->assertArrayNotHasKey('discovery', $run->stages_json);
        $this->assertArrayNotHasKey('evaluation', $run->stages_json);
        $this->assertArrayNotHasKey('recommendation', $run->stages_json);
        $this->assertSame(0, DiscoveryRun::query()->count());
        $this->assertSame(0, EvaluationRun::query()->count());
        $this->assertSame(0, EvaluationResult::query()->count());
        $this->assertSame(0, TradingRecommendation::query()->count());
    }

    public function test_missing_sync_timestamp_stops_before_discovery(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 14:00:00', 'Asia/Kolkata'));
        $profile = $this->makeProfile();
        $this->mockDownstreamNever();

        try {
            app(DailyDecisionPipeline::class)->run($profile, ['notify' => false, 'review' => false]);
            $this->fail('Expected DomainException when no sync timestamp exists.');
        } catch (DomainException $e) {
            $this->assertSame('DATASET_NOT_FRESH', $e->errorCode());
        }

        $this->assertSame(0, DiscoveryRun::query()->count());
        $this->assertSame(0, EvaluationRun::query()->count());
        $this->assertSame(0, TradingRecommendation::query()->count());
    }

    public function test_existing_ohlcv_bars_do_not_bypass_stale_sync_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 14:00:00', 'Asia/Kolkata'));
        $profile = $this->makeProfile();
        $this->markLastSuccessfulDatasetSyncAt(now()->subHours(25));
        $stock = Stock::query()->create([
            'symbol' => 'G'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Gate Price Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->subDay()->toDateString(),
            'open_price' => 100,
            'high_price' => 101,
            'low_price' => 99,
            'close_price' => 100.5,
            'volume' => 1000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        $this->mockDownstreamNever();

        try {
            app(DailyDecisionPipeline::class)->run($profile, ['notify' => false, 'review' => false]);
            $this->fail('Expected DomainException; must not use existing OHLCV as a freshness bypass.');
        } catch (DomainException $e) {
            $this->assertSame('DATASET_NOT_FRESH', $e->errorCode());
        }

        $this->assertSame(0, DiscoveryRun::query()->count());
        $this->assertSame(0, EvaluationRun::query()->count());
        $this->assertSame(0, TradingRecommendation::query()->count());
    }

    public function test_monday_sync_older_than_72_hours_is_blocked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 10:00:00', 'Asia/Kolkata'));
        $profile = $this->makeProfile();
        $this->markLastSuccessfulDatasetSyncAt(Carbon::parse('2026-08-21 09:59:59', 'Asia/Kolkata'));
        $this->mockDownstreamNever();

        try {
            app(DailyDecisionPipeline::class)->run($profile, ['notify' => false, 'review' => false]);
            $this->fail('Expected DomainException for Monday dataset older than 72 hours.');
        } catch (DomainException $e) {
            $this->assertSame('DATASET_NOT_FRESH', $e->errorCode());
        }

        $run = PipelineRun::query()->where('profile_id', $profile->id)->latest('id')->first();
        $this->assertSame(72, $run->stages_json['publish_gate']['max_age_hours'] ?? null);
        $this->assertSame(0, DiscoveryRun::query()->count());
    }

    protected function mockDownstreamAllow(PortfolioProfile $profile): void
    {
        $this->mock(DiscoveryEngine::class, function ($mock) use ($profile): void {
            $mock->shouldReceive('run')->once()->andReturnUsing(function () use ($profile) {
                $run = DiscoveryRun::query()->create([
                    'profile_id' => $profile->id,
                    'dataset_version' => 'test',
                    'status' => 'completed',
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);

                return ['run' => $run, 'candidates' => []];
            });
        });
        $this->mock(EvaluationEngine::class, function ($mock): void {
            $mock->shouldReceive('run')->andReturn([
                'run' => EvaluationRun::query()->make(['id' => 0]),
                'results' => [],
            ]);
        });
        $this->mock(RecommendationEngine::class, function ($mock): void {
            $mock->shouldReceive('generate')->andReturn([
                'recommendations' => [],
                'batch_id' => 'test-batch',
            ]);
        });
    }

    protected function mockDownstreamNever(): void
    {
        $this->mock(DiscoveryEngine::class, function ($mock): void {
            $mock->shouldReceive('run')->never();
        });
        $this->mock(EvaluationEngine::class, function ($mock): void {
            $mock->shouldReceive('run')->never();
        });
        $this->mock(RecommendationEngine::class, function ($mock): void {
            $mock->shouldReceive('generate')->never();
        });
    }

    protected function makeProfile(): PortfolioProfile
    {
        $user = User::query()->create([
            'name' => 'Gate User',
            'email' => 'gate-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        return $this->defaultPortfolioFor($user);
    }
}
