<?php

namespace App\Services\ML;

use App\Jobs\MlRetrainJob;
use App\Models\V7\MlTrainingRun;
use Carbon\Carbon;

/** FEAT-056 — recover durable runs left behind by a worker/process restart. */
class MlTrainingRunRecoveryService
{
    /**
     * @return list<array{run_id:int,horizon:string,action:string}>
     */
    public function recover(?Carbon $now = null, ?array $runIds = null): array
    {
        $now ??= now();
        $cutoff = $now->copy()->subMinutes(max(1, (int) config('ml_lifecycle.recovery.stale_after_minutes', 30)));
        $actions = [];

        MlTrainingRun::query()
            ->when($runIds !== null, fn ($query) => $query->whereIn('id', $runIds))
            ->whereIn('status', ['running', 'cancelling'])
            ->whereNotNull('started_at')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->get()
            ->each(function (MlTrainingRun $run) use ($now, &$actions): void {
                $lock = null;
                if (isset($run->configuration['acceptance'])) {
                    // A long canonical dataset/training job is not stale merely because 30 minutes elapsed.
                    if ($run->updated_at->greaterThanOrEqualTo($now->copy()->subSeconds(MlAcceptanceRuntime::TIMEOUT + 60))) return;
                    $lock = \Illuminate\Support\Facades\Cache::lock('stox-ml-retrain-'.$run->horizon, MlAcceptanceRuntime::TIMEOUT + 60);
                    if (! $lock->get()) return;
                    $run->refresh();
                    if (! in_array($run->status, ['running', 'cancelling'], true) || $run->updated_at->greaterThanOrEqualTo($now->copy()->subSeconds(MlAcceptanceRuntime::TIMEOUT + 60))) {
                        $lock->release();
                        return;
                    }
                }
                try {
                $configuration = is_array($run->configuration) ? $run->configuration : [];
                $wasCancelling = $run->status === 'cancelling';
                $recoveryAttempt = (int) ($configuration['recovery']['attempt'] ?? 0) + 1;
                if (isset($configuration['acceptance']) && ! $wasCancelling && $recoveryAttempt > 3) {
                    $run->forceFill(['status' => 'failed', 'completed_at' => $now, 'failure' => ['type' => 'acceptance_recovery_exhausted']])->save();
                    $actions[] = ['run_id' => $run->id, 'horizon' => $run->horizon, 'action' => 'recovery_exhausted'];
                    return;
                }
                $configuration['recovery'] = [
                    'reason' => 'worker_or_process_restart',
                    'attempt' => $recoveryAttempt,
                    'recovered_at' => $now->toIso8601String(),
                    'previous_status' => $run->status,
                ];
                $configuration['retry'] = array_merge(is_array($configuration['retry'] ?? null) ? $configuration['retry'] : [], [
                    'attempt' => max(1, (int) data_get($configuration, 'retry.attempt', 1)),
                ]);

                $run->forceFill([
                    'status' => $wasCancelling ? 'cancelled' : 'queued',
                    'configuration' => $configuration,
                    'failure' => $wasCancelling ? [
                        'message' => 'Training cancellation was finalized after the worker became stale.',
                        'type' => 'worker_restart_after_cancellation',
                    ] : [
                        'message' => 'Training worker became stale; run requeued for recovery.',
                        'type' => 'worker_restart',
                    ],
                    'completed_at' => $wasCancelling ? $now : null,
                ])->save();

                if ($wasCancelling) {
                    $actions[] = ['run_id' => $run->id, 'horizon' => $run->horizon, 'action' => 'cancelled_stale_run'];
                    return;
                }

                MlRetrainJob::dispatch(
                    $run->horizon,
                    $run->requested_by,
                    (string) ($configuration['trigger'] ?? 'recovery'),
                    isset($configuration['drift_check_id']) ? (int) $configuration['drift_check_id'] : null,
                    $run->id,
                );
                $actions[] = ['run_id' => $run->id, 'horizon' => $run->horizon, 'action' => 'requeued_stale_run'];
                } finally { $lock?->release(); }
            });

        return $actions;
    }
}
