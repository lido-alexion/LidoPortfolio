<?php

namespace App\Console\Commands;

use App\Services\ML\ConfiguredHistoricalUniverseProvider;
use App\Services\ML\MlHistoricalUniverseMembershipService;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;

class BackfillMlUniverseProviderCommand extends Command
{
    protected $signature = 'ml:backfill-universe-provider
        {--from= : Inclusive ISO date}
        {--to= : Inclusive ISO date}
        {--dates= : Comma-separated explicit ISO dates}
        {--attempts=3 : Maximum attempts for retryable provider failures}';

    protected $description = 'Backfill dated ML universe snapshots from the configured authoritative archive provider';

    public function handle(MlHistoricalUniverseMembershipService $memberships, ConfiguredHistoricalUniverseProvider $provider): int
    {
        $dates = $this->option('dates')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('dates')))))
            : iterator_to_array(CarbonPeriod::create($this->option('from'), $this->option('to')));
        $dates = array_map(static fn ($date): string => \Carbon\Carbon::parse($date)->toDateString(), $dates);
        if ($dates === []) {
            $this->error('Provide --dates or both --from and --to.');
            return self::FAILURE;
        }
        try {
            $result = $memberships->backfillFromProvider($dates, $provider, (string) config('ml.historical_universe.source'), (int) $this->option('attempts'));
            $this->info(json_encode($result, JSON_THROW_ON_ERROR));
            return ($result['status'] ?? '') === 'completed' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
