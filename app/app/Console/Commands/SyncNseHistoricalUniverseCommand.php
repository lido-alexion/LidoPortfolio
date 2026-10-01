<?php

namespace App\Console\Commands;

use App\Services\ML\MlHistoricalReferenceDateService;
use App\Services\ML\MlHistoricalUniverseMembershipService;
use App\Services\ML\NseHistoricalUniverseArchiveProvider;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncNseHistoricalUniverseCommand extends Command
{
    protected $signature = 'ml:sync-nse-universe
        {--from= : Inclusive date; defaults to the last recorded boundary plus one day}
        {--to= : Inclusive date; defaults to the last completed equity session}
        {--attempts=3 : Bounded retries for late publications/outages}';

    protected $description = 'Acquire and validate dated official NSE universe files for completed sessions';

    public function handle(MlHistoricalUniverseMembershipService $memberships, NseHistoricalUniverseArchiveProvider $provider): int
    {
        $last = \App\Models\V8\MlUniverseSnapshotBoundary::query()
            ->where('universe_key', MlHistoricalUniverseMembershipService::ACTIVE_ELIGIBLE_NSE)
            ->max('effective_from');
        $from = Carbon::parse((string) ($this->option('from') ?: ($last ?: now()->subDays(7)->toDateString())))->addDay();
        $to = TradingCalendar::normalizeToSessionDate(Carbon::parse((string) ($this->option('to') ?: now()->toDateString())));
        $dates = [];
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if (TradingCalendar::isEquitySessionDate($date)) $dates[] = $date->toDateString();
        }
        if ($dates === []) {
            $this->info('No completed NSE sessions are due.');
            return self::SUCCESS;
        }

        $result = $memberships->backfillFromProvider(
            $dates,
            $provider,
            (string) config('ml.historical_universe.source', 'configured_authoritative_archive'),
            max(1, min((int) $this->option('attempts'), 5)),
        );
        $this->info(json_encode($result, JSON_THROW_ON_ERROR));

        return ($result['status'] ?? '') === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
