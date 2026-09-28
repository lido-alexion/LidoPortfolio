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
    public function recover(?Carbon $now = null): array
    {
        $now ??= now();
        $cutoff = $now->copy()->subMinutes(max(1, (int) config('ml_lifecycle.recovery.stale_after_minutes', 30)));
        $actions = [];

        MlTrainingRun::query()
            ->whereIn('status', ['running', 'cancelling'])
            ->whereNotNull('started_at')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->get()
            ->each(function (MlTrainingRun $run) use ($now, &$actions): void {
                $configuration = is_array($run->configuration) ? $run->configuration : [];
                $configuration['recovery'] = [
                    'reason' => 'worker_or_process_restart',
                    'recovered_at' => $now->toIso8601String(),
                    'previous_status' => $run->status,
                ];
                $configuration['retry'] = array_merge(is_array($configuration['retry'] ?? null) ? $configuration['retry'] : [], [
                    'attempt' => max(1, (int) data_get($configuration, 'retry.attempt', 1)),
                ]);

                $run->forceFill([
                    'status' => 'queued',
                    'configuration' => $configuration,
                    'failure' => [
                        'message' => 'Training worker became stale; run requeued for recovery.',
                        'type' => 'worker_restart',
                    ],
                    'completed_at' => null,
                ])->save();

                MlRetrainJob::dispatch(
                    $run->horizon,
                    $run->requested_by,
                    (string) ($configuration['trigger'] ?? 'recovery'),
                    isset($configuration['drift_check_id']) ? (int) $configuration['drift_check_id'] : null,
                    $run->id,
                );
                $actions[] = ['run_id' => $run->id, 'horizon' => $run->horizon, 'action' => 'requeued_stale_run'];
            });

        return $actions;
    }
}
