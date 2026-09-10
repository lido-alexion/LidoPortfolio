<?php

namespace App\Services\Analytics;

use App\Models\AnalysisPreference;
use App\Models\Benchmark;
use App\Models\CashLedgerEntry;
use App\Models\PortfolioProfile;
use App\Models\PortfolioSnapshot;
use App\Models\PaperExecutionEvent;
use App\Models\PaperSimulationEvent;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Services\HistoricalHoldingsService;
use Carbon\CarbonImmutable;

final class PortfolioPerformanceService
{
    public function __construct(
        private HistoricalHoldingsService $history,
        private PerformanceRiskCalculator $performance,
        private MoneyWeightedReturnCalculator $moneyWeighted,
    ) {}

    /** @return array<string, mixed> */
    public function calculate(PortfolioProfile $profile, string $from, string $to): array
    {
        $cutoff = now()->toISOString();
        $preference = AnalysisPreference::query()
            ->where('user_id', $profile->user_id)
            ->where('scope_key', 'portfolio:'.$profile->id)
            ->first();
        $benchmark = $preference?->primaryBenchmark
            ?? Benchmark::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();

        $dates = PortfolioSnapshot::query()
            ->where('profile_id', $profile->id)
            ->whereBetween('snapshot_date', [$from, $to])
            ->where('created_at', '<=', $cutoff)
            ->orderBy('snapshot_date')
            ->pluck('snapshot_date')
            ->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())
            ->all();
        $dates = array_values(array_unique([$from, ...$dates, $to]));
        sort($dates);

        $externalFlows = CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->whereIn('entry_type', [CashLedgerEntry::TYPE_DEPOSIT, CashLedgerEntry::TYPE_WITHDRAWAL])
            ->where('entry_date', '>', $from)
            ->where('entry_date', '<=', $to)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get(['entry_date', 'amount'])
            ->map(fn (CashLedgerEntry $entry) => [
                'date' => $entry->entry_date->toDateString(),
                'amount' => (float) $entry->amount,
            ])->all();
        $flowByDate = [];
        foreach ($externalFlows as $flow) {
            $flowByDate[$flow['date']] = ($flowByDate[$flow['date']] ?? 0.0) + $flow['amount'];
        }

        $observations = [];
        foreach ($dates as $date) {
            $state = $this->history->asOf($profile, $date, $cutoff);
            $observations[] = [
                'date' => $date,
                'value' => $state['totals']['total_value'],
                'external_flow' => $flowByDate[$date] ?? 0.0,
                'complete' => (bool) $state['completeness']['total_value_complete'],
            ];
        }

        $riskFreeRate = $preference?->risk_free_rate === null ? 0.0 : (float) $preference->risk_free_rate;
        $annualizationDays = $preference?->annualization_days ?? 252;
        $metrics = $this->performance->calculate($observations, $riskFreeRate, $annualizationDays);
        $openingValue = $observations[0]['value'];
        $closingValue = $observations[array_key_last($observations)]['value'];
        $xirr = $openingValue === null || $closingValue === null
            ? null
            : $this->moneyWeighted->calculate($from, $to, (float) $openingValue, (float) $closingValue, $externalFlows);
        $benchmarkResult = $this->benchmarkReturn($benchmark, $from, $to, $cutoff);
        $comparisonBenchmarks = Benchmark::query()
            ->where('is_active', true)
            ->whereIn('id', $preference?->comparison_benchmark_ids ?? [])
            ->get()
            ->map(fn (Benchmark $comparison) => $this->benchmarkReturn($comparison, $from, $to, $cutoff))
            ->values()->all();

        $paperDisclosure = $profile->isPaper() ? [
            'label' => 'PAPER',
            'simulation_state' => $profile->simulation_state,
            'checkpoint_date' => $profile->simulation_checkpoint_date?->toDateString(),
            'strategy_simulated_fill_count' => PaperExecutionEvent::query()->where('profile_id', $profile->id)
                ->whereDate('effective_session_date', '>=', $from)->whereDate('effective_session_date', '<=', $to)
                ->whereNotNull('transaction_id')->count(),
            'investor_intervention_count' => Transaction::query()->where('profile_id', $profile->id)
                ->where('simulation_origin', 'investor_intervention')
                ->whereDate('transaction_date', '>=', $from)->whereDate('transaction_date', '<=', $to)->count(),
            'pause_discontinuity_count' => PaperSimulationEvent::query()->where('profile_id', $profile->id)
                ->where('event_type', 'investor_paused')
                ->whereDate('effective_session_date', '>=', $from)->whereDate('effective_session_date', '<=', $to)->count(),
            'real_account_aggregation_excluded' => true,
        ] : null;

        return [
            'scope' => 'portfolio',
            'portfolio_type' => $profile->portfolio_type,
            'paper_disclosure' => $paperDisclosure,
            'profile_id' => $profile->id,
            'period' => ['from' => $from, 'to' => $to],
            'request_cutoff_at' => $cutoff,
            'xirr_percent' => $xirr,
            ...$metrics,
            'benchmark' => $benchmarkResult,
            'comparison_benchmarks' => $comparisonBenchmarks,
            'excess_return_percent' => $metrics['twr_percent'] === null || $benchmarkResult['return_percent'] === null
                ? null
                : round($metrics['twr_percent'] - $benchmarkResult['return_percent'], 6),
            'evidence' => [
                'value_source' => 'historical_holdings_plus_cash',
                'external_flow_types' => ['deposit', 'withdrawal'],
                'observation_dates' => $dates,
                'benchmark_interpolation' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function benchmarkReturn(?Benchmark $benchmark, string $from, string $to, string $cutoff): array
    {
        if ($benchmark === null) {
            return ['id' => null, 'stable_key' => null, 'return_percent' => null, 'complete' => false];
        }
        $stock = Stock::query()->where('symbol', $benchmark->symbol)->first();
        if ($stock === null) {
            return ['id' => $benchmark->id, 'stable_key' => $benchmark->stable_key, 'return_percent' => null, 'complete' => false];
        }

        $levels = [];
        foreach (['from' => $from, 'to' => $to] as $key => $date) {
            $row = StockPrice::query()->where('stock_id', $stock->id)
                ->where('price_date', '<=', CarbonImmutable::parse($date)->endOfDay())
                ->where('created_at', '<=', $cutoff)
                ->orderByDesc('price_date')->first();
            $level = $row?->adjusted_close_price ?? $row?->close_price;
            $levels[$key] = $level === null ? null : [
                'value' => (float) $level,
                'as_of' => $row->price_date->toDateString(),
            ];
        }
        $return = $levels['from'] === null || $levels['to'] === null || $levels['from']['value'] <= 0
            ? null
            : round((($levels['to']['value'] / $levels['from']['value']) - 1.0) * 100.0, 6);

        return [
            'id' => $benchmark->id,
            'stable_key' => $benchmark->stable_key,
            'name' => $benchmark->name,
            'return_type' => $benchmark->return_type,
            'from' => $levels['from'],
            'to' => $levels['to'],
            'return_percent' => $return,
            'complete' => $return !== null,
        ];
    }
}
