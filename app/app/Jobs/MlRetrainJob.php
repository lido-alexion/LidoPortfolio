<?php

namespace App\Jobs;

use App\Exceptions\MlTrainingRetryScheduledException;
use App\Exceptions\MlTrainingRunCancelledException;
use App\Models\User;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlAcceptanceRuntime;
use App\Services\ML\MlScoringService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MlRetrainJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $horizon,
        public ?int $requestedByUserId = null,
        public string $trigger = 'scheduled',
        public ?int $driftCheckId = null,
        public ?int $trainingRunId = null,
    ) {
        if ($trainingRunId && isset(MlTrainingRun::query()->find($trainingRunId)?->configuration['acceptance'])) {
            $this->timeout = MlAcceptanceRuntime::TIMEOUT;
            $this->onConnection(MlAcceptanceRuntime::CONNECTION)
                ->onQueue(MlAcceptanceRuntime::QUEUE);
        }
    }

    public ?int $timeout = null;

    public function handle(MlScoringService $scoring): void
    {
        $run = $this->trainingRunId ? MlTrainingRun::query()->find($this->trainingRunId) : null;
        $acceptance = $run?->configuration['acceptance'] ?? null;
        if ($acceptance !== null) {
            app(MlAcceptanceRuntime::class)->assertQueue();
        }
        if ($acceptance !== null && $run->status !== 'queued') {
            if (! in_array($run->status, ['running', 'cancelling'], true)) {
                MlAcceptanceJob::dispatch('campaign', $acceptance['campaign_id']);
            }

            return;
        }
        $user = $this->requestedByUserId ? User::query()->find($this->requestedByUserId) : null;
        $overrides = ['trigger' => $this->trigger];
        if ($this->driftCheckId !== null) {
            $overrides['drift_check_id'] = $this->driftCheckId;
        }

        try {
            $scoring->retrain($this->horizon, $acceptance ? Carbon::parse($acceptance['cutoff_date']) : null, $user, $overrides, $this->trainingRunId);
        } catch (MlTrainingRunCancelledException|MlTrainingRetryScheduledException) {
            // Run row already updated (cancelled or queued for retry).
        } finally {
            if ($acceptance !== null) {
                MlAcceptanceJob::dispatch('campaign', $acceptance['campaign_id']);
            }
        }
    }
}
