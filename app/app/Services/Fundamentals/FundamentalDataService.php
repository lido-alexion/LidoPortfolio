<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use App\Models\V7\FundamentalFact;
use App\Models\V7\FundamentalSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FundamentalDataService
{
    public const CADENCE_QUARTERLY = 'quarterly';
    public const CADENCE_ANNUAL = 'annual';

    public function settings(): FundamentalSetting
    {
        return FundamentalSetting::query()->firstOrCreate([], [
            'quarterly_freshness_months' => 5,
            'annual_freshness_months' => 15,
            'request_delay_ms' => 750,
            'max_attempts' => 3,
            'provider' => 'yahoo',
            'paused' => false,
        ]);
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
                $hash = $this->revisionHash($identity, $row['value'] ?? null, $availabilityDate);

                $known = $this->factIdentityQuery($identity)->where('revision_hash', $hash)->exists();
                if ($known) {
                    $stats['deduped']++;
                    continue;
                }

                $revision = (int) $this->factIdentityQuery($identity)->max('revision_number');
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
                    'revision_hash' => $hash,
                    'revision_number' => $revision + 1,
                    'is_current' => true,
                    'source_meta' => is_array($row['source_meta'] ?? null) ? $row['source_meta'] : [],
                ]));
            }
        });

        return $stats;
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
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    public function metric(Stock $stock, string $metric, string $basis = 'ttm', ?Carbon $asOf = null, ?float $price = null): array
    {
        $asOf ??= now();
        $cadence = $basis === self::CADENCE_ANNUAL ? self::CADENCE_ANNUAL : self::CADENCE_QUARTERLY;
        $facts = $this->factMap($stock, $cadence, $asOf);
        $series = $basis === 'ttm' && $cadence === self::CADENCE_QUARTERLY
            ? $this->factSeries($stock, $cadence, $asOf)
            : [];
        $flow = fn (string $key): ?float => ! isset($series[$key]) || $series[$key] === []
            ? null
            : array_sum(array_map(fn (FundamentalFact $fact): float => (float) $fact->value, $series[$key]));
        $netIncome = $basis === 'ttm' ? $flow('net_income') : $this->factValue($facts, 'net_income');
        $revenue = $basis === 'ttm' ? $flow('revenue') : $this->factValue($facts, 'revenue');
        $operatingCashFlow = $basis === 'ttm' ? $flow('operating_cash_flow') : $this->factValue($facts, 'operating_cash_flow');
        $capitalExpenditure = $basis === 'ttm' ? $flow('capital_expenditure') : $this->factValue($facts, 'capital_expenditure');
        $eps = $basis === 'ttm' ? $flow('eps') : $this->factValue($facts, 'eps');
        $value = match ($metric) {
            'revenue' => $revenue,
            'net_income' => $netIncome,
            'debt_equity' => $this->ratio($this->factValue($facts, 'debt'), $this->factValue($facts, 'equity')),
            'roe' => $this->ratio($netIncome, $this->factValue($facts, 'equity')) !== null
                ? $this->ratio($netIncome, $this->factValue($facts, 'equity')) * 100
                : null,
            'net_debt' => $this->minus($this->factValue($facts, 'debt'), $this->factValue($facts, 'cash_and_equivalents')),
            'free_cash_flow' => $this->minus($operatingCashFlow, abs((float) ($capitalExpenditure ?? 0))),
            'pe' => $price !== null ? $this->ratio($price, $eps) : null,
            'pb' => $price !== null ? $this->ratio($price, $this->bookValuePerShare($facts)) : null,
            default => $this->factValue($facts, $metric),
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
                'availability_date' => $fact->availability_date?->toDateString(),
                'revision_number' => $fact->revision_number,
            ], $facts),
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
            if (! isset($out[$row->fact_key])) {
                $out[$row->fact_key] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array<string,list<FundamentalFact>>
     */
    private function factSeries(Stock $stock, string $cadence, Carbon $asOf): array
    {
        $rows = FundamentalFact::query()
            ->where('stock_id', $stock->id)
            ->where('cadence', $cadence)
            ->whereDate('availability_date', '<=', $asOf->toDateString())
            ->whereIn('fact_key', ['revenue', 'net_income', 'operating_cash_flow', 'capital_expenditure', 'eps'])
            ->orderByDesc('period_end')
            ->orderByDesc('availability_date')
            ->orderByDesc('revision_number')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $periodKey = $row->fact_key.':'.$row->period_end?->toDateString();
            if ($row->period_end === null || isset($out[$row->fact_key][$periodKey])) {
                continue;
            }
            $out[$row->fact_key][$periodKey] = $row;
        }

        return array_map(
            fn (array $items): array => array_slice(array_values($items), 0, 4),
            $out,
        );
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
    private function revisionHash(array $identity, mixed $value, string $availabilityDate): string
    {
        return hash('sha256', json_encode([$identity, $value, $availabilityDate], JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $identity */
    private function factIdentityQuery(array $identity): \Illuminate\Database\Eloquent\Builder
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

    private function minus(?float $left, ?float $right): ?float
    {
        return $left !== null && $right !== null ? $left - $right : null;
    }

    /** @param array<string,FundamentalFact> $facts */
    private function bookValuePerShare(array $facts): ?float
    {
        $equity = $this->factValue($facts, 'equity');
        $shares = $this->factValue($facts, 'shares_outstanding');

        return $this->ratio($equity, $shares);
    }
}
