<?php

namespace App\Console\Commands;

use App\Services\ML\MlHistoricalUniverseMembershipService;
use App\Services\ML\NseHistoricalUniverseArchiveProvider;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;

class BackfillNseHistoricalUniverseCommand extends Command
{
    protected $signature = 'ml:backfill-nse-universe
        {--from= : Inclusive ISO date}
        {--to= : Inclusive ISO date}
        {--dates= : Comma-separated explicit ISO dates}
        {--mii-path= : Directory/file containing dated NSE MII security files}
        {--bhavcopy-path= : Directory/file containing dated NSE cash-market bhavcopies}
        {--attempts=3 : Maximum attempts for unavailable source dates}
        {--run-id= : Resume an existing durable backfill run}';

    protected $description = 'Build and backfill PIT active_eligible_nse snapshots from dated NSE MII/bhavcopy files';

    public function handle(MlHistoricalUniverseMembershipService $memberships, NseHistoricalUniverseArchiveProvider $provider): int
    {
        if ($this->option('mii-path')) config(['ml.historical_universe.mii_path' => (string) $this->option('mii-path')]);
        if ($this->option('bhavcopy-path')) config(['ml.historical_universe.bhavcopy_path' => (string) $this->option('bhavcopy-path')]);
        $dates = $this->option('dates')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('dates')))))
            : (($this->option('from') && $this->option('to')) ? iterator_to_array(CarbonPeriod::create($this->option('from'), $this->option('to'))) : []);
        $dates = array_map(static fn ($date): string => Carbon::parse($date)->toDateString(), $dates);
        if ($dates === []) {
            $this->error('Provide --dates or both --from and --to.');
            return self::FAILURE;
        }
        try {
            $result = $memberships->backfillFromProvider(
                $dates,
                $provider,
                'nse_pit_historical_archive',
                (int) $this->option('attempts'),
                MlHistoricalUniverseMembershipService::ACTIVE_ELIGIBLE_NSE,
                $this->option('run-id') !== null ? (int) $this->option('run-id') : null,
            );
            $this->info(json_encode($result, JSON_THROW_ON_ERROR));
            return ($result['status'] ?? '') === 'completed' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
