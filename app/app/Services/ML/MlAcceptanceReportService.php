<?php

namespace App\Services\ML;

use App\Models\V7\MlTrainingRun;
use App\Models\V8\MlAcceptanceCampaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MlAcceptanceReportService
{
    /** Read-only: never probes Python, dispatches jobs, or materializes settings. */
    public function report(): array
    {
        $tables = ['stox_ml_acceptance_sources', 'stox_ml_acceptance_campaigns', 'stox_ml_universe_snapshot_backfill_runs',
            'stox_ml_universe_snapshot_boundaries', 'stox_ml_universe_memberships', 'stox_fundamental_facts', 'stox_ml_training_runs'];
        $migrations = [];
        foreach ($tables as $table) {
            $migrations[$table] = Schema::hasTable($table);
        }
        $campaign = $migrations['stox_ml_acceptance_campaigns'] ? MlAcceptanceCampaign::query()->latest('created_at')->first() : null;
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

    /** Never expose host paths, raw provider identifiers, subprocess errors or payloads. */
    public function safe(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/path|stderr|unmapped_identifiers|failure|source_meta|raw_payload|(?:^|_)(?:error|exception|traceback)(?:$|_)/i', $key)) {
                continue;
            }
            $out[$key] = is_array($item) ? $this->safe($item) : $item;
        }

        return $out;
    }
}
