<?php

namespace App\Services;

use App\Models\DataQualityIssue;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only operational view of data readiness; it never makes data complete. */
class DataCompletenessService
{
    public function __construct(
        protected \App\Services\ML\MlHistoricalUniverseMembershipService $memberships,
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

        return [
            'as_of' => $asOf->toIso8601String(),
            'datasets' => [
                'nse_membership' => ['freshness' => $membership['missing_dates'] === [] ? 'complete' : 'incomplete', 'coverage' => $membership['coverage_percentage'], 'backlog' => count($membership['missing_dates']), 'last_successful_ingestion' => DB::table('stox_ml_universe_snapshot_boundaries')->max('updated_at')],
                'daily_prices' => ['freshness' => $priceCount >= $eligible ? 'complete' : 'incomplete', 'coverage' => $eligible ? round($priceCount / $eligible * 100, 4) : 0.0, 'backlog' => max(0, $eligible - $priceCount), 'last_successful_ingestion' => $lastSession->toDateString()],
                'fundamentals' => ['freshness' => $fundamentalChecks >= $eligible ? 'complete' : 'incomplete', 'coverage' => $eligible ? round($fundamentalChecks / $eligible * 100, 4) : 0.0, 'backlog' => max(0, $eligible - $fundamentalChecks), 'last_successful_ingestion' => DB::table('stox_fundamental_provider_checks')->max('last_successful_check_at')],
                'corporate_actions' => ['freshness' => $latestCorporate !== null ? 'observed' : 'unknown', 'coverage' => null, 'backlog' => null, 'last_successful_ingestion' => $latestCorporate],
            ],
        ];
    }

    public function isComplete(array $report): bool
    {
        return collect($report['datasets'] ?? [])->except(['corporate_actions'])->every(fn ($dataset) => ($dataset['freshness'] ?? null) === 'complete');
    }
}
