<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use App\Models\V7\FundamentalFact;
use App\Models\V7\FundamentalSetting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FundamentalDataService
{
    public const CADENCE_QUARTERLY = 'quarterly';

    public const CADENCE_ANNUAL = 'annual';

    public function resolvedAiInsightsPrimaryProvider(): string
    {
        $override = $this->settings()->ai_insights_primary_provider;
        if (in_array($override, ['gemini', 'codex'], true)) {
            return $override;
        }

        return (string) config('fundamentals_ai.primary_provider', 'gemini');
    }

    public function settings(): FundamentalSetting
    {
        return FundamentalSetting::query()->firstOrCreate([], [
            'quarterly_freshness_months' => 5,
            'annual_freshness_months' => 15,
            'request_delay_ms' => 750,
            'max_attempts' => 3,
            'provider' => 'yahoo',
            'paused' => false,
            'nse_official_fallback_enabled' => false,
            'bse_official_fallback_enabled' => false,
            'nse_official_feed_url' => null,
            'bse_official_feed_url' => null,
        ]);
    }

    /** @return array<string,array{enabled:bool,configured:bool,active:bool,route_mode:string,feed_url:?string}> */
    public function exchangeFallbackStatus(array $overrides = []): array
    {
        $settings = $this->settings();
        $feedUrl = static function (string $exchange) use ($settings, $overrides): string {
            $field = $exchange.'_official_feed_url';
            $configuredUrl = array_key_exists($field, $overrides)
                ? trim((string) ($overrides[$field] ?? ''))
                : trim((string) ($settings->{$field} ?? ''));

            return $configuredUrl !== ''
                ? $configuredUrl
                : trim((string) config('fundamentals_bootstrap.'.$field, ''));
        };
        $nseFeedUrl = $feedUrl('nse');
        $bseFeedUrl = $feedUrl('bse');
        $nseDirectConfigured = (bool) config('fundamentals_bootstrap.nse_official_direct_enabled', false)
            && (bool) config('fundamentals_bootstrap.nse_official_direct_access_authorized', false);
        $nseConfigured = $nseFeedUrl !== '' || $nseDirectConfigured;
        $bseConfigured = $bseFeedUrl !== '';
        $nseEnabled = (bool) $settings->nse_official_fallback_enabled;
        $bseEnabled = (bool) $settings->bse_official_fallback_enabled;

        return [
            'nse' => [
                'enabled' => $nseEnabled,
                'configured' => $nseConfigured,
                'active' => $nseEnabled && $nseConfigured,
                'route_mode' => $nseFeedUrl !== '' ? 'normalized_feed' : ($nseDirectConfigured ? 'direct' : 'unconfigured'),
                'feed_url' => $nseFeedUrl !== '' ? $nseFeedUrl : null,
            ],
            'bse' => [
                'enabled' => $bseEnabled,
                'configured' => $bseConfigured,
                'active' => $bseEnabled && $bseConfigured,
                'route_mode' => $bseConfigured ? 'normalized_feed' : 'unconfigured',
                'feed_url' => $bseFeedUrl !== '' ? $bseFeedUrl : null,
            ],
        ];
    }

    public function exchangeFallbackIsActive(string $exchange): bool
    {
        $key = strtolower($exchange);

        return (bool) ($this->exchangeFallbackStatus()[$key]['active'] ?? false);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{inserted:int,deduped:int,restated:int}
     */
    public function storeFacts(Stock $stock, array $rows, ?Carbon $fetchedAt = null): array
    {
        $fetchedAt ??= now();
        $stats = ['inserted' => 0, 'deduped' => 0, 'restated' => 0];

        DB::transaction(function () use ($stock, $rows, $fetchedAt, &$stats): void {
            // Lock the parent stock row so writers serialize even when no current
            // fact row exists yet. This covers bootstrap and incremental writers,
            // which have separate process-level locks.
            Stock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();

            foreach ($rows as $row) {
                $periodEnd = Carbon::parse((string) $row['period_end'])->toDateString();
                $availability = $row['availability_date'] ?? null;
                $availabilityDate = $availability
                    ? Carbon::parse((string) $availability)->toDateString()
                    : $fetchedAt->toDateString();

                $identity = [
                    'stock_id' => $stock->id,
                    'statement_type' => (string) $row['statement_type'],
                    'cadence' => (string) $row['cadence'],
                    'statement_basis' => (string) ($row['statement_basis'] ?? 'consolidated'),
                    'fact_key' => (string) $row['fact_key'],
                    'period_end' => $periodEnd,
                ];

                $current = $this->factIdentityQuery($identity)
                    ->where('is_current', true)
                    ->orderByDesc('revision_number')
                    ->lockForUpdate()
                    ->first();
                if ($current !== null && $this->canonicalDecimal($current->value) === $this->canonicalDecimal($row['value'] ?? null)) {
                    // A successful unchanged response is still a provider check. Do
                    // not mutate first_fetched_at: it is immutable provenance.
                    $current->forceFill(['last_provider_checked_at' => $fetchedAt])->save();
                    $stats['deduped']++;

                    continue;
                }

                $revision = (int) $this->factIdentityQuery($identity)->max('revision_number');
                $nextRevision = $revision + 1;
                $hash = $this->revisionHash($identity, $row['value'] ?? null, $availabilityDate, $nextRevision);
                if ($revision > 0) {
                    $this->factIdentityQuery($identity)->update(['is_current' => false]);
                    $stats['restated']++;
                } else {
                    $stats['inserted']++;
                }

                FundamentalFact::query()->create(array_merge($identity, [
                    'provider' => (string) ($row['provider'] ?? 'yahoo'),
                    'period_start' => $row['period_start'] ?? null,
                    'reported_period' => $row['reported_period'] ?? $periodEnd,
                    'value' => $row['value'] ?? null,
                    'currency' => $row['currency'] ?? null,
                    'availability_date' => $availabilityDate,
                    'first_fetched_at' => $fetchedAt,
                    'last_provider_checked_at' => $fetchedAt,
                    'revision_hash' => $hash,
                    'revision_number' => $nextRevision,
                    'is_current' => true,
                    'source_meta' => is_array($row['source_meta'] ?? null) ? $row['source_meta'] : [],
                ]));
            }
        });

        return $stats;
    }

    /** @param array<string,mixed> $sourceMeta */
    public function recordSuccessfulProviderCheck(
        Stock $stock,
        string $cadence,
        Carbon $checkedAt,
        string $provider = 'yahoo',
        ?string $responseHash = null,
        ?string $providerSymbol = null,
        array $sourceMeta = [],
    ): void {
        DB::table('stox_fundamental_provider_checks')->upsert([[
            'stock_id' => $stock->id,
            'cadence' => $cadence,
            'last_successful_check_at' => $checkedAt,
            'provider' => $provider,
            'response_hash' => $responseHash,
            'requested_symbol' => $stock->symbol,
            'provider_symbol' => $providerSymbol,
            'source_meta' => $sourceMeta === [] ? null : json_encode($sourceMeta, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['stock_id', 'cadence'], ['last_successful_check_at', 'provider', 'response_hash', 'requested_symbol', 'provider_symbol', 'source_meta', 'updated_at']);
    }

    public function latestFact(Stock $stock, string $factKey, string $cadence, ?Carbon $asOf = null): ?FundamentalFact
    {
        $asOf ??= now();

        return FundamentalFact::query()
            ->where('stock_id', $stock->id)
            ->where('fact_key', $factKey)
            ->where('cadence', $cadence)
            ->whereDate('availability_date', '<=', $asOf->toDateString())
            ->orderByDesc('period_end')
            ->orderByDesc('availability_date')
            ->orderByDesc('revision_number')
            ->get()
            ->first(fn (FundamentalFact $fact): bool => $this->isPitEligible($fact));
    }

    /**
     * @return array<string,mixed>
     */
    public function metric(Stock $stock, string $metric, string $basis = 'ttm', ?Carbon $asOf = null, ?float $price = null): array
    {
        $asOf ??= now();
        $cadence = $basis === self::CADENCE_ANNUAL ? self::CADENCE_ANNUAL : self::CADENCE_QUARTERLY;
        $facts = $this->factMap($stock, $cadence, $asOf);
        $ttm = fn (string $key): ?float => $basis === 'ttm'
            ? $this->ttmFlowSum($stock, $key, $asOf)
            : null;
        $netIncome = $basis === 'ttm' ? $ttm('net_income') : $this->factValue($facts, 'net_income');
        $revenue = $basis === 'ttm' ? $ttm('revenue') : $this->factValue($facts, 'revenue');
        $operatingCashFlow = $basis === 'ttm' ? $ttm('operating_cash_flow') : $this->factValue($facts, 'operating_cash_flow');
        $capitalExpenditure = $basis === 'ttm'
            ? $ttm('capital_expenditure')
            : $this->factValue($facts, 'capital_expenditure');
        $eps = $basis === 'ttm' ? $ttm('eps') : $this->factValue($facts, 'eps');
        $ebitda = $basis === 'ttm' ? $ttm('ebitda') : $this->factValue($facts, 'ebitda');
        $interestExpense = $basis === 'ttm' ? $ttm('interest_expense') : $this->factValue($facts, 'interest_expense');
        $equity = $this->factValue($facts, 'equity');
        $debt = $this->factValue($facts, 'debt');
        $cash = $this->factValue($facts, 'cash_and_equivalents');
        $shares = $this->factValue($facts, 'shares_outstanding');
        $dividendsPaid = $basis === 'ttm' ? $ttm('dividends_paid') : $this->factValue($facts, 'dividends_paid');
        $freeCashFlow = $operatingCashFlow !== null && $capitalExpenditure !== null
            ? $operatingCashFlow - abs($capitalExpenditure)
            : null;
        $marketCap = $price !== null && $shares !== null && $shares > 0 ? $price * $shares : null;
        $enterpriseValue = $marketCap !== null && $debt !== null && $cash !== null
            ? $marketCap + $debt - $cash
            : null;
        $value = match ($metric) {
            'revenue' => $revenue,
            'net_income' => $netIncome,
            'eps' => $eps,
            'debt_equity' => $this->ratioWithPositiveDenominator($this->factValue($facts, 'debt'), $equity),
            'roe' => $this->ratioWithPositiveDenominator($netIncome, $equity) !== null
                ? $this->ratioWithPositiveDenominator($netIncome, $equity) * 100
                : null,
            'net_debt' => $this->minus($debt, $cash),
            'ebitda' => $ebitda,
            'interest_expense' => $interestExpense,
            'debt_to_ebitda' => $ebitda !== null && $ebitda > 0 && $debt !== null ? $debt / $ebitda : null,
            'interest_coverage' => $interestExpense !== null && abs($interestExpense) > 0 && $ebitda !== null
                ? $ebitda / abs($interestExpense)
                : null,
            'free_cash_flow' => $freeCashFlow,
            'market_cap' => $marketCap,
            'enterprise_value' => $enterpriseValue,
            'operating_margin' => $this->percent($this->ratioWithPositiveDenominator($basis === 'ttm' ? $ttm('operating_profit') : $this->factValue($facts, 'operating_profit'), $revenue)),
            'net_margin' => $this->percent($this->ratioWithPositiveDenominator($netIncome, $revenue)),
            'fcf_margin' => $this->percent($this->ratioWithPositiveDenominator($freeCashFlow, $revenue)),
            'fcf_yield' => $this->percent($this->ratioWithPositiveDenominator($freeCashFlow, $marketCap)),
            'dividend_yield' => $this->percent($dividendsPaid !== null && $shares !== null && $shares > 0 && $price !== null && $price > 0
                ? abs($dividendsPaid) / ($shares * $price)
                : null),
            'payout_ratio' => $this->percent($this->ratioWithPositiveDenominator($dividendsPaid !== null ? abs($dividendsPaid) : null, $netIncome)),
            'pe' => $price !== null ? $this->priceEarningsRatio($price, $eps) : null,
            'pb' => $price !== null ? $this->priceBookRatio($price, $facts) : null,
            default => in_array($metric, FundamentalBankMetricsService::METRIC_KEYS, true)
                ? app(FundamentalBankMetricsService::class)->metricsForStock($stock, $asOf)[$metric]
                : $this->factValue($facts, $metric),
        };

        $freshness = $this->freshness($facts, $cadence, $asOf);

        return [
            'metric' => $metric,
            'basis' => $basis,
            'cadence' => $cadence,
            'value' => $value !== null ? round((float) $value, 6) : null,
            'valid_for_live_decision' => $value !== null && $freshness['status'] === 'fresh',
            'freshness' => $freshness,
            'facts' => array_map(fn (FundamentalFact $fact) => [
                'fact_key' => $fact->fact_key,
                'period_end' => $fact->period_end?->toDateString(),
                'reported_period' => $fact->reported_period,
                'availability_date' => $fact->availability_date?->toDateString(),
                'provider' => $fact->provider,
                'currency' => $fact->currency,
                'revision_number' => $fact->revision_number,
            ], $facts),
            'provenance' => $this->metricProvenance($facts, $basis),
        ];
    }

    /** @param array<string,FundamentalFact> $facts */
    private function metricProvenance(array $facts, string $basis): array
    {
        $providers = collect($facts)->pluck('provider')->filter()->unique()->values()->all();
        $latest = collect($facts)->sortByDesc(fn (FundamentalFact $fact) => $fact->period_end?->toDateString() ?? '')->first();

        return [
            'basis' => $basis,
            'providers' => $providers,
            'source_label' => count($providers) === 1 ? (string) $providers[0] : (count($providers) > 1 ? 'Multiple sources' : null),
            'latest_period_end' => $latest?->period_end?->toDateString(),
            'latest_availability_date' => $latest?->availability_date?->toDateString(),
            'derived' => $basis === 'ttm' || count($facts) > 1,
        ];
    }

    /**
     * Return comparable-period growth using only revisions available at $asOf.
     * This deliberately does not reuse the current/TTM metric because a level
     * is not a growth feature and TTM aggregation can hide the comparison.
     *
     * @return array{metric:string,value:?float,availability_date:?string}
     */
    public function growthMetric(Stock $stock, string $factKey, string $cadence, Carbon $asOf): array
    {
        $periodFacts = $this->distinctPeriodFacts($stock, $factKey, $cadence, $asOf);
        $current = $periodFacts[0] ?? null;
        $previous = null;
        if ($current !== null && $current->period_end !== null) {
            $previous = $cadence === self::CADENCE_QUARTERLY
                ? $this->findComparableYearAgoFact($periodFacts, $current->period_end)
                : $this->findPriorAnnualFact($periodFacts, $current->period_end);
        }
        $value = null;
        if ($current !== null && $previous !== null && $previous->value !== null && (float) $previous->value !== 0.0) {
            $value = (((float) $current->value - (float) $previous->value) / abs((float) $previous->value)) * 100;
        }

        return [
            'metric' => $factKey.'_growth',
            'value' => $value !== null ? round($value, 6) : null,
            'availability_date' => $current?->availability_date?->toDateString(),
        ];
    }

    /**
     * @return array<string,FundamentalFact>
     */
    public function factMap(Stock $stock, string $cadence, Carbon $asOf): array
    {
        $rows = FundamentalFact::query()
            ->where('stock_id', $stock->id)
            ->where('cadence', $cadence)
            ->whereDate('availability_date', '<=', $asOf->toDateString())
            ->orderByDesc('period_end')
            ->orderByDesc('availability_date')
            ->orderByDesc('revision_number')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if (! $this->isPitEligible($row)) {
                continue;
            }
            if (! isset($out[$row->fact_key])) {
                $out[$row->fact_key] = $row;
            }
        }

        return $out;
    }

    public function ttmFlowSum(Stock $stock, string $factKey, Carbon $asOf): ?float
    {
        $periodFacts = $this->distinctPeriodFacts($stock, $factKey, self::CADENCE_QUARTERLY, $asOf);
        if ($periodFacts === []) {
            return null;
        }

        $chain = [$periodFacts[0]];
        $cursor = $periodFacts[0]->period_end;
        if ($cursor === null) {
            return null;
        }

        for ($i = 1; $i < 4; $i++) {
            $prior = $this->findPriorQuarterFact($periodFacts, $cursor);
            if ($prior === null || $prior->period_end === null) {
                return null;
            }
            $chain[] = $prior;
            $cursor = $prior->period_end;
        }

        $sum = 0.0;
        foreach ($chain as $fact) {
            if (! is_numeric($fact->value)) {
                return null;
            }
            $sum += (float) $fact->value;
        }

        return $sum;
    }

    /**
     * @return list<FundamentalFact>
     */
    public function distinctPeriodFacts(Stock $stock, string $factKey, string $cadence, Carbon $asOf): array
    {
        $facts = FundamentalFact::query()
            ->where('stock_id', $stock->id)
            ->where('fact_key', $factKey)
            ->where('cadence', $cadence)
            ->whereDate('availability_date', '<=', $asOf->toDateString())
            ->orderByDesc('period_end')
            ->orderByDesc('revision_number')
            ->get();

        $periods = [];
        foreach ($facts as $fact) {
            if (! $this->isPitEligible($fact)) {
                continue;
            }
            $period = $fact->period_end?->toDateString();
            if ($period !== null && ! array_key_exists($period, $periods)) {
                $periods[$period] = $fact;
            }
        }

        return array_values($periods);
    }

    /**
     * @param  list<FundamentalFact>  $periodFacts
     */
    private function findPriorQuarterFact(array $periodFacts, Carbon $fromPeriodEnd): ?FundamentalFact
    {
        $target = $fromPeriodEnd->copy()->subMonths(3);

        return $this->findPeriodNear($periodFacts, $target);
    }

    /**
     * @param  list<FundamentalFact>  $periodFacts
     */
    private function findComparableYearAgoFact(array $periodFacts, Carbon $fromPeriodEnd): ?FundamentalFact
    {
        $target = $fromPeriodEnd->copy()->subYear();

        return $this->findPeriodNear($periodFacts, $target);
    }

    /**
     * @param  list<FundamentalFact>  $periodFacts
     */
    private function findPriorAnnualFact(array $periodFacts, Carbon $fromPeriodEnd): ?FundamentalFact
    {
        $target = $fromPeriodEnd->copy()->subYear();

        return $this->findPeriodNear($periodFacts, $target, 45);
    }

    /**
     * @param  list<FundamentalFact>  $periodFacts
     */
    private function findPeriodNear(array $periodFacts, Carbon $target, int $toleranceDays = 20): ?FundamentalFact
    {
        foreach ($periodFacts as $fact) {
            if ($fact->period_end === null) {
                continue;
            }
            if (abs($fact->period_end->diffInDays($target)) <= $toleranceDays) {
                return $fact;
            }
        }

        return null;
    }

    /**
     * @param  array<string,FundamentalFact>  $facts
     * @return array{status:string,reason:?string,latest_availability_date:?string,latest_period_end:?string}
     */
    public function freshness(array $facts, string $cadence, Carbon $asOf): array
    {
        if ($facts === []) {
            return ['status' => 'missing', 'reason' => 'no_fundamental_facts', 'latest_availability_date' => null, 'latest_period_end' => null];
        }

        $latest = collect($facts)->sortByDesc(fn (FundamentalFact $fact) => $fact->period_end?->toDateString() ?? '')->first();
        $settings = $this->settings();
        $thresholdMonths = $cadence === self::CADENCE_ANNUAL
            ? $settings->annual_freshness_months
            : $settings->quarterly_freshness_months;
        $sanityMonths = $cadence === self::CADENCE_ANNUAL ? 24 : 9;

        if ($latest->availability_date === null || $latest->availability_date->copy()->addMonths($thresholdMonths)->lt($asOf)) {
            return [
                'status' => 'stale',
                'reason' => 'availability_age_exceeded',
                'latest_availability_date' => $latest->availability_date?->toDateString(),
                'latest_period_end' => $latest->period_end?->toDateString(),
            ];
        }

        if ($latest->period_end === null || $latest->period_end->copy()->addMonths($sanityMonths)->lt($asOf)) {
            return [
                'status' => 'sanity_rejected',
                'reason' => 'financial_period_implausibly_old',
                'latest_availability_date' => $latest->availability_date?->toDateString(),
                'latest_period_end' => $latest->period_end?->toDateString(),
            ];
        }

        return [
            'status' => 'fresh',
            'reason' => null,
            'latest_availability_date' => $latest->availability_date?->toDateString(),
            'latest_period_end' => $latest->period_end?->toDateString(),
        ];
    }

    /** @param array<string,mixed> $identity */
    private function revisionHash(array $identity, mixed $value, string $availabilityDate, int $revisionNumber): string
    {
        // The same value can return after a restatement. Include its sequence so
        // that distinct revision events remain unique under the DB constraint.
        return hash(
            'sha256',
            json_encode([$identity, $this->canonicalDecimal($value), $availabilityDate, $revisionNumber], JSON_THROW_ON_ERROR),
        );
    }

    private function canonicalDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return (string) $value;
        }

        return number_format((float) $value, 6, '.', '');
    }

    /** @param array<string,mixed> $identity */
    private function factIdentityQuery(array $identity): Builder
    {
        return FundamentalFact::query()
            ->where('stock_id', $identity['stock_id'])
            ->where('statement_type', $identity['statement_type'])
            ->where('cadence', $identity['cadence'])
            ->where('statement_basis', $identity['statement_basis'])
            ->where('fact_key', $identity['fact_key'])
            ->whereDate('period_end', $identity['period_end']);
    }

    /** @param array<string,FundamentalFact> $facts */
    private function factValue(array $facts, string $key): ?float
    {
        $value = $facts[$key]->value ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function ratio(?float $numerator, ?float $denominator): ?float
    {
        return $denominator !== null && abs($denominator) > 0.000001 && $numerator !== null
            ? $numerator / $denominator
            : null;
    }

    private function ratioWithPositiveDenominator(?float $numerator, ?float $denominator): ?float
    {
        if ($denominator === null || $denominator <= 0 || $numerator === null) {
            return null;
        }

        return $numerator / $denominator;
    }

    private function priceEarningsRatio(float $price, ?float $eps): ?float
    {
        if ($eps === null || $eps <= 0) {
            return null;
        }

        return $price / $eps;
    }

    /** @param array<string,FundamentalFact> $facts */
    private function priceBookRatio(float $price, array $facts): ?float
    {
        $book = $this->bookValuePerShare($facts);
        if ($book === null || $book <= 0) {
            return null;
        }

        return $price / $book;
    }

    private function minus(?float $left, ?float $right): ?float
    {
        return $left !== null && $right !== null ? $left - $right : null;
    }

    private function isPitEligible(FundamentalFact $fact): bool
    {
        return ($fact->source_meta['availability_quality'] ?? null) !== 'non_pit';
    }

    private function percent(?float $ratio): ?float
    {
        return $ratio === null ? null : $ratio * 100;
    }

    /** @param array<string,FundamentalFact> $facts */
    private function bookValuePerShare(array $facts): ?float
    {
        $equity = $this->factValue($facts, 'equity');
        $shares = $this->factValue($facts, 'shares_outstanding');

        return $this->ratio($equity, $shares);
    }
}
