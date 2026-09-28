<?php

namespace App\Jobs;

use App\Exceptions\MlTrainingRetryScheduledException;
use App\Exceptions\MlTrainingRunCancelledException;
use App\Models\User;
use App\Services\ML\MlScoringService;
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
    ) {}

    public function handle(MlScoringService $scoring): void
    {
        $user = $this->requestedByUserId ? User::query()->find($this->requestedByUserId) : null;
        $overrides = ['trigger' => $this->trigger];
        if ($this->driftCheckId !== null) {
            $overrides['drift_check_id'] = $this->driftCheckId;
        }

        try {
            $scoring->retrain($this->horizon, null, $user, $overrides, $this->trainingRunId);
        } catch (MlTrainingRunCancelledException|MlTrainingRetryScheduledException) {
            // Run row already updated (cancelled or queued for retry).
        }
    }
}
