<?php

namespace Tests\Feature\V8;

use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlCandidateEvidenceService;
use App\Services\ML\MlExplainabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlCandidateEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_evidence_persists_same_window_active_and_baseline_comparisons(): void
    {
        $run = MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'completed',
            'cutoff_date' => '2026-06-01',
            'configuration' => ['dataset' => ['version' => 'dataset-3m-v1']],
            'metrics' => [],
            'baselines' => [
                'deterministic_stox' => [
                    'roc_auc' => 0.55,
                    'pr_auc' => 0.5,
                    'hit_rate' => 0.51,
                ],
            ],
            'selected_features' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $split = ['split_basis' => 'chronological-v8', 'test_start' => '2026-01-01', 'test_end' => '2026-06-01'];
        $active = MlModelVersion::query()->create($this->modelAttributes($run, 1, 'active', [
            'roc_auc' => 0.60,
            'pr_auc' => 0.56,
            'hit_rate' => 0.54,
        ], $split));
        $candidate = MlModelVersion::query()->create($this->modelAttributes($run, 2, 'candidate', [
            'roc_auc' => 0.66,
            'pr_auc' => 0.61,
            'hit_rate' => 0.58,
            'calibration' => ['method' => 'platt', 'brier' => 0.18],
            'class_distribution' => ['rows' => 120],
        ], $split));

        $evidence = app(MlCandidateEvidenceService::class)->persist($candidate);

        $this->assertSame('v8-candidate-evidence-1', $evidence['contract_version']);
        $this->assertSame($active->id, $evidence['active_model']['model_id']);
        $this->assertTrue($evidence['active_model']['comparable']);
        $this->assertSame(0.06, $evidence['active_model']['delta']['roc_auc']);
        $this->assertSame(0.11, $evidence['deterministic_baseline']['delta']['roc_auc']);
        $this->assertSame(120, $evidence['sample_count']);
        $this->assertSame('dataset-3m-v1', $evidence['dataset_version']);
        $this->assertSame($evidence, $candidate->fresh()->audit_metadata['candidate_evidence']);
    }

    public function test_no_active_model_is_unavailable_not_candidate_superiority(): void
    {
        $run = MlTrainingRun::query()->create([
            'horizon' => '1m',
            'status' => 'completed',
            'cutoff_date' => '2026-06-01',
            'configuration' => [],
            'metrics' => [],
            'baselines' => [],
            'selected_features' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $candidate = MlModelVersion::query()->create($this->modelAttributes($run, 1, 'candidate', ['roc_auc' => 0.6], []));

        $evidence = app(MlCandidateEvidenceService::class)->build($candidate);

        $this->assertFalse($evidence['active_model']['comparable']);
        $this->assertSame('no_active_model_for_horizon', $evidence['active_model']['unavailable_reason']);
        $this->assertFalse($evidence['deterministic_baseline']['comparable']);
    }

    public function test_explainability_uses_pinned_feature_metadata_and_excludes_dropped_inputs(): void
    {
        $model = new MlModelVersion([
            'id' => 42,
            'version' => 7,
            'horizon' => '6m',
            'feature_set' => ['momentum_score', 'sector_relative_roe'],
            'audit_metadata' => [
                'effective_feature_set' => ['momentum_score'],
                'feature_profile' => [
                    'feature_set_version' => 'profile-6m-v2',
                    'feature_metadata' => [
                        'momentum_score' => ['label' => 'Momentum score', 'formula_version' => 'v8-formula-3'],
                    ],
                ],
            ],
        ]);

        $result = app(MlExplainabilityService::class)->explain($model, [
            ['feature' => 'momentum_score', 'value' => 1.2, 'contribution' => 0.31],
            ['feature' => 'sector_relative_roe', 'value' => 0.2, 'contribution' => -0.4],
            ['feature' => 'future_leak', 'value' => 99, 'contribution' => 5],
        ]);

        $this->assertSame('profile-6m-v2', $result['feature_set_version']);
        $this->assertCount(1, $result['drivers']);
        $this->assertSame('Momentum score', $result['drivers'][0]['label']);
        $this->assertSame('v8-formula-3', $result['drivers'][0]['feature_version']);
        $this->assertSame('6m', $result['drivers'][0]['horizon']);
    }

    /** @return array<string,mixed> */
    private function modelAttributes(MlTrainingRun $run, int $version, string $status, array $metrics, array $split): array
    {
        return [
            'training_run_id' => $run->id,
            'horizon' => $run->horizon,
            'version' => $version,
            'status' => $status,
            'model_family' => 'interpretable_logistic_baseline',
            'training_cutoff_date' => '2026-06-01',
            'feature_set' => ['momentum_score'],
            'preprocessing' => ['state_id' => 'prep-v1'],
            'label_definition' => [],
            'benchmark_mapping' => [],
            'hyperparameters' => [],
            'evaluation_metrics' => $metrics,
            'promotion_thresholds' => [],
            'artifact_path' => '/tmp/model.joblib',
            'artifact_sha256' => 'sha256',
            'audit_metadata' => [
                'feature_profile' => ['feature_set_version' => 'profile-'.$run->horizon.'-v1'],
                'chronological_split' => $split,
                'dataset' => ['version' => 'dataset-'.$run->horizon.'-v1'],
                'deterministic_baseline' => ['definition_version' => 'stox-baseline-v1'],
            ],
        ];
    }
}
