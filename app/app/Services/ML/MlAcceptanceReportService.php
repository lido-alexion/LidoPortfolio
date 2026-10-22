<?php

namespace App\Services\ML;

use App\Models\V7\MlTrainingRun;
use App\Models\V8\MlAcceptanceCampaign;
use App\Models\V8\MlAcceptanceSource;
use App\Models\V8\MlUniverseSnapshotBackfillRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MlAcceptanceReportService
{
    /** Read-only: never probes Python, dispatches jobs, or materializes settings. */
    public function report(): array
    {
        return $this->buildReport();
    }

    private function buildReport(?MlAcceptanceCampaign $fixedCampaign = null, bool $useFixedCampaign = false): array
    {
        $tables = ['stox_ml_acceptance_sources', 'stox_ml_acceptance_campaigns', 'stox_ml_universe_snapshot_backfill_runs',
            'stox_ml_universe_snapshot_boundaries', 'stox_ml_universe_memberships', 'stox_fundamental_facts', 'stox_ml_training_runs'];
        $migrations = [];
        foreach ($tables as $table) {
            $migrations[$table] = Schema::hasTable($table);
        }
        $campaign = $useFixedCampaign ? $fixedCampaign : ($migrations['stox_ml_acceptance_campaigns']
            ? MlAcceptanceCampaign::query()->orderByDesc('wizard_sequence')->orderByDesc('created_at')->orderByDesc('id')->first() : null);
        $runtime = app(MlAcceptanceRuntime::class);
        $horizons = [];
        foreach (MlScoringService::HORIZONS as $horizon) {
            $horizons[$horizon] = $campaign?->horizons[$horizon] ?? ['reference_dates' => null, 'coverage' => null, 'blocking_reasons' => ['preflight_not_recorded']];
            $runId = $horizons[$horizon]['run_id'] ?? null;
            $run = $runId && $migrations['stox_ml_training_runs'] ? MlTrainingRun::query()->find($runId) : null;
            $horizons[$horizon]['run_progress'] = $run ? [
                'run_id' => $run->id, 'status' => $run->status,
                'stage' => $run->configuration['progress']['stage'] ?? null,
                'percent' => $run->configuration['progress']['percent'] ?? null,
                'retry_attempt' => $run->configuration['progress']['retry_attempt'] ?? null,
                'observed_at' => $run->configuration['progress']['updated_at'] ?? null,
            ] : null;
        }
        $bootstrapTable = 'stox_fundamental_bootstrap_runs';
        $bootstrap = Schema::hasTable($bootstrapTable) ? DB::table($bootstrapTable)->orderByDesc('id')->first() : null;
        $lifecycle = ['enabled' => (bool) config('ml_lifecycle.enabled'), 'drift_trigger_enabled' => (bool) config('ml_lifecycle.drift_trigger.enabled'), 'horizons' => []];
        foreach (MlScoringService::HORIZONS as $horizon) {
            $lifecycle['horizons'][$horizon] = Schema::hasTable('stox_ml_lifecycle_schedules')
                ? app(MlLifecycleAutomationService::class)->scheduleSettings($horizon) : ['enabled' => null, 'status' => 'migration_missing'];
            $lifecycle['horizons'][$horizon]['drift_trigger_enabled'] = (bool) config('ml_lifecycle.drift_trigger.enabled');
        }

        return $this->safe([
            'reported_at' => now()->toIso8601String(), 'identity' => $runtime->identity(), 'migrations' => $migrations,
            'runtime' => ['queue_configuration_ready' => $runtime->queueReady(), 'connection' => MlAcceptanceRuntime::CONNECTION, 'queue' => MlAcceptanceRuntime::QUEUE,
                'worker_evidence' => array_map(fn ($e) => $runtime->validWorkerEvidence($e['worker_evidence'] ?? []) ? $e['worker_evidence']['observed_at'] : null, $horizons),
                'python_evidence' => array_map(fn ($e) => $e['training']['adapter_execution'] ?? null, $horizons), 'unknown_is_ready' => false],
            'lifecycle' => $lifecycle, 'readiness' => app(MlAcceptanceCampaignService::class)->readiness(),
            'campaign_id' => $campaign?->id, 'campaign_status' => $campaign?->status ?? 'not_recorded',
            'evidence_current' => $campaign ? $campaign->created_at->greaterThanOrEqualTo(now()->subDays(30)) && app(MlAcceptanceCampaignService::class)->identityMatches($campaign, false) : false,
            'bootstrap' => $bootstrap ? ['id' => $bootstrap->id, 'status' => $bootstrap->status ?? 'unknown', 'created_at' => $bootstrap->created_at ?? null] : ['status' => 'unknown'],
            'horizons' => $horizons,
        ]);
    }

    /**
     * Read-only projection for the acceptance wizard. It only combines persisted
     * acceptance records and the existing report; it never dispatches work or
     * probes the runtime.
     */
    public function wizard(): array
    {
        $campaign = Schema::hasTable('stox_ml_acceptance_campaigns')
            ? MlAcceptanceCampaign::query()->orderByDesc('wizard_sequence')->orderByDesc('created_at')->orderByDesc('id')->first()
            : null;
        $report = $this->buildReport($campaign, true);
        $finalPreflight = false;
        $linkedBackfillId = null;
        foreach (array_reverse($campaign?->history ?? []) as $entry) {
            $action = $entry['action'] ?? '';
            if (in_array($action, ['final_preflight_requested', 'preflight_requested'], true) || str_starts_with($action, 'final_preflight_requested_after_backfill:')) {
                $finalPreflight = str_starts_with($action, 'final_preflight_requested');
                if (preg_match('/\Afinal_preflight_requested_after_backfill:(\d+)\z/', $action, $matches)) { $linkedBackfillId = (int) $matches[1]; }
                break;
            }
        }
        $backfill = null;
        if (Schema::hasTable('stox_ml_universe_snapshot_backfill_runs') && $campaign) {
            $backfill = $linkedBackfillId !== null
                ? MlUniverseSnapshotBackfillRun::query()->find($linkedBackfillId)
                : MlUniverseSnapshotBackfillRun::query()->whereJsonContains('acceptance->campaign_id', $campaign->id)->latest('id')->first();
            if ($linkedBackfillId !== null && (($backfill?->acceptance['mode'] ?? null) !== 'apply'
                || ! app(MlAcceptanceBackfillService::class)->isComplete($backfill))) {
                $backfill = null;
            }
        }

        $requiredDates = [];
        foreach ($report['horizons'] ?? [] as $horizon => $evidence) {
            foreach ($evidence['snapshots'] ?? [] as $date => $snapshot) {
                if (($snapshot['status'] ?? null) !== 'covered') {
                    $requiredDates[$date] = ['date' => $date, 'horizons' => array_values(array_unique(array_merge($requiredDates[$date]['horizons'] ?? [], [$horizon])))];
                }
            }
        }
        ksort($requiredDates);
        $sourceByDate = [];
        if ($requiredDates !== [] && Schema::hasTable('stox_ml_acceptance_sources')) {
            $sourceQuery = MlAcceptanceSource::query()->whereIn('status', ['sealed', 'uploading', 'queued', 'failed'])
                ->where(function ($query) use ($requiredDates) {
                    foreach (array_keys($requiredDates) as $date) {
                        $query->orWhereJsonContains('manifest->date', $date);
                    }
                })->orderByDesc('created_at')->orderByDesc('id');
            foreach ($sourceQuery->get() as $source) {
                $date = $source->manifest['date'] ?? null;
                $family = $source->manifest['source'] ?? null;
                $key = $family.'|'.$source->status;
                if (isset($requiredDates[$date]) && in_array($family, ['nse_mii_security_file', 'nse_cash_bhavcopy'], true)
                    && ! isset($sourceByDate[$date][$key])) {
                    $sourceByDate[$date][$key] = ['id' => $source->id, 'family' => $family, 'date' => $date,
                        'filename' => $source->manifest['filename'] ?? null, 'status' => $source->status, 'received' => $source->received,
                        'bytes' => $source->manifest['bytes'] ?? null];
                }
            }
        }
        foreach ($requiredDates as $date => $requirement) {
            $requirement['sources'] = array_values($sourceByDate[$date] ?? []);
            $requirement['accepted_families'] = ['nse_mii_security_file', 'nse_cash_bhavcopy'];
            $requiredDates[$date] = $requirement;
        }

        $campaignStatus = $campaign?->status ?? 'not_recorded';
        $requiredSourceDates = array_keys($requiredDates);
        $allSourcesSealed = $requiredSourceDates !== [] && collect($requiredSourceDates)->every(fn ($date) => collect($sourceByDate[$date] ?? [])->contains(fn ($source) => $source['status'] === 'sealed'));
        $backfillState = $backfill?->acceptance ?? [];
        $backfillStatus = $backfill?->status;
        $backfillMode = $backfillState['mode'] ?? null;
        $backfillComplete = $backfill && app(MlAcceptanceBackfillService::class)->isComplete($backfill);
        $preflightRecorded = $campaign && collect(MlScoringService::HORIZONS)->every(fn ($horizon) => array_key_exists($horizon, $campaign->horizons ?? []));
        $trainingStarted = collect($campaign?->history ?? [])->contains(fn ($entry) => ($entry['action'] ?? null) === 'start');
        $qualified = $campaignStatus === 'qualified' && (bool) ($report['evidence_current'] ?? false);
        $reasonCodes = array_merge(...array_map(fn ($h) => $h['blocking_reasons'] ?? [], array_values($report['horizons'] ?? [])));
        $hasSourceBlocker = collect($reasonCodes)->contains(fn ($code) => str_starts_with((string) $code, 'snapshot_unproven:') || $code === 'membership_coverage_incomplete');
        $isSourceRemediation = $campaignStatus === 'blocked' && $requiredSourceDates !== [] && $hasSourceBlocker;

        $requiredTables = array_keys($report['migrations'] ?? []);
        $missingTables = array_values(array_filter($requiredTables, fn ($table) => empty($report['migrations'][$table])));
        $readinessReasons = [];
        if (empty($report['runtime']['queue_configuration_ready'])) { $readinessReasons[] = 'queue_configuration_not_ready'; }
        if ($missingTables !== []) { $readinessReasons[] = 'acceptance_migration_missing'; }

        $backfillReasonCodes = $backfillStatus === 'failed' ? ['backfill_failed']
            : ($backfillStatus === 'completed' && ! $backfillComplete ? ['backfill_evidence_incomplete'] : []);
        $steps = [
            ['id' => 'readiness', 'status' => $readinessReasons === [] ? 'complete' : 'blocked',
                'reason_codes' => $readinessReasons],
            ['id' => 'discovery', 'status' => ! $campaign ? 'action_required' : ($campaignStatus === 'preflight' && ! $finalPreflight ? 'in_progress'
                : ($isSourceRemediation || ($finalPreflight && in_array($campaignStatus, ['blocked', 'failed', 'cancelled'], true)) ? 'complete'
                    : (in_array($campaignStatus, ['blocked', 'failed', 'cancelled'], true) ? $campaignStatus : 'complete'))),
                'reason_codes' => ! $campaign ? ['preflight_not_recorded']
                    : ($campaignStatus === 'blocked' && ! $isSourceRemediation ? (array_values(array_unique($reasonCodes)) ?: ['preflight_blocked']) : [])],
            ['id' => 'sources', 'status' => $requiredSourceDates === [] ? ($preflightRecorded || $finalPreflight ? 'not_required' : 'action_required') : ($backfill ? 'complete' : 'action_required'),
                'reason_codes' => $requiredSourceDates === [] ? [] : ($allSourcesSealed ? ['source_selection_required'] : ['missing_required_source_date'])],
            ['id' => 'backfill', 'status' => in_array($backfillStatus, ['queued', 'running'], true) ? 'in_progress'
                    : ($backfillStatus === 'failed' ? 'failed' : ($backfillStatus === 'cancelled' ? 'cancelled'
                        : ($backfillComplete && $backfillMode === 'apply' ? 'complete'
                        : ($backfillStatus === 'completed' && ! $backfillComplete && $backfillMode === 'apply' ? 'blocked'
                            : ($backfillStatus === 'completed' && $backfillMode === 'preview' && $backfillComplete ? 'action_required'
                                : ($requiredSourceDates === [] ? 'not_required' : ($allSourcesSealed ? 'action_required' : 'blocked'))))))),
                'reason_codes' => $backfillReasonCodes],
            ['id' => 'final_preflight', 'status' => $qualified || in_array($campaignStatus, ['ready', 'training', 'local_complete'], true)
                || ($trainingStarted && in_array($campaignStatus, ['failed', 'cancelled'], true)) ? 'complete'
                : ($campaignStatus === 'preflight' && $finalPreflight ? 'in_progress'
                    : (in_array($campaignStatus, ['failed', 'cancelled'], true) && $finalPreflight ? $campaignStatus
                        : ($campaignStatus === 'blocked' && ! $isSourceRemediation ? 'blocked' : ($backfillComplete && $backfillMode === 'apply' ? 'action_required' : ($isSourceRemediation ? 'blocked' : 'action_required'))))),
                'reason_codes' => $campaignStatus === 'blocked' && (! $isSourceRemediation || $finalPreflight) ? array_values(array_unique($reasonCodes)) : []],
            ['id' => 'training', 'status' => in_array($campaignStatus, ['training'], true) ? 'in_progress'
                : (in_array($campaignStatus, ['qualified', 'local_complete'], true) ? 'complete'
                    : (in_array($campaignStatus, ['failed', 'cancelled'], true) && $finalPreflight && $preflightRecorded ? $campaignStatus
                        : ($campaignStatus === 'ready' ? 'action_required' : 'blocked'))),
                'reason_codes' => []],
            ['id' => 'review', 'status' => $qualified ? 'complete' : (in_array($campaignStatus, ['failed', 'cancelled', 'blocked'], true) ? $campaignStatus : 'action_required'),
                'reason_codes' => []],
        ];
        $actionable = ['action_required', 'blocked', 'failed', 'cancelled', 'in_progress'];
        $currentStep = 7;
        foreach ($steps as $index => $step) {
            if (in_array($step['status'], $actionable, true)) { $currentStep = $index + 1; break; }
        }
        if ($qualified) { $currentStep = 7; }

        return $this->safe([
            'reported_at' => now()->toIso8601String(), 'current_step' => $currentStep, 'steps' => $steps,
            'campaign' => $campaign ? ['id' => $campaign->id, 'status' => $campaign->status, 'cutoff_date' => $campaign->cutoff_date?->toDateString(),
                'created_at' => $campaign->created_at?->toIso8601String(), 'identity' => $campaign->identity, 'active_models' => $campaign->active_models,
                'qualified_at' => $campaign->qualified_at?->toIso8601String(), 'final_preflight' => $finalPreflight, 'history' => $campaign->history] : null,
            'backfill' => $backfill ? ['id' => $backfill->id, 'status' => $backfill->status, 'mode' => $backfillMode,
                'cursor' => $backfillState['cursor'] ?? 0, 'requested_dates' => $backfill->requested_dates ?? [],
                'results' => $backfillState['results'] ?? [], 'history' => $backfillState['history'] ?? [],
                'resumable' => $backfillStatus === 'failed',
                'can_apply' => $backfillMode === 'preview' && (bool) $backfillComplete,
                'can_retry_preview' => $backfillStatus === 'cancelled' || ($backfillStatus === 'completed' && $backfillMode === 'preview' && ! $backfillComplete),
                'created_at' => $backfill->created_at?->toIso8601String()] : null,
            'required_sources' => array_values($requiredDates), 'horizons' => $report['horizons'] ?? [],
            'readiness' => $report['readiness'] ?? [], 'evidence_current' => $report['evidence_current'] ?? false,
            'identity' => $report['identity'] ?? [], 'migrations' => $report['migrations'] ?? [],
            'runtime' => $report['runtime'] ?? [], 'lifecycle' => $report['lifecycle'] ?? [],
            'links' => ['report' => '/api/v1/admin/ml/acceptance', 'model_review' => '/settings/ml-scoring'],
        ]);
    }

    /** Never expose host paths, raw provider identifiers, subprocess errors or payloads. */
    public function safe(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/path|nse_source_file|stderr|unmapped_identifiers|failure|source_meta|raw_payload|(?:^|_)(?:error|exception|traceback)(?:$|_)/i', $key)) {
                continue;
            }
            $out[$key] = is_array($item) ? $this->safe($item) : $item;
        }

        return $out;
    }
}
