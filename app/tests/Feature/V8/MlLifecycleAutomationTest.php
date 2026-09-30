<?php

namespace Tests\Feature\V8;

use App\Jobs\MlRetrainJob;
use App\Models\User;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlLifecycleAutomationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MlLifecycleAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests cover behavior after acceptance. Real evidence gates have their own tests.
        $this->mock(\App\Services\ML\MlAcceptanceCampaignService::class)
            ->shouldReceive('readiness')->andReturn(['ready' => true]);
    }


    public function test_tick_queues_job_when_schedule_due_and_no_active_run(): void
    {
        config([
            'ml_lifecycle.enabled' => true,
            'ml_lifecycle.horizons.1m.enabled' => true,
            'ml_lifecycle.horizons.1m.schedule' => 'monthly_first_sunday_02:00',
        ]);

        Bus::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $now = Carbon::parse('2026-03-01 02:00:00', 'Asia/Kolkata');

        $actions = app(MlLifecycleAutomationService::class)->tick($admin, $now);

        $this->assertNotEmpty($actions);
        Bus::assertDispatched(MlRetrainJob::class, fn (MlRetrainJob $job) => $job->horizon === '1m');
    }

    public function test_tick_skips_when_running_retrain_exists(): void
    {
        config([
            'ml_lifecycle.enabled' => true,
            'ml_lifecycle.horizons.1m.enabled' => true,
            'ml_lifecycle.horizons.1m.schedule' => 'monthly_first_sunday_02:00',
        ]);

        MlTrainingRun::query()->create([
            'horizon' => '1m',
            'status' => 'running',
            'cutoff_date' => now()->toDateString(),
            'started_at' => now(),
        ]);

        Bus::fake();
        $now = Carbon::parse('2026-03-01 02:00:00', 'Asia/Kolkata');
        $actions = app(MlLifecycleAutomationService::class)->tick(null, $now);

        $this->assertSame('skipped', $actions[0]['action'] ?? null);
        Bus::assertNothingDispatched();
    }

    public function test_tick_skips_horizon_when_schedule_disabled(): void
    {
        config([
            'ml_lifecycle.enabled' => true,
            'ml_lifecycle.horizons.1m.enabled' => false,
            'ml_lifecycle.horizons.1m.schedule' => 'monthly_first_sunday_02:00',
        ]);

        Bus::fake();
        $now = Carbon::parse('2026-03-01 02:00:00', 'Asia/Kolkata');
        $actions = app(MlLifecycleAutomationService::class)->tick(null, $now);

        $this->assertSame([], $actions);
        Bus::assertNothingDispatched();
    }

    public function test_tick_noop_when_lifecycle_env_disabled(): void
    {
        config([
            'ml_lifecycle.enabled' => false,
            'ml_lifecycle.horizons.1m.enabled' => true,
            'ml_lifecycle.horizons.1m.schedule' => 'monthly_first_sunday_02:00',
        ]);

        Bus::fake();
        $now = Carbon::parse('2026-03-01 02:00:00', 'Asia/Kolkata');
        $actions = app(MlLifecycleAutomationService::class)->tick(null, $now);

        $this->assertSame([], $actions);
        Bus::assertNothingDispatched();
    }

    public function test_admin_ml_dashboard_includes_lifecycle_status(): void
    {
        config([
            'ml_lifecycle.enabled' => true,
            'ml_lifecycle.horizons.3m.enabled' => true,
            'ml_lifecycle.drift_trigger.enabled' => true,
        ]);

        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson('/api/v1/admin/ml')
            ->assertOk()
            ->assertJsonPath('data.lifecycle.enabled', true)
            ->assertJsonPath('data.lifecycle.drift_trigger.enabled', true)
            ->assertJsonFragment(['horizon' => '3m', 'schedule_enabled' => true]);
    }

    public function test_admin_lifecycle_status_reflects_disabled_env(): void
    {
        config([
            'ml_lifecycle.enabled' => false,
            'ml_lifecycle.horizons.1m.enabled' => true,
            'ml_lifecycle.drift_trigger.enabled' => false,
        ]);

        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson('/api/v1/admin/ml')
            ->assertOk()
            ->assertJsonPath('data.lifecycle.enabled', false)
            ->assertJsonPath('data.lifecycle.horizons.0.schedule_enabled', true);
    }

    public function test_admin_can_persist_bounded_schedule_settings_and_tick_uses_them(): void
    {
        config(['ml_lifecycle.enabled' => true]);
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->putJson('/api/v1/admin/ml/schedules/1m', [
                'enabled' => true,
                'schedule' => 'monthly_first_sunday_05:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.schedule.enabled', true)
            ->assertJsonPath('data.schedule.schedule', 'monthly_first_sunday_05:00');

        $service = app(MlLifecycleAutomationService::class);
        $this->assertTrue($service->scheduleDue('1m', Carbon::parse('2026-03-01 05:00:00', 'Asia/Kolkata')));
        $this->assertFalse($service->scheduleDue('1m', Carbon::parse('2026-03-01 02:00:00', 'Asia/Kolkata')));
    }

    public function test_schedule_update_rejects_unbounded_option(): void
    {
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->putJson('/api/v1/admin/ml/schedules/3m', [
                'enabled' => true,
                'schedule' => 'daily_02:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schedule']);
    }

    public function test_investor_cannot_change_ml_lifecycle_schedule(): void
    {
        $investor = User::factory()->create(['is_admin' => false]);
        $this->defaultPortfolioFor($investor);

        $this->actingAs($investor)->withProfileHeader($investor)
            ->putJson('/api/v1/admin/ml/schedules/1m', [
                'enabled' => true,
                'schedule' => 'monthly_first_sunday_02:00',
            ])
            ->assertForbidden();
    }

    public function test_invalid_configured_schedule_fails_closed_to_bounded_default(): void
    {
        config([
            'ml_lifecycle.horizons.6m.enabled' => true,
            'ml_lifecycle.horizons.6m.schedule' => 'daily_23:00',
        ]);

        $settings = app(MlLifecycleAutomationService::class)->scheduleSettings('6m');

        $this->assertSame('monthly_first_sunday_04:00', $settings['schedule']);
        $this->assertFalse(app(MlLifecycleAutomationService::class)->scheduleDue(
            '6m',
            Carbon::parse('2026-03-01 23:00:00', 'Asia/Kolkata'),
        ));
    }

    public function test_admin_status_exposes_next_run_and_durable_active_run_state(): void
    {
        config([
            'ml_lifecycle.enabled' => true,
            'ml_lifecycle.horizons.1m.enabled' => true,
            'ml_lifecycle.horizons.1m.schedule' => 'monthly_first_sunday_02:00',
        ]);
        $run = MlTrainingRun::query()->create([
            'horizon' => '1m',
            'status' => 'running',
            'cutoff_date' => '2026-02-01',
            'configuration' => [
                'trigger' => 'scheduled',
                'retry' => ['attempt' => 2],
                'cancellation' => ['requested' => true],
                'progress' => ['stage' => 'training', 'percent' => 55],
            ],
            'failure' => ['message' => 'temporary worker issue'],
            'started_at' => now(),
        ]);

        $status = app(MlLifecycleAutomationService::class)->adminStatus(Carbon::parse('2026-03-01 01:00:00', 'Asia/Kolkata'));
        $row = collect($status['horizons'])->firstWhere('horizon', '1m');

        $this->assertSame('2026-03-01T02:00:00+05:30', $row['next_scheduled_at']);
        $this->assertSame($run->id, $row['active_run']['id']);
        $this->assertSame(2, $row['active_run']['retry']['attempt']);
        $this->assertTrue($row['active_run']['cancellation']['requested']);
        $this->assertSame('temporary worker issue', $row['active_run']['failure']['message']);
        $this->assertSame('running', $row['latest_run']['status']);
        $this->assertSame('scheduled', $row['latest_run']['trigger']);
    }

    public function test_tick_requeues_stale_running_run_after_worker_restart(): void
    {
        config(['ml_lifecycle.enabled' => true, 'ml_lifecycle.recovery.stale_after_minutes' => 30]);
        $run = MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'running',
            'cutoff_date' => '2026-02-01',
            'configuration' => ['trigger' => 'scheduled'],
            'started_at' => Carbon::parse('2026-03-01 00:00:00', 'Asia/Kolkata'),
        ]);
        $run->forceFill(['updated_at' => Carbon::parse('2026-03-01 00:05:00', 'Asia/Kolkata')])->save();

        Bus::fake();
        $actions = app(MlLifecycleAutomationService::class)->tick(null, Carbon::parse('2026-03-01 01:00:00', 'Asia/Kolkata'));

        $this->assertSame('queued', $run->fresh()->status);
        $this->assertSame('requeued_stale_run', $actions[0]['action'] ?? null);
        Bus::assertDispatched(MlRetrainJob::class, fn (MlRetrainJob $job): bool => $job->trainingRunId === $run->id && $job->horizon === '3m');
    }

    public function test_tick_finalizes_stale_cancellation_without_requeueing_it(): void
    {
        config(['ml_lifecycle.enabled' => true, 'ml_lifecycle.recovery.stale_after_minutes' => 30]);
        $run = MlTrainingRun::query()->create([
            'horizon' => '6m',
            'status' => 'cancelling',
            'cutoff_date' => '2026-02-01',
            'configuration' => ['trigger' => 'manual'],
            'started_at' => Carbon::parse('2026-03-01 00:00:00', 'Asia/Kolkata'),
        ]);
        $run->forceFill(['updated_at' => Carbon::parse('2026-03-01 00:05:00', 'Asia/Kolkata')])->save();

        Bus::fake();
        $actions = app(MlLifecycleAutomationService::class)->tick(null, Carbon::parse('2026-03-01 01:00:00', 'Asia/Kolkata'));

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame('cancelled_stale_run', $actions[0]['action'] ?? null);
        Bus::assertNothingDispatched();
    }
}
