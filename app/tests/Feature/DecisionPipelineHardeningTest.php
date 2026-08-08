<?php

namespace Tests\Feature;

use App\Engines\Pipeline\DailyDecisionPipeline;
use App\Models\PipelineRun;
use App\Models\PortfolioProfile;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\TosNotification;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Services\DecisionPipelineScheduleService;
use App\Support\TradingOsConfig;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * F148/F149 operational hardening: partial-failure retry (H1) and automatic lock (H2).
 */
class DecisionPipelineHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-08 19:00:00', 'Asia/Kolkata'));
        config([TradingOsConfig::KEY_ENABLED => true]);
    }

    protected function tearDown(): void
    {
        Cache::lock(DecisionPipelineScheduleService::AUTOMATIC_LOCK_KEY)->forceRelease();
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_all_profiles_succeed_marks_each_profile_and_global_guard(): void
    {
        $profiles = $this->seedProfiles(2);
        $guard = app(DecisionPipelineScheduleService::class);

        $this->mockPipelineSuccessForProfiles($profiles);

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful();

        foreach ($profiles as $profile) {
            $this->assertTrue($guard->hasProfileSucceededToday($profile->id));
        }
        $this->assertTrue($guard->hasAutomaticRunToday());
    }

    public function test_partial_failure_marks_successful_profiles_but_not_global_guard(): void
    {
        $profiles = $this->seedProfiles(3);
        $guard = app(DecisionPipelineScheduleService::class);
        $failingProfileId = $profiles->last()->id;

        $this->mockPartialFailurePipeline($profiles, $failingProfileId);

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertFailed();

        $this->assertTrue($guard->hasProfileSucceededToday($profiles[0]->id));
        $this->assertTrue($guard->hasProfileSucceededToday($profiles[1]->id));
        $this->assertFalse($guard->hasProfileSucceededToday($failingProfileId));
        $this->assertFalse($guard->hasAutomaticRunToday());
    }

    public function test_automatic_retry_skips_successful_profiles_and_retries_failed_only(): void
    {
        $profiles = $this->seedProfiles(3);
        $guard = app(DecisionPipelineScheduleService::class);
        $failingProfileId = $profiles->last()->id;
        $runCounts = array_fill_keys($profiles->pluck('id')->all(), 0);

        $this->mock(DailyDecisionPipeline::class, function ($mock) use ($profiles, $failingProfileId, &$runCounts): void {
            $mock->shouldReceive('run')
                ->times(4)
                ->with(
                    Mockery::type(PortfolioProfile::class),
                    Mockery::on(fn (array $opts) => ($opts['trigger'] ?? null) === 'scheduled'),
                )
                ->andReturnUsing(function (PortfolioProfile $profile) use ($failingProfileId, &$runCounts) {
                    $runCounts[$profile->id]++;
                    if ($profile->id === $failingProfileId && $runCounts[$profile->id] === 1) {
                        throw new RuntimeException('profile C first-attempt failure');
                    }

                    return $this->pipelineSuccessResult($profile);
                });
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertFailed();
        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped profile #'.$profiles[0]->id)
            ->expectsOutputToContain('Skipped profile #'.$profiles[1]->id);

        $this->assertSame(1, $runCounts[$profiles[0]->id]);
        $this->assertSame(1, $runCounts[$profiles[1]->id]);
        $this->assertSame(2, $runCounts[$failingProfileId]);
        $this->assertTrue($guard->hasAutomaticRunToday());
    }

    public function test_failed_profile_remains_eligible_for_automatic_retry(): void
    {
        $profiles = $this->seedProfiles(1);
        $profile = $profiles->first();
        $guard = app(DecisionPipelineScheduleService::class);

        $this->mock(DailyDecisionPipeline::class, function ($mock) use ($profile): void {
            $mock->shouldReceive('run')
                ->once()
                ->andThrow(new RuntimeException('still failing'));
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'post-sync'])
            ->assertFailed();

        $this->assertFalse($guard->hasProfileSucceededToday($profile->id));
        $this->assertFalse($guard->hasAutomaticRunToday());
    }

    public function test_manual_run_ignores_automatic_successful_profile_markers(): void
    {
        $profiles = $this->seedProfiles(1);
        $profile = $profiles->first();
        app(DecisionPipelineScheduleService::class)->markProfileSuccessfulToday($profile->id);

        $this->mock(DailyDecisionPipeline::class, function ($mock) use ($profile): void {
            $mock->shouldReceive('run')
                ->once()
                ->with(
                    Mockery::on(fn ($p) => $p instanceof PortfolioProfile && $p->id === $profile->id),
                    Mockery::on(fn (array $opts) => ($opts['trigger'] ?? null) === 'manual'),
                )
                ->andReturn($this->pipelineSuccessResult($profile));
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'manual'])
            ->assertSuccessful();
    }

    public function test_successful_profile_markers_reset_on_next_calendar_day(): void
    {
        $profiles = $this->seedProfiles(1);
        $profile = $profiles->first();
        $guard = app(DecisionPipelineScheduleService::class);

        $guard->markProfileSuccessfulToday($profile->id);
        $this->assertTrue($guard->hasProfileSucceededToday($profile->id));

        Carbon::setTestNow(Carbon::parse('2026-08-09 19:00:00', 'Asia/Kolkata'));

        $this->assertFalse($guard->hasProfileSucceededToday($profile->id));
        $this->assertSame([], $guard->successfulProfileIdsToday());
    }

    public function test_automatic_retry_does_not_create_duplicate_pipeline_runs_for_skipped_profiles(): void
    {
        $profiles = $this->seedProfiles(2);
        $guard = app(DecisionPipelineScheduleService::class);
        $guard->markProfileSuccessfulToday($profiles[0]->id);

        $beforeRuns = PipelineRun::query()->count();

        $this->mock(DailyDecisionPipeline::class, function ($mock) use ($profiles): void {
            $mock->shouldReceive('run')
                ->once()
                ->with(
                    Mockery::on(fn ($p) => $p instanceof PortfolioProfile && $p->id === $profiles[1]->id),
                    Mockery::on(fn (array $opts) => ($opts['trigger'] ?? null) === 'scheduled'),
                )
                ->andReturn($this->pipelineSuccessResult($profiles[1]));
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful();

        $this->assertSame($beforeRuns + 1, PipelineRun::query()->count());
    }

    public function test_automatic_retry_does_not_create_notifications_for_skipped_profiles(): void
    {
        $profiles = $this->seedProfiles(2);
        $guard = app(DecisionPipelineScheduleService::class);
        $guard->markProfileSuccessfulToday($profiles[0]->id);

        $beforeNotifications = TosNotification::query()->count();

        $this->mock(DailyDecisionPipeline::class, function ($mock) use ($profiles): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn($this->pipelineSuccessResult($profiles[1]));
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful();

        $this->assertSame($beforeNotifications, TosNotification::query()->count());
    }

    public function test_scheduled_trigger_acquires_automatic_lock(): void
    {
        $profiles = $this->seedProfiles(1);
        $held = Cache::lock(DecisionPipelineScheduleService::AUTOMATIC_LOCK_KEY, 60);
        $this->assertTrue($held->get());

        $this->mock(DailyDecisionPipeline::class, function ($mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful()
            ->expectsOutputToContain('another automatic execution is in progress');

        $held->release();
    }

    public function test_post_sync_cannot_execute_while_scheduled_lock_held(): void
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

    public function test_scheduled_cannot_execute_while_post_sync_lock_held(): void
    {
        $held = Cache::lock(DecisionPipelineScheduleService::AUTOMATIC_LOCK_KEY, 60);
        $this->assertTrue($held->get());

        $this->mock(DailyDecisionPipeline::class, function ($mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful()
            ->expectsOutputToContain('another automatic execution is in progress');

        $held->release();
    }

    public function test_lock_contention_is_a_safe_skip_not_command_failure(): void
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

    public function test_lock_is_released_after_successful_completion(): void
    {
        $profiles = $this->seedProfiles(1);
        $this->mockPipelineSuccessForProfiles($profiles);

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful();

        $lock = Cache::lock(DecisionPipelineScheduleService::AUTOMATIC_LOCK_KEY, 60);
        $this->assertTrue($lock->get(), 'Lock must be released after successful completion');
        $lock->release();
    }

    public function test_lock_is_released_after_partial_failure(): void
    {
        $profiles = $this->seedProfiles(2);
        $failingProfileId = $profiles->last()->id;
        $this->mockPartialFailurePipeline($profiles, $failingProfileId);

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertFailed();

        $lock = Cache::lock(DecisionPipelineScheduleService::AUTOMATIC_LOCK_KEY, 60);
        $this->assertTrue($lock->get(), 'Lock must be released after partial failure');
        $lock->release();
    }

    public function test_lock_is_released_after_unexpected_exception(): void
    {
        $profiles = $this->seedProfiles(1);

        $this->mock(DailyDecisionPipeline::class, function ($mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andThrow(new RuntimeException('unexpected pipeline crash'));
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertFailed();

        $lock = Cache::lock(DecisionPipelineScheduleService::AUTOMATIC_LOCK_KEY, 60);
        $this->assertTrue($lock->get(), 'Lock must be released after unexpected exception');
        $lock->release();
    }

    public function test_manual_execution_is_not_blocked_by_automatic_lock(): void
    {
        $profiles = $this->seedProfiles(1);
        $profile = $profiles->first();
        $held = Cache::lock(DecisionPipelineScheduleService::AUTOMATIC_LOCK_KEY, 60);
        $this->assertTrue($held->get());

        $this->mock(DailyDecisionPipeline::class, function ($mock) use ($profile): void {
            $mock->shouldReceive('run')
                ->once()
                ->with(
                    Mockery::on(fn ($p) => $p instanceof PortfolioProfile && $p->id === $profile->id),
                    Mockery::on(fn (array $opts) => ($opts['trigger'] ?? null) === 'manual'),
                )
                ->andReturn($this->pipelineSuccessResult($profile));
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'manual'])
            ->assertSuccessful();

        $held->release();
    }

    public function test_automatic_lock_key_is_shared_across_triggers(): void
    {
        $this->assertSame(
            DecisionPipelineScheduleService::AUTOMATIC_LOCK_KEY,
            'trading-os:decision-pipeline:automatic',
        );
    }

    public function test_once_per_day_guard_still_works_after_lock_release(): void
    {
        $profiles = $this->seedProfiles(1);
        $guard = app(DecisionPipelineScheduleService::class);
        $this->mockPipelineSuccessForProfiles($profiles);

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful();
        $this->assertTrue($guard->hasAutomaticRunToday());

        $this->mock(DailyDecisionPipeline::class, function ($mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'post-sync'])
            ->assertSuccessful()
            ->expectsOutputToContain('already completed automatically today');
    }

    public function test_combined_partial_failure_retry_and_concurrent_lock(): void
    {
        $profiles = $this->seedProfiles(3);
        $guard = app(DecisionPipelineScheduleService::class);
        $failingProfileId = $profiles->last()->id;
        $runCounts = array_fill_keys($profiles->pluck('id')->all(), 0);

        $this->mock(DailyDecisionPipeline::class, function ($mock) use ($failingProfileId, &$runCounts): void {
            $mock->shouldReceive('run')
                ->times(3)
                ->andReturnUsing(function (PortfolioProfile $profile) use ($failingProfileId, &$runCounts) {
                    $runCounts[$profile->id]++;
                    if ($profile->id === $failingProfileId) {
                        throw new RuntimeException('profile C first-attempt failure');
                    }

                    return $this->pipelineSuccessResult($profile);
                });
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertFailed();

        $held = Cache::lock(DecisionPipelineScheduleService::AUTOMATIC_LOCK_KEY, 60);
        $this->assertTrue($held->get(), 'Simulate automatic run 2 in progress');

        $this->mock(DailyDecisionPipeline::class, function ($mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'post-sync'])
            ->assertSuccessful()
            ->expectsOutputToContain('another automatic execution is in progress');

        $held->release();

        $this->mock(DailyDecisionPipeline::class, function ($mock) use ($failingProfileId, &$runCounts): void {
            $mock->shouldReceive('run')
                ->once()
                ->with(
                    Mockery::on(fn ($p) => $p instanceof PortfolioProfile && $p->id === $failingProfileId),
                    Mockery::on(fn (array $opts) => ($opts['trigger'] ?? null) === 'scheduled'),
                )
                ->andReturnUsing(function (PortfolioProfile $profile) use (&$runCounts) {
                    $runCounts[$profile->id]++;

                    return $this->pipelineSuccessResult($profile);
                });
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped profile #'.$profiles[0]->id);

        $this->assertSame(1, $runCounts[$profiles[0]->id]);
        $this->assertSame(1, $runCounts[$profiles[1]->id]);
        $this->assertSame(2, $runCounts[$failingProfileId]);
        $this->assertTrue($guard->hasAutomaticRunToday());
        $this->assertSame(
            '2026-08-08',
            Setting::getValue(DecisionPipelineScheduleService::KEY_AUTO_DATE),
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PortfolioProfile>  $profiles
     */
    protected function mockPipelineSuccessForProfiles($profiles): void
    {
        $this->mock(DailyDecisionPipeline::class, function ($mock) use ($profiles): void {
            foreach ($profiles as $profile) {
                $mock->shouldReceive('run')
                    ->once()
                    ->with(
                        Mockery::on(fn ($p) => $p instanceof PortfolioProfile && $p->id === $profile->id),
                        Mockery::type('array'),
                    )
                    ->andReturn($this->pipelineSuccessResult($profile));
            }
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PortfolioProfile>  $profiles
     */
    protected function mockPartialFailurePipeline($profiles, int $failingProfileId): void
    {
        $this->mock(DailyDecisionPipeline::class, function ($mock) use ($profiles, $failingProfileId): void {
            foreach ($profiles as $profile) {
                $expectation = $mock->shouldReceive('run')
                    ->once()
                    ->with(
                        Mockery::on(fn ($p) => $p instanceof PortfolioProfile && $p->id === $profile->id),
                        Mockery::type('array'),
                    );

                if ($profile->id === $failingProfileId) {
                    $expectation->andThrow(new RuntimeException('profile failure'));
                } else {
                    $expectation->andReturn($this->pipelineSuccessResult($profile));
                }
            }
        });
    }

    /**
     * @return array{pipeline_run: PipelineRun, stages: array<string, mixed>}
     */
    protected function pipelineSuccessResult(PortfolioProfile $profile): array
    {
        return [
            'pipeline_run' => PipelineRun::query()->create([
                'profile_id' => $profile->id,
                'status' => 'completed',
                'started_at' => now(),
                'completed_at' => now(),
            ]),
            'stages' => [
                'discovery' => ['candidates' => 1],
                'evaluation' => ['results' => 1],
                'recommendation' => ['count' => 1],
            ],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, PortfolioProfile>
     */
    protected function seedProfiles(int $count)
    {
        $profiles = collect();
        for ($i = 0; $i < $count; $i++) {
            $user = User::query()->create([
                'name' => "Hardening User {$i}",
                'email' => 'hard-'.$i.'-'.Str::random(6).'@example.com',
                'password' => 'password123',
            ]);
            $profiles->push($this->defaultPortfolioFor($user));
        }

        return $profiles;
    }
}
