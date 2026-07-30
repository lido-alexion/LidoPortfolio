<?php

namespace App\Console\Commands;

use App\Services\DataQualityLegacyCorporateActionMigrationService;
use Illuminate\Console\Command;

class MigrateLegacyCorporateActionsCommand extends Command
{
    protected $signature = 'portfolio:migrate-legacy-corporate-actions {--apply : Persist changes (default dry-run)}';

    protected $description = 'Convert legacy manual split/bonus records into Data Quality issues';

    public function handle(DataQualityLegacyCorporateActionMigrationService $service): int
    {
        $dryRun = ! $this->option('apply');
        $result = $service->migrateAppliedActions($dryRun);

        $this->info(sprintf(
            'Legacy migration %s: scanned=%d, migrated=%d, skipped=%d',
            $dryRun ? '(dry-run)' : '(applied)',
            $result['scanned'],
            $result['migrated'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
