<?php

namespace Tests\Feature\V8;

use App\Jobs\MlRetrainJob;
use App\Models\V7\MlDriftCheck;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlDriftTriggerEvaluator;
use App\Services\ML\MlLifecycleAutomationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MlDriftTriggerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests cover behavior after acceptance. Real evidence gates have their own tests.
        $this->mock(\App\Services\ML\MlAcceptanceCampaignService::class)
            ->shouldReceive('readiness')->andReturn(['ready' => true]);
    }


    public function test_evaluator_detects_material_drift_check(): void
    {
        config(['ml_lifecycle.drift_trigger.enabled' => true]);

        $run = MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'completed',
            'cutoff_date' => '2026-01-01',
            'configuration' => [],
            'started_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);
        $model = MlModelVersion::query()->create([
            'training_run_id' => $run->id,
            'horizon' => '3m',
            'version' => 1,
            'status' => 'active',
            'model_family' => 'interpretable_logistic_baseline',
            'training_cutoff_date' => '2026-01-01',
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
            'promoted_at' => now()->subMonths(2),
        ]);
        MlDriftCheck::query()->create([
            'model_version_id' => $model->id,
            'window_months' => 3,
            'status' => 'warning',
            'metrics' => ['hit_rate' => 0.4],
            'warnings' => ['live_hit_rate_deterioration'],
            'checked_at' => now()->subHour(),
        ]);

        $result = app(MlDriftTriggerEvaluator::class)->evaluateHorizon('3m');

        $this->assertNotNull($result);
        $this->assertContains('live_hit_rate_deterioration', $result['warnings']);
    }

    public function test_tick_queues_drift_retrain_when_material_check_present(): void
    {
        config([
            'ml_lifecycle.enabled' => true,
            'ml_lifecycle.horizons.3m.enabled' => false,
            'ml_lifecycle.drift_trigger.enabled' => true,
        ]);

        $run = MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'completed',
            'cutoff_date' => '2026-01-01',
            'configuration' => [],
            'started_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);
        $model = MlModelVersion::query()->create([
            'training_run_id' => $run->id,
            'horizon' => '3m',
            'version' => 1,
            'status' => 'active',
            'model_family' => 'interpretable_logistic_baseline',
            'training_cutoff_date' => '2026-01-01',
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
            'promoted_at' => now()->subMonths(2),
        ]);
        $check = MlDriftCheck::query()->create([
            'model_version_id' => $model->id,
            'window_months' => 3,
            'status' => 'warning',
            'metrics' => [],
            'warnings' => ['live_benchmark_return_deterioration'],
            'checked_at' => now(),
        ]);

        Bus::fake();
        $actions = app(MlLifecycleAutomationService::class)->tick(null, Carbon::now('Asia/Kolkata'));

        $driftActions = array_values(array_filter($actions, fn (array $row) => ($row['trigger'] ?? '') === 'drift'));
        $this->assertCount(1, $driftActions);
        Bus::assertDispatched(MlRetrainJob::class, function (MlRetrainJob $job) use ($check): bool {
            return $job->horizon === '3m'
                && $job->trigger === 'drift'
                && $job->driftCheckId === $check->id;
        });
    }

    public function test_tick_skips_drift_retrain_when_trigger_disabled(): void
    {
        config([
            'ml_lifecycle.enabled' => true,
            'ml_lifecycle.horizons.3m.enabled' => false,
            'ml_lifecycle.drift_trigger.enabled' => false,
        ]);

        $run = MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'completed',
            'cutoff_date' => '2026-01-01',
            'configuration' => [],
            'started_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);
        MlModelVersion::query()->create([
            'training_run_id' => $run->id,
            'horizon' => '3m',
            'version' => 1,
            'status' => 'active',
            'model_family' => 'interpretable_logistic_baseline',
            'training_cutoff_date' => '2026-01-01',
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
            'promoted_at' => now()->subMonths(2),
        ]);
        MlDriftCheck::query()->create([
            'model_version_id' => MlModelVersion::query()->where('horizon', '3m')->value('id'),
            'window_months' => 3,
            'status' => 'warning',
            'metrics' => [],
            'warnings' => ['live_hit_rate_deterioration'],
            'checked_at' => now(),
        ]);

        Bus::fake();
        $actions = app(MlLifecycleAutomationService::class)->tick(null, Carbon::now('Asia/Kolkata'));
        $this->assertFalse(collect($actions)->contains(fn (array $row) => ($row['trigger'] ?? '') === 'drift'));
        Bus::assertNothingDispatched();
    }

    public function test_drift_trigger_respects_cooldown_after_prior_drift_run(): void
    {
        config(['ml_lifecycle.drift_trigger.enabled' => true, 'ml_lifecycle.drift_trigger.cooldown_hours' => 168]);

        MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'completed',
            'cutoff_date' => '2026-01-01',
            'configuration' => ['trigger' => 'drift'],
            'started_at' => now()->subHours(2),
            'completed_at' => now()->subHours(1),
        ]);

        $this->assertNull(app(MlDriftTriggerEvaluator::class)->evaluateHorizon('3m'));
    }
}
