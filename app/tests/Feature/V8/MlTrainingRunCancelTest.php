<?php

namespace Tests\Feature\V8;

use App\Models\User;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlTrainingRunCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlTrainingRunCancelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_cancel_queued_run_immediately(): void
    {
        $admin = User::factory()->admin()->create();
        $run = MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'queued',
            'cutoff_date' => now()->toDateString(),
            'configuration' => ['trigger' => 'manual'],
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/ml/runs/{$run->id}/cancel", ['reason' => 'mistake'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $run->refresh();
        $this->assertSame('cancelled', $run->status);
        $this->assertNotNull($run->completed_at);
    }

    public function test_running_run_moves_to_cancelling_until_checkpoint(): void
    {
        $admin = User::factory()->admin()->create();
        $run = MlTrainingRun::query()->create([
            'horizon' => '1m',
            'status' => 'running',
            'cutoff_date' => now()->toDateString(),
            'configuration' => ['trigger' => 'scheduled', 'progress' => ['percent' => 40]],
            'started_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/ml/runs/{$run->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelling');

        $run->refresh();
        $this->assertSame('cancelling', $run->status);

        try {
            app(MlTrainingRunCancellationService::class)->assertContinueOrAbort($run);
            $this->fail('Expected cancellation abort');
        } catch (\App\Exceptions\MlTrainingRunCancelledException) {
            // expected
        }

        $run->refresh();
        $this->assertSame('cancelled', $run->status);
    }
}
