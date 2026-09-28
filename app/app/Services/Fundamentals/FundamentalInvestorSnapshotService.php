<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use App\Models\V7\FundamentalFact;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FundamentalInvestorSnapshotService
{
    /** @return list<string> */
    public static function summaryMetricIds(): array
    {
        return app(FundamentalMetricCatalog::class)->investorSummaryMetricIds();
    }

    /** @var list<string> */
    public const HISTORY_FACT_KEYS = [
        'revenue',
        'net_income',
        'operating_cash_flow',
        'capital_expenditure',
        'equity',
        'debt',
        'eps',
    ];

    public function __construct(
        protected FundamentalDataService $fundamentals,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Stock $stock, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $price = $this->latestMarketPrice($stock, $asOf);
        $basis = 'ttm';

        $summary = [];
        foreach (self::summaryMetricIds() as $metricId) {
            $row = $metricId === 'revenue_growth_yoy'
                ? $this->fundamentals->growthMetric($stock, 'revenue', FundamentalDataService::CADENCE_QUARTERLY, $asOf)
                : $this->fundamentals->metric($stock, $metricId, $basis, $asOf, $price);
            $summary[] = [
                'id' => $metricId,
                'label' => $this->metricLabel($metricId),
                'value' => $row['value'],
                'basis' => $metricId === 'revenue_growth_yoy' ? 'quarterly_yoy' : $basis,
                'valid_for_live_decision' => $row['valid_for_live_decision'] ?? ($row['value'] !== null),
                'freshness' => $row['freshness'] ?? null,
                'provenance' => $row['provenance'] ?? ($metricId === 'revenue_growth_yoy' ? [
                    'basis' => 'quarterly_yoy',
                    'source_label' => 'Derived by StoX',
                    'derived' => true,
                ] : null),
            ];
        }

        return [
            'stock' => ['id' => $stock->id, 'symbol' => $stock->symbol, 'name' => $stock->name],
            'as_of' => $asOf->toDateString(),
            'market_price' => $price,
            'price_source' => $price !== null ? 'latest_adjusted_close' : null,
            'coverage' => $this->coverageSummary($stock, $asOf),
            'freshness' => $this->sectionFreshness($stock, $asOf),
            'summary' => $summary,
            'metrics' => collect(self::summaryMetricIds())
                ->map(fn (string $metric) => $this->fundamentals->metric($stock, $metric, $basis, $asOf, $price))
                ->values()
                ->all(),
            'statements' => [
                'quarterly' => $this->latestStatementSlice($stock, FundamentalDataService::CADENCE_QUARTERLY, $asOf),
                'annual' => $this->latestStatementSlice($stock, FundamentalDataService::CADENCE_ANNUAL, $asOf),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function statementHistory(Stock $stock, string $cadence, Carbon $asOf, int $maxPeriods = 8): array
    {
        $rows = FundamentalFact::query()
            ->where('stock_id', $stock->id)
            ->where('cadence', $cadence)
            ->where('is_current', true)
            ->whereDate('availability_date', '<=', $asOf->toDateString())
            ->orderByDesc('period_end')
            ->orderByDesc('revision_number')
            ->get();

        $byPeriod = [];
        $presentKeys = [];
        foreach ($rows as $fact) {
            $period = $fact->period_end?->toDateString();
            if ($period === null) {
                continue;
            }
            $presentKeys[$fact->fact_key] = true;
            if (! isset($byPeriod[$period][$fact->fact_key])) {
                $byPeriod[$period][$fact->fact_key] = $fact;
            }
        }

        $periods = collect(array_keys($byPeriod))
            ->sortDesc()
            ->take($maxPeriods)
            ->values()
            ->all();

        $orderedKeys = $this->orderedHistoryFactKeys(array_keys($presentKeys));
        $basicKeys = config('fundamentals_ui.basic_fact_keys', self::HISTORY_FACT_KEYS);

        $tableRows = [];
        foreach ($orderedKeys as $factKey) {
            $cells = [];
            foreach ($periods as $period) {
                $fact = $byPeriod[$period][$factKey] ?? null;
                $yoyPct = null;
                if ($cadence === FundamentalDataService::CADENCE_QUARTERLY && $fact?->value !== null) {
                    $priorPeriod = $this->yearAgoPeriodEnd($period);
                    $prior = $priorPeriod !== null ? ($byPeriod[$priorPeriod][$factKey] ?? null) : null;
                    if ($prior?->value !== null && (float) $prior->value !== 0.0) {
                        $yoyPct = round(
                            (((float) $fact->value - (float) $prior->value) / abs((float) $prior->value)) * 100,
                            2,
                        );
                    }
                }
                $cells[] = [
                    'period_end' => $period,
                    'value' => $fact?->value !== null ? (float) $fact->value : null,
                    'yoy_pct' => $yoyPct,
                    'provider' => $fact?->provider,
                ];
            }
            $tableRows[] = [
                'fact_key' => $factKey,
                'label' => $this->factLabel($factKey),
                'section' => in_array($factKey, $basicKeys, true) ? 'basic' : 'advanced',
                'cells' => $cells,
            ];
        }

        return [
            'stock_id' => $stock->id,
            'cadence' => $cadence,
            'as_of' => $asOf->toDateString(),
            'periods' => $periods,
            'rows' => $tableRows,
            'sections' => [
                'basic' => array_values(array_filter($tableRows, fn (array $r) => $r['section'] === 'basic')),
                'advanced' => array_values(array_filter($tableRows, fn (array $r) => $r['section'] === 'advanced')),
            ],
        ];
    }

    /**
     * Point-in-time metric series at each quarterly availability boundary (TTM basis).
     *
     * @return array<string, mixed>
     */
    public function metricHistory(
        Stock $stock,
        string $metricId,
        Carbon $asOf,
        string $range = '5y',
        string $frequency = 'default',
    ): array {
        $catalog = app(FundamentalMetricCatalog::class);
        if ($catalog->derivedMetric($metricId) === null) {
            return [
                'metric' => $metricId,
                'label' => $this->metricLabel($metricId),
                'as_of' => $asOf->toDateString(),
                'range' => $range,
                'points' => [],
                'error' => 'unsupported_metric',
            ];
        }

        $resolvedFrequency = $frequency === 'default'
            ? $catalog->defaultChartFrequency($metricId)
            : $frequency;

        if ($catalog->isValuationMetric($metricId) && in_array($resolvedFrequency, ['daily', 'monthly'], true)) {
            return $this->valuationMetricHistory($stock, $metricId, $asOf, $range, $resolvedFrequency);
        }

        $cutoff = $this->rangeCutoff($asOf, $range);

        $periodRows = DB::table('stox_fundamental_facts')
            ->where('stock_id', $stock->id)
            ->where('cadence', FundamentalDataService::CADENCE_QUARTERLY)
            ->where('is_current', true)
            ->whereDate('availability_date', '<=', $asOf->toDateString())
            ->whereDate('period_end', '>=', $cutoff->toDateString())
            ->selectRaw('period_end, MAX(availability_date) as pit_date')
            ->groupBy('period_end')
            ->orderBy('period_end')
            ->get();

        $points = [];
        foreach ($periodRows as $row) {
            $pit = Carbon::parse((string) $row->pit_date);
            $price = $this->latestMarketPrice($stock, $pit);
            $computed = $this->fundamentals->metric($stock, $metricId, 'ttm', $pit, $price);
            $points[] = [
                'period_end' => (string) $row->period_end,
                'as_of' => $pit->toDateString(),
                'value' => $computed['value'] !== null ? (float) $computed['value'] : null,
            ];
        }

        return [
            'metric' => $metricId,
            'label' => $this->metricLabel($metricId),
            'basis' => 'ttm',
            'frequency' => 'quarterly',
            'as_of' => $asOf->toDateString(),
            'range' => $range,
            'points' => $points,
        ];
    }

    /**
     * Daily PIT valuation series (adjusted prices); monthly is last trading day per calendar month.
     *
     * @return array<string, mixed>
     */
    protected function valuationMetricHistory(
        Stock $stock,
        string $metricId,
        Carbon $asOf,
        string $range,
        string $frequency,
    ): array {
        $cutoff = $this->rangeCutoff($asOf, $range);
        $priceRows = DB::table('portfolio_stock_prices')
            ->where('stock_id', $stock->id)
            ->whereDate('price_date', '>=', $cutoff->toDateString())
            ->whereDate('price_date', '<=', $asOf->toDateString())
            ->orderBy('price_date')
            ->get(['price_date', 'adjusted_close_price']);

        $daily = [];
        foreach ($priceRows as $row) {
            $pit = Carbon::parse((string) $row->price_date);
            $price = $row->adjusted_close_price !== null ? (float) $row->adjusted_close_price : null;
            if ($price === null || $price <= 0) {
                continue;
            }
            $computed = $this->fundamentals->metric($stock, $metricId, 'ttm', $pit, $price);
            $daily[] = [
                'as_of' => $pit->toDateString(),
                'value' => $computed['value'] !== null ? (float) $computed['value'] : null,
            ];
        }

        $points = $frequency === 'daily'
            ? $daily
            : $this->aggregateValuationMonthly($daily);

        return [
            'metric' => $metricId,
            'label' => $this->metricLabel($metricId),
            'basis' => 'ttm',
            'frequency' => $frequency,
            'price_basis' => 'adjusted_close',
            'as_of' => $asOf->toDateString(),
            'range' => $range,
            'points' => $points,
        ];
    }

    /**
     * @param  list<array{as_of: string, value: float|null}>  $daily
     * @return list<array{as_of: string, value: float|null}>
     */
    protected function aggregateValuationMonthly(array $daily): array
    {
        $byMonth = [];
        foreach ($daily as $point) {
            $month = substr($point['as_of'], 0, 7);
            $byMonth[$month] = $point;
        }

        return array_values($byMonth);
    }

    protected function rangeCutoff(Carbon $asOf, string $range): Carbon
    {
        return match ($range) {
            '1y' => $asOf->copy()->subYear(),
            '3y' => $asOf->copy()->subYears(3),
            '10y' => $asOf->copy()->subYears(10),
            default => $asOf->copy()->subYears(5),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function coverageSummary(Stock $stock, Carbon $asOf): array
    {
        return [
            'quarterly' => $this->cadenceCoverage($stock, FundamentalDataService::CADENCE_QUARTERLY, $asOf),
            'annual' => $this->cadenceCoverage($stock, FundamentalDataService::CADENCE_ANNUAL, $asOf),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function cadenceCoverage(Stock $stock, string $cadence, Carbon $asOf): array
    {
        $agg = DB::table('stox_fundamental_facts')
            ->where('stock_id', $stock->id)
            ->where('cadence', $cadence)
            ->where('is_current', true)
            ->whereDate('availability_date', '<=', $asOf->toDateString())
            ->selectRaw('COUNT(DISTINCT period_end) as periods, MIN(period_end) as earliest, MAX(period_end) as latest')
            ->first();

        return [
            'period_count' => (int) ($agg->periods ?? 0),
            'earliest_period' => $agg->earliest,
            'latest_period' => $agg->latest,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sectionFreshness(Stock $stock, Carbon $asOf): array
    {
        $quarterly = $this->fundamentals->freshness(
            $this->fundamentals->factMap($stock, FundamentalDataService::CADENCE_QUARTERLY, $asOf),
            FundamentalDataService::CADENCE_QUARTERLY,
            $asOf,
        );
        $annual = $this->fundamentals->freshness(
            $this->fundamentals->factMap($stock, FundamentalDataService::CADENCE_ANNUAL, $asOf),
            FundamentalDataService::CADENCE_ANNUAL,
            $asOf,
        );

        $status = in_array('stale', [$quarterly['status'], $annual['status']], true) ? 'stale' : (
            in_array('missing', [$quarterly['status'], $annual['status']], true) ? 'missing' : 'fresh'
        );

        return [
            'status' => $status,
            'quarterly' => $quarterly,
            'annual' => $annual,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function latestStatementSlice(Stock $stock, string $cadence, Carbon $asOf): array
    {
        return collect($this->fundamentals->factMap($stock, $cadence, $asOf))
            ->map(fn (FundamentalFact $fact) => [
                'fact_key' => $fact->fact_key,
                'label' => $this->factLabel($fact->fact_key),
                'value' => $fact->value !== null ? (float) $fact->value : null,
                'period_end' => $fact->period_end?->toDateString(),
                'provider' => $fact->provider,
            ])
            ->values()
            ->all();
    }

    public function latestMarketPrice(Stock $stock, Carbon $asOf): ?float
    {
        $row = DB::table('portfolio_stock_prices')
            ->where('stock_id', $stock->id)
            ->whereDate('price_date', '<=', $asOf->toDateString())
            ->orderByDesc('price_date')
            ->first(['adjusted_close_price', 'close_price']);

        if ($row === null) {
            return null;
        }

        $adj = $row->adjusted_close_price ?? $row->close_price;

        return is_numeric($adj) ? (float) $adj : null;
    }

    protected function metricLabel(string $metricId): string
    {
        return app(FundamentalMetricCatalog::class)->derivedMetricLabel($metricId);
    }

    protected function factLabel(string $factKey): string
    {
        return app(FundamentalMetricCatalog::class)->primaryFactLabel($factKey);
    }

    /**
     * @param  list<string>  $presentKeys
     * @return list<string>
     */
    protected function yearAgoPeriodEnd(string $periodEnd): ?string
    {
        try {
            return Carbon::parse($periodEnd)->subYear()->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $presentKeys
     * @return list<string>
     */
    protected function orderedHistoryFactKeys(array $presentKeys): array
    {
        $order = config('fundamentals_ui.statement_fact_order', self::HISTORY_FACT_KEYS);
        $basic = config('fundamentals_ui.basic_fact_keys', self::HISTORY_FACT_KEYS);
        $ordered = [];
        foreach ($order as $key) {
            if (in_array($key, $presentKeys, true)) {
                $ordered[] = $key;
            }
        }
        $remaining = array_diff($presentKeys, $ordered);
        sort($remaining);

        return array_values(array_merge($ordered, $remaining));
    }
}
