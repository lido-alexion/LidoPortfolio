<?php

namespace Tests\Feature\V8;

use App\Jobs\MlRetrainJob;
use App\Models\User;
use App\Models\V7\MlTrainingRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MlRetrainQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_queue_background_retrain(): void
    {
        Bus::fake();

        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson('/api/v1/admin/ml/retrain-queue', ['horizon' => '3m'])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true);

        $queued = MlTrainingRun::query()->where('horizon', '3m')->where('status', 'queued')->first();
        $this->assertNotNull($queued);

        Bus::assertDispatched(
            MlRetrainJob::class,
            fn (MlRetrainJob $job) => $job->horizon === '3m'
                && $job->trigger === 'manual'
                && $job->trainingRunId === $queued->id,
        );
    }

    public function test_queue_retrain_rejects_when_run_already_active(): void
    {
        Bus::fake();

        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'running',
            'cutoff_date' => now()->toDateString(),
            'started_at' => now(),
        ]);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson('/api/v1/admin/ml/retrain-queue', ['horizon' => '3m'])
            ->assertStatus(422);

        Bus::assertNothingDispatched();
    }

    public function test_queue_retrain_rejects_unknown_trigger_before_dispatch(): void
    {
        Bus::fake();

        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(\App\Services\ML\MlScoringService::class)
            ->queueRetrainRun('3m', $admin, 'operator-bypass');

        Bus::assertNothingDispatched();
    }

    public function test_horizon_lock_row_is_seeded_for_every_supported_horizon(): void
    {
        $this->assertSame(
            ['1m', '3m', '6m'],
            \App\Models\V7\MlTrainingHorizonLock::query()->orderBy('horizon')->pluck('horizon')->all(),
        );
    }
}
