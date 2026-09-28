<?php

namespace App\Services\ML;

use App\Exceptions\MlTrainingRunCancelledException;
use App\Models\User;
use App\Models\V7\MlTrainingRun;
use Illuminate\Validation\ValidationException;

class MlTrainingRunCancellationService
{
    public function __construct(
        protected MlTrainingRunProgressService $progress,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function request(MlTrainingRun $run, ?User $actor, ?string $reason = null): array
    {
        $run->refresh();
        if (in_array($run->status, ['cancelled', 'completed', 'failed'], true)) {
            throw ValidationException::withMessages([
                'run' => ['This training run is already finished and cannot be cancelled.'],
            ]);
        }

        if ($run->status === 'queued') {
            return $this->finalizeCancelled($run, $actor, $reason, immediate: true);
        }

        if ($run->status === 'cancelling') {
            return $this->payload($run);
        }

        if ($run->status !== 'running') {
            throw ValidationException::withMessages([
                'run' => ['Only queued or running training runs can be cancelled.'],
            ]);
        }

        $configuration = $run->configuration ?? [];
        $configuration['cancellation'] = [
            'requested' => true,
            'requested_at' => now()->toIso8601String(),
            'requested_by' => $actor?->id,
            'reason' => $reason,
        ];
        $run->forceFill([
            'status' => 'cancelling',
            'configuration' => $configuration,
        ])->save();
        $this->progress->record($run, 'cancelling', (int) ($configuration['progress']['percent'] ?? 0));

        return $this->payload($run);
    }

    /**
     * @throws MlTrainingRunCancelledException
     */
    public function assertContinueOrAbort(MlTrainingRun $run): void
    {
        $run->refresh();
        if ($run->status === 'cancelled') {
            throw new MlTrainingRunCancelledException($run->id);
        }
        if ($run->status === 'cancelling' || ($run->configuration['cancellation']['requested'] ?? false) === true) {
            $this->finalizeCancelled($run, null, (string) ($run->configuration['cancellation']['reason'] ?? null), immediate: false);

            throw new MlTrainingRunCancelledException($run->id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function finalizeCancelled(MlTrainingRun $run, ?User $actor, ?string $reason, bool $immediate): array
    {
        $configuration = $run->configuration ?? [];
        $configuration['cancellation'] = array_merge($configuration['cancellation'] ?? [], [
            'requested' => true,
            'requested_at' => $configuration['cancellation']['requested_at'] ?? now()->toIso8601String(),
            'requested_by' => $configuration['cancellation']['requested_by'] ?? $actor?->id,
            'reason' => $reason ?? ($configuration['cancellation']['reason'] ?? null),
            'completed_at' => now()->toIso8601String(),
            'immediate' => $immediate,
        ]);

        $run->forceFill([
            'status' => 'cancelled',
            'configuration' => $configuration,
            'completed_at' => now(),
        ])->save();
        $this->progress->record($run, 'cancelled', 100);

        return $this->payload($run);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(MlTrainingRun $run): array
    {
        return [
            'run_id' => $run->id,
            'horizon' => $run->horizon,
            'status' => $run->status,
            'cancellation' => $run->configuration['cancellation'] ?? null,
        ];
    }
}
