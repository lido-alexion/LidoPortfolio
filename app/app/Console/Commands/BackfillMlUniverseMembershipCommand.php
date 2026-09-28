<?php

namespace App\Console\Commands;

use App\Services\ML\MlHistoricalUniverseMembershipService;
use Illuminate\Console\Command;

class BackfillMlUniverseMembershipCommand extends Command
{
    protected $signature = 'ml:backfill-universe-membership
        {source-file : JSON file containing dated authoritative snapshots}
        {--source=historical_provider : auditable source label}
        {--run-id= : resume an existing backfill run}';

    protected $description = 'Backfill dated point-in-time ML universe membership snapshots without current-universe fallback';

    public function handle(MlHistoricalUniverseMembershipService $memberships): int
    {
        $path = (string) $this->argument('source-file');
        if (! is_file($path)) {
            $this->error("Historical universe source file does not exist: {$path}");

            return self::FAILURE;
        }

        try {
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $snapshots = is_array($payload['snapshots'] ?? null) ? $payload['snapshots'] : $payload;
            if (! is_array($snapshots)) {
                throw new \InvalidArgumentException('Historical universe source must contain a snapshots array.');
            }
            $result = $memberships->backfillHistoricalSnapshots(
                $snapshots,
                (string) $this->option('source'),
                $this->option('run-id') !== null ? (int) $this->option('run-id') : null,
            );
            $this->info(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
