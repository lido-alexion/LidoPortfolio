<?php

namespace Tests\Feature;

use App\Engines\Data\DataEngine;
use App\Engines\Data\DatasetVersionLedger;
use App\Engines\Discovery\DiscoveryEngine;
use App\Engines\Evaluation\EvaluationEngine;
use App\Engines\Pipeline\DailyDecisionPipeline;
use App\Engines\Recommendation\RecommendationEngine;
use App\Exceptions\DomainException;
use App\Models\DatasetVersion;
use App\Models\DiscoveryRun;
use App\Models\EvaluationRun;
use App\Models\PipelineRun;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Services\DailyMarketSyncService;
use App\Support\TradingOsConfig;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class DatasetVersioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            TradingOsConfig::KEY_ENABLED => true,
            TradingOsConfig::KEY_NOTIFICATION.'.notify_on_generate' => false,
            TradingOsConfig::KEY_DISCOVERY.'.include_screener_hits' => false,
            TradingOsConfig::KEY_DISCOVERY.'.include_patterns' => false,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-27 14:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_successful_sync_creates_an_immutable_dataset_version(): void
    {
        $this->seedPriceBar('2026-08-27');
        $sync = app(DailyMarketSyncService::class);
        $sync->markSuccessful();

        $this->assertSame(1, DatasetVersion::query()->count());
        $version = DatasetVersion::query()->first();
        $this->assertNotNull($version);
        $this->assertSame('ds-20260827140000-20260827', $version->version_key);
        $this->assertSame('2026-08-27', $version->latest_price_date?->toDateString());
        $this->assertSame(1, (int) $version->price_bars);
        $this->assertSame($version->version_key, app(DatasetVersionLedger::class)->currentVersionKey());
        $this->assertSame($version->version_key, app(DataEngine::class)->currentDatasetVersion());
        $this->assertNotNull($sync->lastSuccessfulSyncAt());
    }

    public function test_version_identity_cannot_be_mutated(): void
    {
        app(DailyMarketSyncService::class)->markSuccessful();
        $version = DatasetVersion::query()->first();
        $originalKey = $version->version_key;
        $originalSyncedAt = $version->synced_at->toIso8601String();

        try {
            $version->update(['version_key' => 'ds-mutated']);
            $this->fail('Expected LogicException when mutating a dataset version.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $fresh = DatasetVersion::query()->find($version->id);
        $this->assertSame($originalKey, $fresh->version_key);
        $this->assertSame($originalSyncedAt, $fresh->synced_at->toIso8601String());

        $this->seedPriceBar('2026-08-28');
        $fresh->refresh();
        $this->assertSame($originalKey, $fresh->version_key);
        $this->assertNull($fresh->latest_price_date);
        $this->assertSame(0, (int) $fresh->price_bars);
    }

    public function test_later_successful_sync_creates_a_distinct_version(): void
    {
        $sync = app(DailyMarketSyncService::class);
        $sync->markSuccessful();
        $first = DatasetVersion::query()->orderBy('id')->first();

        Carbon::setTestNow(Carbon::parse('2026-08-28 14:00:00', 'Asia/Kolkata'));
        $this->seedPriceBar('2026-08-28');
        $sync->markSuccessful();

        $this->assertSame(2, DatasetVersion::query()->count());
        $second = DatasetVersion::query()->orderByDesc('id')->first();
        $this->assertNotSame($first->version_key, $second->version_key);
        $this->assertSame($first->version_key, DatasetVersion::query()->find($first->id)->version_key);
        $this->assertSame($second->version_key, app(DatasetVersionLedger::class)->currentVersionKey());
        $this->assertSame('ds-20260828140000-20260828', $second->version_key);
    }

    public function test_same_instant_second_success_still_gets_a_distinct_key(): void
    {
        $sync = app(DailyMarketSyncService::class);
        $sync->markSuccessful();
        $sync->markSuccessful();

        $keys = DatasetVersion::query()->orderBy('id')->pluck('version_key')->all();
        $this->assertCount(2, $keys);
        $this->assertSame('ds-20260827140000-none', $keys[0]);
        $this->assertSame('ds-20260827140000-none-2', $keys[1]);
        $this->assertSame($keys[1], app(DatasetVersionLedger::class)->currentVersionKey());
    }

    public function test_incomplete_sync_does_not_create_or_activate_a_version(): void
    {
        $sync = app(DailyMarketSyncService::class);
        $sync->markSuccessful();
        $current = app(DatasetVersionLedger::class)->currentVersionKey();
        $successfulAt = $sync->lastSuccessfulSyncAt();

        $sync->markIncomplete(4, 1);

        $this->assertSame(1, DatasetVersion::query()->count());
        $this->assertSame($current, app(DatasetVersionLedger::class)->currentVersionKey());
        $this->assertTrue($successfulAt->equalTo($sync->lastSuccessfulSyncAt()));
        $this->assertFalse($sync->hasSyncedSuccessfullyToday());
    }

    public function test_failed_sync_without_mark_successful_creates_no_version(): void
    {
        app(DailyMarketSyncService::class)->markIncomplete(2, 2);

        $this->assertSame(0, DatasetVersion::query()->count());
        $this->assertSame(DatasetVersionLedger::NONE, app(DataEngine::class)->currentDatasetVersion());
        $this->assertNull(app(DailyMarketSyncService::class)->lastSuccessfulSyncAt());
    }

    public function test_discovery_run_records_the_dataset_version_it_consumed(): void
    {
        $this->seedPriceBar('2026-08-27');
        app(DailyMarketSyncService::class)->markSuccessful();
        $versionKey = app(DatasetVersionLedger::class)->currentVersionKey();

        $result = app(DiscoveryEngine::class)->run($this->makeProfile());

        $this->assertSame($versionKey, $result['run']->dataset_version);
        $this->assertDatabaseHas('portfolio_tos_discovery_runs', [
            'id' => $result['run']->id,
            'dataset_version' => $versionKey,
        ]);
    }

    public function test_historical_discovery_run_stays_linked_after_a_later_sync(): void
    {
        app(DailyMarketSyncService::class)->markSuccessful();
        $firstKey = app(DatasetVersionLedger::class)->currentVersionKey();
        $profile = $this->makeProfile();
        $firstRun = app(DiscoveryEngine::class)->run($profile)['run'];
        $this->assertSame($firstKey, $firstRun->dataset_version);

        Carbon::setTestNow(Carbon::parse('2026-08-28 09:00:00', 'Asia/Kolkata'));
        $this->seedPriceBar('2026-08-28');
        app(DailyMarketSyncService::class)->markSuccessful();
        $secondKey = app(DatasetVersionLedger::class)->currentVersionKey();
        $this->assertNotSame($firstKey, $secondKey);

        $secondRun = app(DiscoveryEngine::class)->run($profile)['run'];

        $this->assertSame($firstKey, $firstRun->fresh()->dataset_version);
        $this->assertSame($secondKey, $secondRun->dataset_version);
        $this->assertSame($firstKey, DatasetVersion::query()->orderBy('id')->value('version_key'));
    }

    public function test_existing_version_does_not_bypass_stale_freshness_gate(): void
    {
        $profile = $this->makeProfile();
        app(DailyMarketSyncService::class)->recordSuccessfulSyncAt(
            Carbon::parse('2026-08-26 12:59:59', 'Asia/Kolkata'),
        );
        $this->assertSame(1, DatasetVersion::query()->count());
        $this->mockDownstreamNever();

        try {
            app(DailyDecisionPipeline::class)->run($profile, ['notify' => false, 'review' => false]);
            $this->fail('Expected DomainException; a dataset version must not bypass freshness.');
        } catch (DomainException $e) {
            $this->assertSame('DATASET_NOT_FRESH', $e->errorCode());
        }

        $run = PipelineRun::query()->where('profile_id', $profile->id)->latest('id')->first();
        $this->assertSame('failed', $run->status);
        $this->assertSame('dataset_not_fresh', $run->stages_json['publish_gate']['reason'] ?? null);
        $this->assertSame(24, $run->stages_json['publish_gate']['max_age_hours'] ?? null);
        $this->assertArrayNotHasKey('discovery', $run->stages_json);
        $this->assertSame(0, DiscoveryRun::query()->count());
        $this->assertSame(0, EvaluationRun::query()->count());
        $this->assertSame(0, TradingRecommendation::query()->count());
    }

    public function test_fresh_versioned_dataset_still_allows_the_pipeline(): void
    {
        $profile = $this->makeProfile();
        app(DailyMarketSyncService::class)->recordSuccessfulSyncAt(
            Carbon::parse('2026-08-27 10:00:00', 'Asia/Kolkata'),
        );
        $versionKey = app(DatasetVersionLedger::class)->currentVersionKey();
        $this->mockDownstreamAllow($profile, $versionKey);

        $result = app(DailyDecisionPipeline::class)->run($profile, [
            'notify' => false,
            'review' => false,
        ]);

        $this->assertSame('completed', $result['pipeline_run']->status);
        $this->assertTrue($result['stages']['publish_gate']['allowed']);
        $this->assertSame(24, $result['stages']['publish_gate']['max_age_hours']);
        $this->assertSame($versionKey, $result['stages']['data_status']['dataset_version']);
        $this->assertSame($versionKey, DiscoveryRun::query()->value('dataset_version'));
    }

    public function test_ohlcv_bars_without_successful_sync_create_no_version_and_do_not_bypass_freshness(): void
    {
        $profile = $this->makeProfile();
        $this->seedPriceBar('2026-08-27');
        $this->mockDownstreamNever();

        $this->assertSame(0, DatasetVersion::query()->count());
        $this->assertSame(DatasetVersionLedger::NONE, app(DataEngine::class)->currentDatasetVersion());

        try {
            app(DailyDecisionPipeline::class)->run($profile, ['notify' => false, 'review' => false]);
            $this->fail('Expected DomainException; OHLCV must not stand in for a successful versioned sync.');
        } catch (DomainException $e) {
            $this->assertSame('DATASET_NOT_FRESH', $e->errorCode());
        }

        $this->assertSame(0, DatasetVersion::query()->count());
        $this->assertSame(0, DiscoveryRun::query()->count());
    }

    protected function mockDownstreamAllow(PortfolioProfile $profile, string $versionKey): void
    {
        $this->mock(DiscoveryEngine::class, function ($mock) use ($profile, $versionKey): void {
            $mock->shouldReceive('run')->once()->andReturnUsing(function () use ($profile, $versionKey) {
                $run = DiscoveryRun::query()->create([
                    'profile_id' => $profile->id,
                    'dataset_version' => $versionKey,
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
            'name' => 'Version User',
            'email' => 'dsver-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        return $this->defaultPortfolioFor($user);
    }

    protected function seedPriceBar(string $priceDate): Stock
    {
        $stock = Stock::query()->create([
            'symbol' => 'V'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Version Seed',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => $priceDate,
            'open_price' => 100,
            'high_price' => 101,
            'low_price' => 99,
            'close_price' => 100.5,
            'volume' => 1000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        return $stock;
    }
}
