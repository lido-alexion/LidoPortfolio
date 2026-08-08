<?php

namespace Tests\Feature;

use App\Engines\Pipeline\DailyDecisionPipeline;
use App\Models\PipelineRun;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Services\DecisionPipelineScheduleService;
use App\Support\TradingOsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Verification tests for F148/F149 retry and concurrent-trigger behaviour.
 * Documents current semantics; does not assert desired future redesign.
 */
class DecisionPipelineRetryVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([TradingOsConfig::KEY_ENABLED => true]);
    }

    protected function tearDown(): void
    {
        Cache::lock(DecisionPipelineScheduleService::AUTOMATIC_LOCK_KEY)->forceRelease();
        Mockery::close();
        parent::tearDown();
    }

    public function test_partial_multi_profile_failure_does_not_mark_guard_and_retries_only_failed_profiles(): void
    {
        $profiles = $this->seedProfiles(3);
        $runCounts = array_fill_keys($profiles->pluck('id')->all(), 0);
        $failingProfileId = $profiles->last()->id;

        $this->mock(DailyDecisionPipeline::class, function ($mock) use (&$runCounts, $failingProfileId): void {
            $mock->shouldReceive('run')
                ->times(4)
                ->with(
                    Mockery::type(PortfolioProfile::class),
                    Mockery::on(fn (array $opts) => ($opts['trigger'] ?? null) === 'scheduled'),
                )
                ->andReturnUsing(function (PortfolioProfile $profile) use (&$runCounts, $failingProfileId) {
                    $runCounts[$profile->id]++;
                    if ($profile->id === $failingProfileId && $runCounts[$profile->id] === 1) {
                        throw new RuntimeException('profile C first-attempt failure');
                    }

                    return [
                        'pipeline_run' => PipelineRun::query()->create([
                            'profile_id' => $profile->id,
                            'status' => 'completed',
                            'started_at' => now(),
                            'completed_at' => now(),
                        ]),
                        'stages' => ['discovery' => ['candidates' => 1]],
                    ];
                });
        });

        $guard = app(DecisionPipelineScheduleService::class);

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertFailed();
        $this->assertFalse($guard->hasAutomaticRunToday());
        $this->assertSame(1, $runCounts[$profiles[0]->id]);
        $this->assertSame(1, $runCounts[$profiles[1]->id]);
        $this->assertSame(1, $runCounts[$profiles[2]->id]);

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful();
        $this->assertTrue($guard->hasAutomaticRunToday());
        $this->assertSame('scheduled', $guard->lastAutomaticTrigger());
        $this->assertSame(1, $runCounts[$profiles[0]->id], 'Profile A must not rerun on automatic retry');
        $this->assertSame(1, $runCounts[$profiles[1]->id], 'Profile B must not rerun on automatic retry');
        $this->assertSame(2, $runCounts[$profiles[2]->id]);
    }

    public function test_automatic_retry_regenerates_recommendations_by_cancelling_stale_open_rows(): void
    {
        $user = User::query()->create([
            'name' => 'Retry User',
            'email' => 'retry-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'R'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Retry Test Stock',
            'is_active' => true,
        ]);

        $first = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 80,
            'strategy_score' => 90,
            'confidence' => 0.9,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'reservation_status' => TradingRecommendation::RESERVATION_NONE,
            'version' => 4,
            'generated_at' => now()->subHour(),
        ]);

        $this->mock(DailyDecisionPipeline::class, function ($mock) use ($profile): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturnUsing(function () use ($profile) {
                    TradingRecommendation::query()
                        ->forProfile($profile)
                        ->staleOpen()
                        ->update(['status' => TradingRecommendation::STATUS_CANCELLED]);

                    return [
                        'pipeline_run' => PipelineRun::query()->create([
                            'profile_id' => $profile->id,
                            'status' => 'completed',
                            'started_at' => now(),
                            'completed_at' => now(),
                        ]),
                        'stages' => ['recommendation' => ['count' => 1]],
                    ];
                });
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful();

        $first->refresh();
        $this->assertSame(TradingRecommendation::STATUS_CANCELLED, $first->status);
    }

    public function test_pending_execution_recommendations_are_not_cancelled_by_stale_open_scope(): void
    {
        $user = User::query()->create([
            'name' => 'Pending Exec User',
            'email' => 'pending-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'P'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Pending Exec Stock',
            'is_active' => true,
        ]);

        $pendingExecution = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
            'priority' => 80,
            'strategy_score' => 90,
            'confidence' => 0.9,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'reservation_status' => TradingRecommendation::RESERVATION_RESERVED,
            'reserved_amount' => 1000,
            'version' => 4,
            'generated_at' => now()->subHour(),
        ]);

        TradingRecommendation::query()
            ->forProfile($profile)
            ->staleOpen()
            ->update(['status' => TradingRecommendation::STATUS_CANCELLED]);

        $pendingExecution->refresh();
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $pendingExecution->status);
        $this->assertSame(TradingRecommendation::RESERVATION_RESERVED, $pendingExecution->reservation_status);
    }

    public function test_second_automatic_trigger_is_skipped_after_successful_mark(): void
    {
        app(DecisionPipelineScheduleService::class)->markAutomaticRunToday('post-sync');

        $this->mock(DailyDecisionPipeline::class, function ($mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful()
            ->expectsOutputToContain('already completed automatically today');
    }

    public function test_automatic_guard_does_not_block_concurrent_starts_before_mark(): void
    {
        $guard = app(DecisionPipelineScheduleService::class);

        $this->assertFalse($guard->shouldSkipAutomatic('scheduled'));
        $this->assertFalse($guard->shouldSkipAutomatic('post-sync'));
        $this->assertFalse($guard->hasAutomaticRunToday());
    }

    public function test_concurrent_automatic_triggers_are_blocked_by_shared_execution_lock(): void
    {
        $held = Cache::lock(DecisionPipelineScheduleService::AUTOMATIC_LOCK_KEY, 60);
        $this->assertTrue($held->get());

        $this->mock(DailyDecisionPipeline::class, function ($mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'post-sync'])
            ->assertSuccessful()
            ->expectsOutputToContain('another automatic execution is in progress');

        $held->release();
    }

    /**
     * @return \Illuminate\Support\Collection<int, PortfolioProfile>
     */
    protected function seedProfiles(int $count)
    {
        $profiles = collect();
        for ($i = 0; $i < $count; $i++) {
            $user = User::query()->create([
                'name' => "Profile User {$i}",
                'email' => 'profile-'.$i.'-'.Str::random(6).'@example.com',
                'password' => 'password123',
            ]);
            $profiles->push($this->defaultPortfolioFor($user));
        }

        return $profiles;
    }
}
