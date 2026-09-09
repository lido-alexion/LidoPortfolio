<?php

namespace App\Console\Commands;

use App\Models\PortfolioProfile;
use App\Services\Artifacts\LegacyArtifactBackfillService;
use Illuminate\Console\Command;

class BackfillLegacyArtifactsCommand extends Command
{
    protected $signature = 'portfolio:backfill-reusable-artifacts
        {--profile= : Limit to one Portfolio profile id}
        {--dry-run : Validate and inventory the exact backfill, then roll back every write}';

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
        $dryRun = (bool) $this->option('dry-run');
        $result = $dryRun ? $service->preview($profile) : $service->backfill($profile);
        if ($dryRun) {
            $this->info("Artifact backfill dry run: {$result['created']} would be created; {$result['skipped']} already mapped; {$result['failed']} failed. No changes were committed.");
        } else {
            $this->info("Artifact backfill: {$result['created']} created; {$result['skipped']} skipped; {$result['failed']} failed.");
        }
        foreach ($result['failures'] as $failure) {
            $this->warn("{$failure['type']} #{$failure['legacy_id']}: {$failure['error']}");
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
