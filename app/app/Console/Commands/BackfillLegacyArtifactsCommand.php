<?php

namespace App\Console\Commands;

use App\Models\PortfolioProfile;
use App\Services\Artifacts\LegacyArtifactBackfillService;
use Illuminate\Console\Command;

class BackfillLegacyArtifactsCommand extends Command
{
    protected $signature = 'portfolio:backfill-reusable-artifacts {--profile= : Limit to one Portfolio profile id}';

    protected $description = 'Idempotently map legacy Screeners and Strategies into immutable reusable artifacts and bindings';

    public function handle(LegacyArtifactBackfillService $service): int
    {
        $profile = $this->option('profile') !== null
            ? PortfolioProfile::query()->find($this->option('profile'))
            : null;
        if ($this->option('profile') !== null && ! $profile) {
            $this->error('Portfolio profile was not found.');

            return self::FAILURE;
        }
        $result = $service->backfill($profile);
        $this->info("Artifact backfill: {$result['created']} created; {$result['skipped']} skipped; {$result['failed']} failed.");
        foreach ($result['failures'] as $failure) {
            $this->warn("{$failure['type']} #{$failure['legacy_id']}: {$failure['error']}");
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
