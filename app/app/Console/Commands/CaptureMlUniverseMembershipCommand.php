<?php

namespace App\Console\Commands;

use App\Services\ML\MlHistoricalUniverseMembershipService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CaptureMlUniverseMembershipCommand extends Command
{
    protected $signature = 'ml:capture-universe-membership
        {--effective-from= : ISO date for the snapshot boundary; must be today}
        {--source=admin_snapshot : auditable source label}
        {--snapshot-key= : optional stable source snapshot identifier}';

    protected $description = 'Capture the current eligible NSE universe as a dated PIT ML membership snapshot';

    public function handle(MlHistoricalUniverseMembershipService $memberships): int
    {
        $effectiveFrom = Carbon::parse((string) ($this->option('effective-from') ?: now()->toDateString()));
        $result = $memberships->captureCurrentEligibleSnapshot(
            $effectiveFrom,
            (string) $this->option('source'),
            $this->option('snapshot-key') ?: null,
        );
        $this->info(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
