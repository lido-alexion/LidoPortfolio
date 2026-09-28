<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlPrediction;
use App\Models\V7\MlTrainingRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlStockInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_investor_can_fetch_ml_insights_per_horizon(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'MLIN', 'exchange' => 'NSE', 'name' => 'ML Insights']);

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
            'version' => 4,
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
            'score' => 0.61,
            'confidence' => 0.58,
            'benchmark_symbol' => 'NIFTY50',
            'shadow' => false,
            'explanations' => ['top_positive' => [], 'top_negative' => []],
            'feature_snapshot' => [],
        ]);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/ml-insights")
            ->assertOk()
            ->assertJsonPath('data.symbol', 'MLIN')
            ->assertJsonPath('data.horizons.1.horizon', '3m')
            ->assertJsonPath('data.horizons.1.prediction.score', 0.61)
            ->assertJsonPath('data.horizons.1.active_model.version', 4)
            ->assertJsonPath('data.horizons.0.prediction', null);
    }
}
