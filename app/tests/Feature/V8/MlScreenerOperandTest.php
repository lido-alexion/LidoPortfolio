<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlPrediction;
use App\Models\V7\MlTrainingRun;
use App\Services\Screener\ScreenerCatalog;
use App\Services\Screener\ScreenerEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlScreenerOperandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ScreenerCatalog::clearIndicatorCache();
    }

    public function test_catalog_includes_ml_success_score_indicators(): void
    {
        $ids = ScreenerCatalog::indicatorIds();
        $this->assertContains('ml_success_score_1m', $ids);
        $this->assertContains('ml_success_score_3m', $ids);
        $this->assertContains('ml_success_score_6m', $ids);
    }

    public function test_ml_condition_fails_when_prediction_missing(): void
    {
        $stock = Stock::query()->create(['symbol' => 'NOML', 'exchange' => 'NSE', 'name' => 'No ML']);
        $eval = app(ScreenerEvaluationService::class);
        $bars = [
            ['open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1000, 'date' => '2025-06-15'],
        ];
        $definition = [
            'root' => [
                'type' => 'condition',
                'operator' => 'gt',
                'left' => ['indicator' => 'ml_success_score_3m'],
                'right' => ['type' => 'constant', 'value' => 0.5],
            ],
        ];

        $result = $eval->evaluateStock($definition, $bars, [], $stock);
        $this->assertFalse($result['matched']);
        $this->assertFalse($result['skipped']);
    }

    public function test_ml_condition_matches_when_score_available(): void
    {
        $stock = Stock::query()->create(['symbol' => 'MLSC', 'exchange' => 'NSE', 'name' => 'ML Score']);
        $run = MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'completed',
            'cutoff_date' => '2025-06-01',
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
            'version' => 1,
            'status' => 'active',
            'model_family' => 'interpretable_logistic_baseline',
            'training_cutoff_date' => '2025-06-01',
            'feature_set' => [],
            'preprocessing' => [],
            'label_definition' => [],
            'benchmark_mapping' => [],
            'hyperparameters' => [],
            'evaluation_metrics' => [],
            'promotion_thresholds' => [],
            'artifact_path' => 'test',
            'artifact_sha256' => 'abc',
            'audit_metadata' => [],
        ]);
        MlPrediction::query()->create([
            'stock_id' => $stock->id,
            'model_version_id' => $model->id,
            'horizon' => '3m',
            'as_of' => '2025-06-10',
            'score' => 0.72,
            'confidence' => 0.72,
            'benchmark_symbol' => 'NIFTY50',
            'shadow' => false,
            'explanations' => [],
            'feature_snapshot' => [],
        ]);

        $eval = app(ScreenerEvaluationService::class);
        $bars = [
            ['open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1000, 'date' => '2025-06-15'],
        ];
        $definition = [
            'root' => [
                'type' => 'condition',
                'operator' => 'gt',
                'left' => ['indicator' => 'ml_success_score_3m'],
                'right' => ['type' => 'constant', 'value' => 0.5],
            ],
        ];

        $result = $eval->evaluateStock($definition, $bars, [], $stock);
        $this->assertTrue($result['matched']);
    }
}
