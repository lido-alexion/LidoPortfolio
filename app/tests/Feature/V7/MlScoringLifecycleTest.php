<?php

namespace Tests\Feature\V7;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlPrediction;
use App\Models\V7\MlTrainingRun;
use App\Models\StockPrice;
use App\Services\ML\MlPythonAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;
use Tests\TestCase;

class MlScoringLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $benchmark = Stock::query()->create(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true]);
        $training = Stock::query()->create(['symbol' => 'TRAINING', 'exchange' => 'NSE', 'name' => 'Training Issuer']);
        for ($day = 0; $day < 520; $day++) {
            $date = Carbon::parse('2025-01-01')->addDays($day)->toDateString();
            foreach ([$benchmark, $training] as $subject) {
                StockPrice::query()->create([
                    'stock_id' => $subject->id,
                    'price_date' => $date,
                    'close_price' => 100 + $day + ($subject->id === $training->id ? $day % 7 : 0),
                    'adjusted_close_price' => 100 + $day,
                    'data_source' => 'test',
                ]);
            }
        }
        $this->app->bind(MlPythonAdapter::class, fn (): MlPythonAdapter => new class extends MlPythonAdapter
        {
            public function run(string $operation, array $payload): array
            {
                if ($operation === 'train') {
                    File::put($payload['artifact_path'], 'test-model-artifact');

                    return [
                        'schema_version' => 1,
                        'artifact_sha256' => hash_file('sha256', $payload['artifact_path']),
                        'metrics' => [
                            'roc_auc' => 0.72,
                            'pr_auc' => 0.68,
                            'benchmark_relative_return' => 0.03,
                            'deterministic_baseline_delta' => 0.01,
                            'class_distribution' => ['positive' => 15, 'negative' => 15, 'rows' => 30],
                        ],
                        'baselines' => ['naive' => [], 'deterministic_stox' => []],
                        'metadata' => ['format' => 'test'],
                    ];
                }

                if ($operation === 'predict') {
                    return [
                        'schema_version' => 1,
                        'score' => 63.5,
                        'confidence' => 0.635,
                        'contributions' => [
                            ['feature' => 'momentum_score', 'value' => 1, 'coefficient' => 0.2, 'contribution' => 0.2, 'direction' => 'positive'],
                        ],
                    ];
                }

                return ['schema_version' => 1, 'status' => 'insufficient_data', 'metrics' => [], 'warnings' => ['insufficient_predictions']];
            }
        });
    }

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

        $this->actingAs($member)->withProfileHeader($member)
            ->postJson("/api/v1/stocks/{$stock->id}/ml-predictions", ['horizon' => '3m', 'as_of' => '2026-09-13', 'shadow' => true])
            ->assertOk()
            ->assertJsonPath('data.prediction.shadow', true);
        $this->assertFalse(app(\App\Services\ML\MlScoringService::class)->latestPrediction($stock, '3m', now())->shadow === true);
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

        $artifactPath = storage_path('framework/testing/ml-threshold-model.joblib');
        File::ensureDirectoryExists(dirname($artifactPath));
        File::put($artifactPath, 'test-model-artifact');
        $model->forceFill([
            'artifact_path' => $artifactPath,
            'artifact_sha256' => hash_file('sha256', $artifactPath),
            'artifact_format' => 'joblib',
            'artifact_version' => 'test',
        ])->save();

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

    public function test_admin_can_persist_a_drift_check_without_affecting_predictions(): void
    {
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);
        $modelId = $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson('/api/v1/admin/ml/retrain', ['horizon' => '1m', 'cutoff_date' => '2026-09-12'])
            ->assertCreated()
            ->json('data.model.id');
        $model = MlModelVersion::query()->findOrFail($modelId);
        for ($i = 0; $i < 30; $i++) {
            MlPrediction::query()->create([
                'stock_id' => 1,
                'model_version_id' => $model->id,
                'horizon' => '1m',
                'as_of' => now()->subDays($i),
                'score' => 50,
                'confidence' => 0.5,
                'benchmark_symbol' => 'NIFTY50',
                'shadow' => false,
            ]);
        }

        $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson("/api/v1/admin/ml/models/{$model->id}/drift-check", ['window_months' => 3])
            ->assertOk()
            ->assertJsonPath('data.drift_check.status', 'insufficient_data');
    }
}
