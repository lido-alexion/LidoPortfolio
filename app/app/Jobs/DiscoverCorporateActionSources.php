<?php

namespace App\Jobs;

use App\Services\DataQualityCorporateActionAiDiscovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Supplementary source discovery must never hold up deterministic feed sync. */
class DiscoverCorporateActionSources implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** @param list<array{symbol:string,ex_date:string,action_type:string}> $events */
    public function __construct(public array $events, public ?string $detectionRunId = null) {}

    public function handle(DataQualityCorporateActionAiDiscovery $discovery): void
    {
        $discovery->discover($this->events, $this->detectionRunId);
    }
}
