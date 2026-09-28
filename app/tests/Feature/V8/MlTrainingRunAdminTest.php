<?php

namespace Tests\Feature\V8;

use App\Models\User;
use App\Models\V7\MlTrainingRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlTrainingRunAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lists_training_runs_with_progress(): void
    {
        config(['ml_lifecycle.sse.max_polls' => 1, 'ml_lifecycle.sse.poll_interval_seconds' => 0]);

        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $run = MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'completed',
            'cutoff_date' => '2026-06-01',
            'configuration' => [
                'trigger' => 'drift',
                'progress' => ['stage' => 'completed', 'percent' => 100],
                'challenger_evidence' => [
                    'challenger_auc' => 0.62,
                    'baseline_auc' => 0.58,
                    'challenger_selected' => true,
                ],
                'chronological_validation_grid' => [
                    'version' => 1,
                    'test_window_count' => 4,
                    'test_positive_rate_stability' => 0.91,
                ],
            ],
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson('/api/v1/admin/ml/runs?horizon=3m')
            ->assertOk()
            ->assertJsonPath('data.runs.0.id', $run->id)
            ->assertJsonPath('data.runs.0.progress.percent', 100);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson("/api/v1/admin/ml/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.trigger', 'drift')
            ->assertJsonPath('data.challenger_evidence.challenger_auc', 0.62)
            ->assertJsonPath('data.chronological_validation_grid.test_window_count', 4);

        $stream = $this->actingAs($admin)->withProfileHeader($admin)
            ->get("/api/v1/admin/ml/runs/{$run->id}/stream");

        $stream->assertOk();
        $this->assertStringContainsString('event: progress', $stream->streamedContent());
        $this->assertStringContainsString('"status":"completed"', $stream->streamedContent());
    }
}
