<?php

namespace App\Services\ML;

use App\Jobs\MlAcceptanceJob;
use App\Jobs\MlRetrainJob;
use App\Models\User;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use App\Models\V8\MlAcceptanceCampaign;
use App\Models\V8\MlUniverseSnapshotBoundary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MlAcceptanceCampaignService
{
    public function create(string $cutoff, int $actor): MlAcceptanceCampaign
    {
        app(MlAcceptanceRuntime::class)->assertQueue();
        validator(['cutoff' => $cutoff], ['cutoff' => 'required|date_format:Y-m-d|before_or_equal:today'])->validate();

        return Cache::lock('ml-acceptance-campaign-create', 30)->block(5, function () use ($cutoff, $actor) {
            if (MlAcceptanceCampaign::query()->whereIn('status', ['preflight', 'ready', 'training'])->exists()) {
                $this->fail('An acceptance campaign is already active.');
            }
            // Retain immutable evidence rather than silently pruning it to free space.
            if (MlAcceptanceCampaign::query()->count() >= 100) {
                $this->fail('Acceptance evidence retention quota reached.');
            }
            $runtime = app(MlAcceptanceRuntime::class);
            $campaign = MlAcceptanceCampaign::query()->create([
                'id' => (string) Str::uuid(), 'actor_id' => $actor, 'cutoff_date' => $cutoff, 'status' => 'preflight',
                'identity' => $runtime->identity(), 'active_models' => $runtime->activeModels(), 'horizons' => [],
                'history' => $runtime->history([], 'preflight_requested', $actor),
            ]);
            MlAcceptanceJob::dispatch('campaign', $campaign->id);

            return $campaign;
        });
    }

    public function action(MlAcceptanceCampaign $campaign, string $action, int $actor): MlAcceptanceCampaign
    {
        if ($action !== 'cancel') {
            app(MlAcceptanceRuntime::class)->assertQueue();
        }

        return Cache::lock('ml-campaign-'.$campaign->id, MlAcceptanceRuntime::LOCK_SECONDS)->block(5, function () use ($campaign, $action, $actor) {
            $campaign->refresh();
            if ($action === 'start') {
                if ($campaign->status !== 'ready' || ! $this->identityMatches($campaign)) {
                    $this->fail('Successful current preflight is required.');
                }
                DB::transaction(function () use ($campaign, $actor) {
                    $horizons = $campaign->horizons;
                    foreach (MlScoringService::HORIZONS as $horizon) {
                        if (! isset($horizons[$horizon]) || $horizons[$horizon]['blocking_reasons'] !== []) {
                            $this->fail('All horizons must pass preflight.');
                        }
                        $run = app(MlScoringService::class)->queueRetrainRun($horizon, User::query()->findOrFail($actor), 'manual', null, [
                            'campaign_id' => $campaign->id, 'cutoff_date' => $campaign->cutoff_date->toDateString(),
                            'dataset_sha256' => $horizons[$horizon]['dataset_sha256'], 'identity' => $campaign->identity,
                        ]);
                        $horizons[$horizon]['run_id'] = $run->id;
                    }
                    $campaign->forceFill(['status' => 'training', 'horizons' => $horizons])->save();
                });
            } elseif ($action === 'cancel') {
                if (! in_array($campaign->status, ['preflight', 'ready', 'training'], true)) {
                    $this->fail('Campaign has finished.');
                }
                foreach ($campaign->horizons as $evidence) {
                    $run = isset($evidence['run_id']) ? MlTrainingRun::query()->find($evidence['run_id']) : null;
                    if ($run && in_array($run->status, ['queued', 'running', 'cancelling'], true)) {
                        app(MlTrainingRunCancellationService::class)->request($run, User::query()->find($actor), 'Acceptance campaign cancelled');
                    }
                }
                $campaign->status = 'cancelled';
            } elseif ($action === 'resume') {
                if (! in_array($campaign->status, ['preflight', 'training', 'cancelled'], true)) {
                    $this->fail('Failed training requires a fresh campaign.');
                }
                if (! $this->identityMatches($campaign)) {
                    $this->fail('Campaign identity changed.');
                }
                $runIds = array_values(array_filter(array_column($campaign->horizons, 'run_id')));
                app(MlTrainingRunRecoveryService::class)->recover(null, $runIds);
                foreach ($campaign->horizons as $horizon => $evidence) {
                    $run = isset($evidence['run_id']) ? MlTrainingRun::query()->find($evidence['run_id']) : null;
                    if ($run?->status === 'queued') {
                        MlRetrainJob::dispatch($horizon, $run->requested_by, 'manual', null, $run->id);
                    }
                }
                MlAcceptanceJob::dispatch('campaign', $campaign->id);
            } else {
                $this->fail('Unsupported action.');
            }
            $campaign->history = app(MlAcceptanceRuntime::class)->history($campaign->history, $action, $actor);
            $campaign->save();

            return $campaign;
        });
    }

    public function step(string $id, ?array $workerEvidence = null): void
    {
        Cache::lock('ml-campaign-'.$id, MlAcceptanceRuntime::LOCK_SECONDS)->block(5, function () use ($id, $workerEvidence) {
            $campaign = MlAcceptanceCampaign::query()->findOrFail($id);
            if (! in_array($campaign->status, ['preflight', 'training'], true)) {
                return;
            }
            if (! $this->identityMatches($campaign)) {
                $campaign->forceFill(['status' => 'failed', 'history' => app(MlAcceptanceRuntime::class)->history($campaign->history, 'identity_changed', null)])->save();

                return;
            }
            if ($campaign->status === 'training') {
                $this->finish($campaign);

                return;
            }
            $horizons = $campaign->horizons;
            foreach (MlScoringService::HORIZONS as $horizon) {
                if (isset($horizons[$horizon])) {
                    continue;
                }
                $horizons[$horizon] = app(MlAcceptanceEvidenceService::class)->preflight($campaign->id, $horizon, $campaign->cutoff_date);
                $horizons[$horizon]['worker_evidence'] = $workerEvidence;
                $horizons[$horizon]['worker_observed_at'] = $workerEvidence['observed_at'] ?? null;
                $campaign->forceFill(['horizons' => $horizons])->save();
                if (count($horizons) < 3) {
                    MlAcceptanceJob::dispatch('campaign', $id);

                    return;
                }
                break;
            }
            $passed = count($horizons) === 3 && collect($horizons)->every(fn ($e) => $e['blocking_reasons'] === []);
            $campaign->forceFill(['status' => $passed ? 'ready' : 'blocked', 'history' => app(MlAcceptanceRuntime::class)->history($campaign->history, $passed ? 'preflight_passed' : 'preflight_blocked', null)])->save();
        });
    }

    public function assertTraining(MlTrainingRun $run, array $dataset): void
    {
        $acceptance = $run->configuration['acceptance'] ?? null;
        if ($acceptance === null) {
            return;
        }
        $campaign = MlAcceptanceCampaign::query()->findOrFail($acceptance['campaign_id']);
        foreach ($campaign->horizons[$run->horizon]['snapshots'] ?? [] as $date => $snapshot) {
            $boundary = MlUniverseSnapshotBoundary::query()
                ->where('universe_key', MlHistoricalUniverseMembershipService::ACTIVE_ELIGIBLE_NSE)->whereDate('effective_from', $date)->first();
            if (! $boundary || $boundary->snapshot_key !== $snapshot['snapshot_key']) {
                $this->fail('Acceptance snapshot identity changed.');
            }
            foreach ($snapshot['diagnostics'] as $key => $value) {
                if (($boundary->quality_diagnostics[$key] ?? null) != $value) {
                    $this->fail('Acceptance snapshot provenance changed.');
                }
            }
        }
        if ($campaign->status !== 'training' || ! $this->identityMatches($campaign)
            || ($campaign->horizons[$run->horizon]['run_id'] ?? null) !== $run->id
            || ! hash_equals($acceptance['dataset_sha256'], app(MlAcceptanceEvidenceService::class)->datasetHash($dataset))) {
            $this->fail('Acceptance inputs changed or campaign is no longer active.');
        }
    }

    private function finish(MlAcceptanceCampaign $campaign): void
    {
        $horizons = $campaign->horizons;
        $complete = true;
        $valid = true;
        foreach (MlScoringService::HORIZONS as $horizon) {
            $run = MlTrainingRun::query()->find($horizons[$horizon]['run_id'] ?? 0);
            if ($run && in_array($run->status, ['queued', 'running', 'cancelling'], true)) {
                $complete = false;

                continue;
            }
            $model = $run ? MlModelVersion::query()->where('training_run_id', $run->id)->orderBy('id')->first() : null;
            $configuration = $run?->configuration ?? [];
            $metadata = $model?->audit_metadata['adapter_metadata'] ?? [];
            $passed = app(MlAcceptanceRuntime::class)->validWorkerEvidence($horizons[$horizon]['worker_evidence'] ?? []) && $run && in_array($run->status, ['completed_eligible', 'completed_rejected'], true)
                && $model && is_file((string) app(MlArtifactPaths::class)->resolve($model->artifact_path)) && hash_equals((string) $model->artifact_sha256, hash_file('sha256', (string) app(MlArtifactPaths::class)->resolve($model->artifact_path)))
                && ! empty($configuration['chronological_validation_grid'])
                && ! empty($configuration['feature_selection']['partition_coverage'])
                && ! empty($metadata['calibration']) && ! empty($metadata['challenger']) && ! empty($run->baselines)
                && ($metadata['execution']['adapter'] ?? null) === MlPythonAdapter::class
                && ($metadata['execution']['environment'] ?? null) === $campaign->identity['environment']
                && ($metadata['execution']['adapter_sha256'] ?? null) === $campaign->identity['adapter_sha256'];
            $horizons[$horizon]['training'] = ['run_id' => $run?->id, 'status' => $run?->status ?? 'missing', 'evidence_complete' => (bool) $passed,
                'model_id' => $model?->id, 'artifact_sha256' => $model?->artifact_sha256,
                'feature_selection' => $configuration['feature_selection'] ?? null, 'folds' => $configuration['chronological_validation_grid'] ?? null,
                'calibration' => $metadata['calibration'] ?? null, 'baseline' => $run?->baselines, 'candidate' => $configuration['challenger_evidence'] ?? null,
                'adapter_execution' => $metadata['execution'] ?? null];
            $valid = $valid && $passed;
        }
        $campaign->horizons = $horizons;
        if ($complete) {
            $identity = $campaign->identity;
            $qualified = $valid && $identity['environment'] === 'production' && ! empty($identity['build_id']) && $identity['build_id'] !== 'local'
                && preg_match('/\A[0-9a-f]{40}\z/', (string) $identity['commit_sha']) === 1;
            $campaign->status = $qualified ? 'qualified' : ($valid ? 'local_complete' : 'failed');
            $campaign->qualified_at = $qualified ? now() : null;
            $campaign->history = app(MlAcceptanceRuntime::class)->history($campaign->history, $campaign->status, null);
        }
        $campaign->save();
    }

    public function readiness(): array
    {
        if (! Schema::hasTable('stox_ml_acceptance_campaigns')) {
            return ['ready' => false, 'reason' => 'acceptance_migration_missing'];
        }
        $campaign = MlAcceptanceCampaign::query()->where('status', 'qualified')->where('qualified_at', '>=', now()->subDays(30))->latest('qualified_at')->first();
        $runtime = app(MlAcceptanceRuntime::class);
        $ready = $campaign && $this->identityMatches($campaign, false) && $runtime->queueReady();
        foreach (MlScoringService::HORIZONS as $horizon) {
            $evidence = $campaign?->horizons[$horizon] ?? [];
            $ready = $ready && $runtime->validWorkerEvidence($evidence['worker_evidence'] ?? [])
                && ($evidence['training']['evidence_complete'] ?? false)
                && ($evidence['training']['adapter_execution']['adapter'] ?? null) === MlPythonAdapter::class;
        }

        return ['ready' => (bool) $ready, 'reason' => $ready ? null : 'current_production_acceptance_required', 'campaign_id' => $ready ? $campaign->id : null, 'qualified_at' => $ready ? $campaign->qualified_at->toIso8601String() : null];
    }

    public function identityMatches(MlAcceptanceCampaign $campaign, bool $checkActive = true): bool
    {
        $runtime = app(MlAcceptanceRuntime::class);

        return $campaign->identity == $runtime->identity() && (! $checkActive || $campaign->active_models == $runtime->activeModels());
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['campaign' => [$message]]);
    }
}
