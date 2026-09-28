<?php

namespace Tests\Feature\V8;

use App\Models\User;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlPromotionReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_fetch_promotion_review_for_candidate(): void
    {
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);
        $run = MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'completed',
            'cutoff_date' => '2026-06-01',
            'configuration' => [],
            'metrics' => [],
            'baselines' => [],
            'selected_features' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $model = MlModelVersion::query()->create([
            'training_run_id' => $run->id,
            'horizon' => '3m',
            'version' => 2,
            'status' => 'candidate',
            'model_family' => 'interpretable_logistic_baseline',
            'training_cutoff_date' => '2026-06-01',
            'feature_set' => ['momentum_score'],
            'preprocessing' => [],
            'label_definition' => [],
            'benchmark_mapping' => [],
            'hyperparameters' => [],
            'evaluation_metrics' => [
                'roc_auc' => 0.62,
                'pr_auc' => 0.58,
                'benchmark_relative_return' => 0.02,
                'deterministic_baseline_delta' => 0.01,
                'calibration' => [
                    'method' => 'platt_sigmoid',
                    'calibrated_brier' => 0.19,
                    'uncalibrated_brier' => 0.21,
                ],
            ],
            'promotion_thresholds' => [
                'min_roc_auc' => 0.52,
                'min_pr_auc' => 0.5,
                'min_benchmark_relative_return' => 0.0,
                'min_deterministic_baseline_delta' => 0.0,
            ],
            'artifact_path' => null,
            'artifact_sha256' => null,
            'audit_metadata' => [],
        ]);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson("/api/v1/admin/ml/models/{$model->id}/promotion-review")
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.checks.roc_auc.met', true)
            ->assertJsonPath('data.model.version', 2)
            ->assertJsonPath('data.calibration.method', 'platt_sigmoid');
    }

    public function test_promotion_review_includes_challenger_sibling_from_same_run(): void
    {
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);
        $run = MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'completed',
            'cutoff_date' => '2026-06-01',
            'configuration' => [],
            'metrics' => [],
            'baselines' => [],
            'selected_features' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $logistic = MlModelVersion::query()->create([
            'training_run_id' => $run->id,
            'horizon' => '3m',
            'version' => 1,
            'status' => 'candidate',
            'model_family' => 'interpretable_logistic_baseline',
            'training_cutoff_date' => '2026-06-01',
            'feature_set' => [],
            'preprocessing' => [],
            'label_definition' => [],
            'benchmark_mapping' => [],
            'hyperparameters' => [],
            'evaluation_metrics' => ['roc_auc' => 0.6],
            'promotion_thresholds' => ['min_roc_auc' => 0.52, 'min_pr_auc' => 0.5, 'min_benchmark_relative_return' => 0, 'min_deterministic_baseline_delta' => 0],
            'artifact_path' => '/tmp/logistic.joblib',
            'artifact_sha256' => 'abc',
            'audit_metadata' => [],
        ]);
        $challenger = MlModelVersion::query()->create([
            'training_run_id' => $run->id,
            'horizon' => '3m',
            'version' => 2,
            'status' => 'candidate',
            'model_family' => 'hist_gradient_boosting_challenger',
            'training_cutoff_date' => '2026-06-01',
            'feature_set' => [],
            'preprocessing' => [],
            'label_definition' => [],
            'benchmark_mapping' => [],
            'hyperparameters' => [],
            'evaluation_metrics' => [
                'roc_auc' => 0.74,
                'pr_auc' => 0.7,
                'benchmark_relative_return' => 0.03,
                'deterministic_baseline_delta' => 0.02,
            ],
            'promotion_thresholds' => ['min_roc_auc' => 0.52, 'min_pr_auc' => 0.5, 'min_benchmark_relative_return' => 0, 'min_deterministic_baseline_delta' => 0],
            'artifact_path' => '/tmp/challenger.joblib',
            'artifact_sha256' => 'def',
            'audit_metadata' => [],
        ]);

        $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson("/api/v1/admin/ml/models/{$logistic->id}/promotion-review")
            ->assertOk()
            ->assertJsonPath('data.challenger_sibling.id', $challenger->id)
            ->assertJsonPath('data.challenger_sibling.eligible', true);
    }
}
