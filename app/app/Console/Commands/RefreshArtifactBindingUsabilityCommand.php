<?php

namespace App\Console\Commands;

use App\Services\Artifacts\ArtifactBindingUsabilityService;
use Illuminate\Console\Command;

class RefreshArtifactBindingUsabilityCommand extends Command
{
    protected $signature = 'portfolio:refresh-artifact-binding-usability';

    protected $description = 'Re-evaluate pinned trading artifact dependencies and resolve blocked-binding notifications';

    public function handle(ArtifactBindingUsabilityService $service): int
    {
        $result = $service->refreshAll();
        $this->info(sprintf(
            'Artifact bindings: %d checked; %d usable; %d warning; %d blocked.',
            $result['checked'],
            $result['usable'],
            $result['warning'],
            $result['blocked'],
        ));

        return self::SUCCESS;
    }
}
