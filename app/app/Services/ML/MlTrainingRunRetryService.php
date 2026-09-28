<?php

namespace App\Services\ML;

use App\Exceptions\MlTrainingRetryScheduledException;
use App\Exceptions\MlTrainingRunCancelledException;
use App\Jobs\MlRetrainJob;
use App\Models\User;
use App\Models\V7\MlTrainingRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

class MlTrainingRunRetryService
{
    public function __construct(
        protected MlTrainingRunProgressService $progress,
    ) {}

    /**
     * @throws MlTrainingRetryScheduledException
     */
    public function scheduleIfTransient(
        MlTrainingRun $run,
        string $horizon,
        ?User $user,
        \Throwable $exception,
    ): void {
        if (! $this->isTransientFailure($exception)) {
            return;
        }

        $configuration = is_array($run->configuration) ? $run->configuration : [];
        $attempt = (int) ($configuration['retry']['attempt'] ?? 1);
        $maxAttempts = max(1, (int) config('ml_lifecycle.retry.max_attempts', 3));
        if ($attempt >= $maxAttempts) {
            return;
        }

        $backoffs = config('ml_lifecycle.retry.backoff_seconds', [60, 300, 900]);
        $delay = (int) ($backoffs[$attempt - 1] ?? (is_array($backoffs) ? end($backoffs) : 60));

        $configuration['retry'] = [
            'attempt' => $attempt + 1,
            'max_attempts' => $maxAttempts,
            'scheduled_at' => now()->addSeconds($delay)->toIso8601String(),
            'last_error' => substr($exception->getMessage(), 0, 500),
            'last_error_type' => get_class($exception),
        ];

        $run->forceFill([
            'status' => 'queued',
            'configuration' => $configuration,
            'failure' => [
                'message' => 'Transient failure; retry scheduled.',
                'type' => 'transient',
                'attempt' => $attempt,
            ],
            'completed_at' => null,
        ])->save();

        $this->progress->record($run, 'retry_scheduled', min(90, 10 + ($attempt * 10)), [
            'delay_seconds' => $delay,
            'next_attempt' => $attempt + 1,
        ]);

        MlRetrainJob::dispatch(
            $horizon,
            $user?->id,
            (string) ($configuration['trigger'] ?? 'manual'),
            isset($configuration['drift_check_id']) ? (int) $configuration['drift_check_id'] : null,
            $run->id,
        )->delay(now()->addSeconds($delay));

        throw new MlTrainingRetryScheduledException($run->id);
    }

    public function isTransientFailure(\Throwable $exception): bool
    {
        if ($exception instanceof MlTrainingRunCancelledException
            || $exception instanceof ValidationException
            || $exception instanceof MlTrainingRetryScheduledException) {
            return false;
        }

        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof \InvalidArgumentException) {
            return false;
        }

        $message = strtolower($exception->getMessage());
        foreach ([
            'connection',
            'timeout',
            'temporarily unavailable',
            'ml adapter',
            'broken pipe',
            'could not connect',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
