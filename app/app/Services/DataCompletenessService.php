<?php

namespace App\Services;

use App\Models\DataQualityIssue;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Support\TradingCalendar;
use App\Services\ML\IntradayHistoricalPlatformService;
use App\Services\Microstructure\MicrostructureCollectorControlService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only operational view of data readiness; it never makes data complete. */
class DataCompletenessService
{
    public function __construct(
        protected \App\Services\ML\MlHistoricalUniverseMembershipService $memberships,
        protected IntradayHistoricalPlatformService $intraday,
        protected MicrostructureCollectorControlService $microstructure,
    ) {}

    /** @return array<string,mixed> */
    public function report(?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $lastSession = TradingCalendar::lastRequiredPriceSession($asOf);
        $dates = [];
        for ($date = $lastSession->copy()->subDays(29); $date->lte($lastSession); $date->addDay()) {
            if (TradingCalendar::isEquitySessionDate($date)) $dates[] = $date->toDateString();
        }
        $membership = $this->memberships->coverageForDates($dates);
        $eligible = Stock::query()->effectivelyActive()->where('exchange', 'NSE')->where('is_benchmark', false)->count();
        $priceCount = StockPrice::query()->whereDate('price_date', $lastSession->toDateString())->whereHas('stock', fn ($q) => $q->where('exchange', 'NSE')->where('is_benchmark', false))->distinct('stock_id')->count('stock_id');
        $fundamentalChecks = DB::table('stox_fundamental_provider_checks')->where('last_successful_check_at', '>=', $asOf->copy()->subMonths(15))->distinct('stock_id')->count('stock_id');
        $latestCorporate = DataQualityIssue::query()->where('issue_type', 'corporate_action')->max('detected_at');
        $intraday = $this->intraday->status();
        $microstructure = $this->microstructure->operationalStatus();

        return [
            'as_of' => $asOf->toIso8601String(),
            'datasets' => [
                'nse_membership' => ['freshness' => $membership['missing_dates'] === [] ? 'complete' : 'incomplete', 'coverage' => $membership['coverage_percentage'], 'backlog' => count($membership['missing_dates']), 'last_successful_ingestion' => DB::table('stox_ml_universe_snapshot_boundaries')->max('updated_at')],
                'daily_prices' => ['freshness' => $priceCount >= $eligible ? 'complete' : 'incomplete', 'coverage' => $eligible ? round($priceCount / $eligible * 100, 4) : 0.0, 'backlog' => max(0, $eligible - $priceCount), 'last_successful_ingestion' => $lastSession->toDateString()],
                'fundamentals' => ['freshness' => $fundamentalChecks >= $eligible ? 'complete' : 'incomplete', 'coverage' => $eligible ? round($fundamentalChecks / $eligible * 100, 4) : 0.0, 'backlog' => max(0, $eligible - $fundamentalChecks), 'last_successful_ingestion' => DB::table('stox_fundamental_provider_checks')->max('last_successful_check_at')],
                'corporate_actions' => ['freshness' => $latestCorporate !== null ? 'observed' : 'unknown', 'coverage' => null, 'backlog' => null, 'last_successful_ingestion' => $latestCorporate],
                // Intraday collection is deliberately diagnostic only and never
                // gates daily ML readiness.
                'microstructure_intraday' => ['freshness' => 'optional', 'coverage' => null, 'backlog' => null, 'last_successful_ingestion' => null],
                'minute_corpus_feat_065' => ['freshness' => ($intraday['enabled'] ?? false) ? 'observed' : 'disabled', 'coverage' => null, 'backlog' => array_sum(array_map('intval', (array) ($intraday['checkpoint_counts'] ?? []))), 'last_successful_ingestion' => null, 'owner_status' => $intraday],
                'live_microstructure_feat_063' => ['freshness' => ($microstructure['enabled'] ?? false) ? 'observed' : 'disabled', 'coverage' => $microstructure['coverage_summary']['coverage_percent'] ?? null, 'backlog' => null, 'last_successful_ingestion' => $microstructure['latest_finalized_partition'] ?? null, 'owner_status' => $microstructure],
            ],
        ];
    }

    public function isComplete(array $report): bool
    {
        return collect($report['datasets'] ?? [])->except(['microstructure_intraday', 'minute_corpus_feat_065', 'live_microstructure_feat_063', 'corporate_actions'])->every(fn ($dataset) => ($dataset['freshness'] ?? null) === 'complete');
    }
}
