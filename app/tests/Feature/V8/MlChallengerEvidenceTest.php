<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlPythonAdapter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MlChallengerEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $benchmark = Stock::query()->create(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true]);
        $training = Stock::query()->create(['symbol' => 'TRAINING', 'exchange' => 'NSE', 'name' => 'Training Issuer']);
        $trainingTwo = Stock::query()->create(['symbol' => 'TRAINING2', 'exchange' => 'NSE', 'name' => 'Training Issuer 2']);
        $trainingThree = Stock::query()->create(['symbol' => 'TRAINING3', 'exchange' => 'NSE', 'name' => 'Training Issuer 3']);
        for ($day = 0; $day < 1400; $day++) {
            $date = Carbon::parse('2023-01-01')->addDays($day)->toDateString();
            foreach ([$benchmark, $training, $trainingTwo, $trainingThree] as $subject) {
                StockPrice::query()->create([
                    'stock_id' => $subject->id,
                    'price_date' => $date,
                    'close_price' => 100 + $day + ($subject->id === $training->id ? $day % 7 : 0),
                    'adjusted_close_price' => 100 + $day,
                    'volume' => 1000 + $day,
                    'data_source' => 'test',
                ]);
            }
        }

        $this->app->bind(MlPythonAdapter::class, fn (): MlPythonAdapter => new class extends MlPythonAdapter
        {
            public function run(string $operation, array $payload): array
            {
                if ($operation !== 'train') {
                    return parent::run($operation, $payload);
                }
                File::put($payload['artifact_path'], 'test-model-artifact');
                $challengerDigest = null;
                if (! empty($payload['challenger_artifact_path'])) {
                    File::put($payload['challenger_artifact_path'], 'test-challenger-artifact');
                    $challengerDigest = hash_file('sha256', $payload['challenger_artifact_path']);
                }

                return [
                    'schema_version' => 1,
                    'artifact_sha256' => hash_file('sha256', $payload['artifact_path']),
                    'challenger_artifact_sha256' => $challengerDigest,
                    'metrics' => [
                        'roc_auc' => 0.71,
                        'pr_auc' => 0.67,
                        'benchmark_relative_return' => 0.02,
                        'deterministic_baseline_delta' => 0.01,
                        'class_distribution' => ['positive' => 12, 'negative' => 12, 'rows' => 24],
                    ],
                    'baselines' => ['naive' => [], 'deterministic_stox' => []],
                    'metadata' => [
                        'format' => 'test',
                        'effective_feature_set' => $payload['numeric_features'],
                        'excluded_features' => [],
                        'feature_training_coverage' => [],
                        'challenger' => [
                            'model_family' => 'hist_gradient_boosting_challenger',
                            'status' => 'trained',
                            'test_metrics' => [
                                'roc_auc' => 0.74,
                                'pr_auc' => 0.68,
                                'benchmark_relative_return' => 0.03,
                            ],
                            'roc_auc_delta_vs_logistic' => 0.03,
                        ],
                    ],
                ];
            }
        });
    }

    public function test_retrain_persists_challenger_evidence_on_training_run_and_model(): void
    {
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $modelId = $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson('/api/v1/admin/ml/retrain', ['horizon' => '3m', 'cutoff_date' => '2026-09-12'])
            ->assertCreated()
            ->json('data.model.id');

        $model = MlModelVersion::query()->findOrFail($modelId);
        $run = MlTrainingRun::query()->findOrFail($model->training_run_id);

        $this->assertSame('trained', $run->configuration['challenger_evidence']['status'] ?? null);
        $this->assertSame('hist_gradient_boosting_challenger', $model->audit_metadata['challenger_evidence']['model_family'] ?? null);

        $challenger = MlModelVersion::query()
            ->where('training_run_id', $run->id)
            ->where('model_family', 'hist_gradient_boosting_challenger')
            ->first();
        $this->assertNotNull($challenger);
        $this->assertSame('candidate', $challenger->status);
        $this->assertSame(0.74, (float) ($challenger->evaluation_metrics['roc_auc'] ?? 0));

        $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson("/api/v1/admin/ml/models/{$model->id}/promotion-review")
            ->assertOk()
            ->assertJsonPath('data.challenger_sibling.id', $challenger->id)
            ->assertJsonPath('data.challenger_sibling.eligible', true);
    }

    public function test_quality_rejection_is_distinct_from_operational_failure(): void
    {
        $this->app->bind(MlPythonAdapter::class, fn (): MlPythonAdapter => new class extends MlPythonAdapter
        {
            public function run(string $operation, array $payload): array
            {
                if ($operation !== 'train') {
                    return parent::run($operation, $payload);
                }

                File::put($payload['artifact_path'], 'rejected-model-artifact');

                return [
                    'schema_version' => 1,
                    'artifact_sha256' => hash_file('sha256', $payload['artifact_path']),
                    'metrics' => [
                        'roc_auc' => 0.49,
                        'pr_auc' => 0.45,
                        'benchmark_relative_return' => -0.01,
                        'deterministic_baseline_delta' => -0.02,
                        'class_distribution' => ['positive' => 12, 'negative' => 12, 'rows' => 24],
                    ],
                    'baselines' => ['deterministic_stox' => []],
                    'metadata' => [
                        'effective_feature_set' => $payload['numeric_features'],
                        'excluded_features' => [],
                        'feature_training_coverage' => [],
                    ],
                ];
            }
        });

        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        $modelId = $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson('/api/v1/admin/ml/retrain', ['horizon' => '3m', 'cutoff_date' => '2026-09-12'])
            ->assertCreated()
            ->json('data.model.id');

        $model = MlModelVersion::query()->findOrFail($modelId);
        $run = MlTrainingRun::query()->findOrFail($model->training_run_id);

        $this->assertSame('rejected', $model->status);
        $this->assertSame('completed_rejected', $run->status);
        $this->assertNull($run->failure);
    }
}
