<?php
namespace App\Jobs;

use App\Services\ML\MlAcceptanceBackfillService;
use App\Services\ML\MlAcceptanceCampaignService;
use App\Services\ML\MlAcceptanceSourceService;
use App\Services\ML\MlAcceptanceRuntime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MlAcceptanceJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = MlAcceptanceRuntime::TIMEOUT;
    public array $backoff = [30, 120, 300];
    public function __construct(public string $operation, public string $identity) {}
    public function handle(): void
    {
        app(MlAcceptanceRuntime::class)->assertQueue();
        match ($this->operation) {
            'source' => app(MlAcceptanceSourceService::class)->validateQueued($this->identity),
            'backfill' => app(MlAcceptanceBackfillService::class)->step((int) $this->identity),
            'campaign' => app(MlAcceptanceCampaignService::class)->step($this->identity),
            default => throw new \LogicException('Unknown acceptance operation.'),
        };
    }
}
