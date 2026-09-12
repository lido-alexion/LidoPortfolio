<?php

namespace Tests\Feature\V7;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlScoringLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_retrains_promotes_and_persists_predictions(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $this->defaultPortfolioFor($admin);
        $this->defaultPortfolioFor($member);
        $stock = Stock::query()->create(['symbol' => 'INFY', 'exchange' => 'NSE', 'name' => 'Infosys']);

        $modelId = $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson('/api/v1/admin/ml/retrain', ['horizon' => '3m', 'cutoff_date' => '2026-09-12'])
            ->assertCreated()
            ->assertJsonPath('data.model.horizon', '3m')
            ->json('data.model.id');

        $this->assertSame('candidate', MlModelVersion::query()->findOrFail($modelId)->status);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson("/api/v1/admin/ml/models/{$modelId}/promote")
            ->assertOk()
            ->assertJsonPath('data.model.status', 'active');

        $this->actingAs($member)->withProfileHeader($member)
            ->postJson("/api/v1/stocks/{$stock->id}/ml-predictions", ['horizon' => '3m', 'as_of' => '2026-09-12'])
            ->assertOk()
            ->assertJsonPath('data.prediction.horizon', '3m')
            ->assertJsonPath('data.prediction.shadow', false);
    }

    public function test_admin_cannot_promote_candidate_that_misses_thresholds(): void
    {
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);
        $run = MlTrainingRun::query()->create([
            'horizon' => '1m',
            'status' => 'completed',
            'cutoff_date' => '2026-09-12',
            'configuration' => [],
            'metrics' => [],
            'baselines' => [],
            'selected_features' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $model = MlModelVersion::query()->create([
            'training_run_id' => $run->id,
            'horizon' => '1m',
            'version' => 1,
            'status' => 'candidate',
            'model_family' => 'interpretable_logistic_baseline',
            'training_cutoff_date' => '2026-09-12',
            'feature_set' => [],
            'preprocessing' => [],
            'label_definition' => [],
            'benchmark_mapping' => [],
            'hyperparameters' => [],
            'evaluation_metrics' => ['roc_auc' => 0.51, 'pr_auc' => 0.49, 'benchmark_relative_return' => -0.01, 'deterministic_baseline_delta' => -0.01],
            'promotion_thresholds' => ['min_roc_auc' => 0.52, 'min_pr_auc' => 0.5, 'min_benchmark_relative_return' => 0.0, 'min_deterministic_baseline_delta' => 0.0],
        ]);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson("/api/v1/admin/ml/models/{$model->id}/promote")
            ->assertUnprocessable()
            ->assertJsonPath('errors.model.0', 'This model does not meet its promotion thresholds.');

        $this->assertSame('candidate', $model->fresh()->status);
    }

    public function test_admin_can_rollback_to_retained_model_version(): void
    {
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $firstId = $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson('/api/v1/admin/ml/retrain', ['horizon' => '6m', 'cutoff_date' => '2026-08-01'])
            ->assertCreated()
            ->json('data.model.id');
        $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson("/api/v1/admin/ml/models/{$firstId}/promote")
            ->assertOk();

        $secondId = $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson('/api/v1/admin/ml/retrain', ['horizon' => '6m', 'cutoff_date' => '2026-09-12'])
            ->assertCreated()
            ->json('data.model.id');
        $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson("/api/v1/admin/ml/models/{$secondId}/promote")
            ->assertOk();

        $this->assertSame('retained', MlModelVersion::query()->findOrFail($firstId)->status);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson('/api/v1/admin/ml/rollback', ['horizon' => '6m', 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.model.status', 'active')
            ->assertJsonPath('data.model.version', 1);

        $this->assertSame('retained', MlModelVersion::query()->findOrFail($secondId)->status);
    }
}
