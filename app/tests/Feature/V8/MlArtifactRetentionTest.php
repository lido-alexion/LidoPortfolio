<?php

namespace Tests\Feature\V8;

use App\Models\User;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlArtifactRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MlArtifactRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_oldest_retained_models_beyond_cap(): void
    {
        config([
            'ml_lifecycle.retention.enabled' => true,
            'ml_lifecycle.retention.max_retained_per_horizon' => 2,
        ]);

        $run = MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'completed',
            'cutoff_date' => '2026-01-01',
            'configuration' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $paths = [];
        foreach ([1, 2, 3] as $version) {
            $path = storage_path('framework/testing/ml-model-3m-v'.$version.'.joblib');
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'artifact-'.$version);
            $paths[] = $path;
            MlModelVersion::query()->create([
                'training_run_id' => $run->id,
                'horizon' => '3m',
                'version' => $version,
                'status' => 'retained',
                'model_family' => 'interpretable_logistic_baseline',
                'training_cutoff_date' => '2026-01-01',
                'feature_set' => [],
                'preprocessing' => [],
                'label_definition' => [],
                'benchmark_mapping' => [],
                'hyperparameters' => [],
                'evaluation_metrics' => [],
                'promotion_thresholds' => [],
                'artifact_path' => $path,
                'artifact_sha256' => hash('sha256', 'artifact-'.$version),
                'audit_metadata' => [],
            ]);
        }

        $pruned = app(MlArtifactRetentionService::class)->pruneHorizon('3m', dryRun: false);

        $this->assertCount(1, $pruned);
        $this->assertSame('pruned', MlModelVersion::query()->where('version', 1)->value('status'));
        $this->assertFileDoesNotExist($paths[0]);
        $this->assertSame('retained', MlModelVersion::query()->where('version', 3)->value('status'));

        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function test_admin_can_preview_retention_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson('/api/v1/admin/ml/retention-plan')
            ->assertOk()
            ->assertJsonStructure(['data' => ['enabled', 'max_retained_per_horizon', 'horizons']]);
    }
}
