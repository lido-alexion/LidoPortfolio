<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\MlDriftCheck;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlTrainingDatasetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlFeatureRegistryAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_feature_registry(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/ml/features')
            ->assertOk()
            ->assertJsonPath('data.registry_version', 'v8-registry-10')
            ->assertJsonPath('data.features.0.feature_id', 'relative_strength_1m')
            ->assertJsonPath('data.features.0.pit_safety', 'point_in_time_safe')
            ->assertJsonPath('data.features.0.formula_version', 'v8-formula-1')
            ->assertJsonPath('data.implemented_count', count([
                ...MlTrainingDatasetBuilder::NUMERIC_FEATURES,
                ...MlTrainingDatasetBuilder::CATEGORICAL_FEATURES,
            ]));
    }

    public function test_admin_can_preview_dataset_plan_for_horizon(): void
    {
        Stock::query()->create([
            'symbol' => 'NIFTY50',
            'exchange' => 'NSE',
            'name' => 'NIFTY 50',
            'is_benchmark' => true,
            'is_active' => true,
        ]);
        $plan = Stock::query()->create([
            'symbol' => 'PLAN',
            'exchange' => 'NSE',
            'name' => 'Plan Co',
            'is_active' => true,
        ]);
        $inactive = Stock::query()->create([
            'symbol' => 'INACTIVE',
            'exchange' => 'NSE',
            'name' => 'Inactive Co',
            'is_active' => false,
        ]);
        \App\Models\StockPrice::query()->create([
            'stock_id' => $plan->id,
            'price_date' => '2026-06-30',
            'open_price' => 100,
            'high_price' => 100,
            'low_price' => 100,
            'close_price' => 100,
            'adjusted_close_price' => 100,
            'data_source' => 'test',
            'volume' => 1000,
        ]);
        \App\Models\StockPrice::query()->create([
            'stock_id' => $inactive->id,
            'price_date' => '2026-06-30',
            'close_price' => 100,
            'adjusted_close_price' => 100,
            'data_source' => 'test',
            'volume' => 1000,
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/ml/dataset-plan?horizon=3m&cutoff_date=2026-06-30')
            ->assertOk()
            ->assertJsonPath('data.feature_set.horizon', '3m')
            ->assertJsonStructure(['data' => ['feature_set' => ['feature_set_version', 'definition_hash']]])
            ->assertJsonPath('data.dataset_plan.horizon', '3m')
            ->assertJsonPath('data.dataset_plan.stock_count', 1);
    }

    public function test_admin_dashboard_surfaces_latest_drift_check_per_horizon(): void
    {
        $admin = User::factory()->admin()->create();
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
        $model = MlModelVersion::query()->create([
            'training_run_id' => $run->id,
            'horizon' => '1m',
            'version' => 1,
            'status' => 'active',
            'model_family' => 'interpretable_logistic_baseline',
            'training_cutoff_date' => '2026-06-01',
            'feature_set' => [],
            'preprocessing' => [],
            'label_definition' => [],
            'benchmark_mapping' => [],
            'hyperparameters' => [],
            'evaluation_metrics' => [],
            'promotion_thresholds' => [],
            'artifact_path' => null,
            'artifact_sha256' => null,
            'audit_metadata' => [],
        ]);
        MlDriftCheck::query()->create([
            'model_version_id' => $model->id,
            'window_months' => 3,
            'status' => 'warning',
            'metrics' => ['roc_auc' => 0.48],
            'warnings' => ['roc_auc_below_floor'],
            'checked_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/ml')
            ->assertOk()
            ->assertJsonPath('data.horizons.0.horizon', '1m')
            ->assertJsonPath('data.horizons.0.latest_drift_check.status', 'warning');
    }
}
