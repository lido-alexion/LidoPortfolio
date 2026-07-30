<?php

namespace App\Console\Commands;

use App\Services\DataQualityCorporateActionSyncService;
use Illuminate\Console\Command;

class SyncCorporateActionsCommand extends Command
{
    protected $signature = 'portfolio:sync-corporate-actions {--feed-url= : Override feed URL}';

    protected $description = 'Sync exchange corporate actions into Data Quality Center queue';

    public function handle(DataQualityCorporateActionSyncService $sync): int
    {
        $result = $sync->syncFromExchangeFeed(
            $this->option('feed-url') ? (string) $this->option('feed-url') : null,
        );

        $this->info(sprintf(
            'Corporate action sync done: feed rows=%d, created=%d, skipped=%d',
            $result['synced'],
            $result['created'],
            $result['skipped'],
        ));

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
