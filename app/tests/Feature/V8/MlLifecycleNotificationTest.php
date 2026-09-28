<?php

namespace Tests\Feature\V8;

use App\Models\NotificationSource;
use App\Models\User;
use App\Models\V7\MlDriftCheck;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlLifecycleNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlLifecycleNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_candidate_publishes_admin_notification(): void
    {
        config(['ml_lifecycle.notifications.enabled' => true]);
        User::factory()->admin()->create();

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
            'version' => 4,
            'status' => 'candidate',
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

        app(MlLifecycleNotificationService::class)->notifyEligibleCandidate($model);

        $this->assertDatabaseHas('portfolio_notification_sources', [
            'notification_type' => 'ml.lifecycle.condition',
            'severity' => 'action_required',
        ]);
        $this->assertSame(1, NotificationSource::query()->count());
    }

    public function test_drift_warning_skipped_when_status_stable(): void
    {
        config(['ml_lifecycle.notifications.enabled' => true]);
        User::factory()->admin()->create();

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
        $check = MlDriftCheck::query()->create([
            'model_version_id' => $model->id,
            'window_months' => 3,
            'status' => 'ok',
            'metrics' => [],
            'warnings' => [],
            'checked_at' => now(),
        ]);

        app(MlLifecycleNotificationService::class)->notifyDriftWarning($check, $model);

        $this->assertSame(0, NotificationSource::query()->count());
    }
}
