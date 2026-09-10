<?php

namespace App\Services\Analytics;

use App\Models\AnalysisPreference;
use App\Models\Benchmark;
use App\Models\CashLedgerEntry;
use App\Models\PortfolioProfile;
use App\Models\PortfolioSnapshot;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\HistoricalHoldingsService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class AccountPerformanceService
{
    public function __construct(
        private HistoricalHoldingsService $history,
        private PerformanceRiskCalculator $performance,
        private MoneyWeightedReturnCalculator $moneyWeighted,
    ) {}

    /** @param array<int, int>|null $whatIfProfileIds */
    public function calculate(User $user, string $from, string $to, ?array $whatIfProfileIds = null): array
    {
        $cutoff = now()->toDateTimeString();
        $profiles = PortfolioProfile::query()->where('user_id', $user->id)->get();
        $ownedIds = $profiles->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($whatIfProfileIds !== null) {
            $selectedIds = array_values(array_unique(array_map('intval', $whatIfProfileIds)));
            if (array_diff($selectedIds, $ownedIds) !== []) {
                throw ValidationException::withMessages(['portfolio_ids' => 'What-if portfolios must belong to the signed-in account.']);
            }
            $mode = 'what_if';
        } else {
            $excluded = AnalysisPreference::query()->where('user_id', $user->id)
                ->whereNotNull('profile_id')->where('include_in_account_performance', false)
                ->pluck('profile_id')->map(fn ($id) => (int) $id)->all();
            $selectedIds = array_values(array_diff($ownedIds, $excluded));
            $mode = 'configured';
        }
        $selectedProfiles = $profiles->whereIn('id', $selectedIds);

        $dates = PortfolioSnapshot::query()->whereIn('profile_id', $selectedIds ?: [-1])
            ->whereBetween('snapshot_date', [$from, $to])->where('created_at', '<=', $cutoff)
            ->orderBy('snapshot_date')->pluck('snapshot_date')
            ->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())->all();
        $dates = array_values(array_unique([$from, ...$dates, $to]));
        sort($dates);

        $externalFlows = CashLedgerEntry::query()->whereIn('profile_id', $selectedIds ?: [-1])
            ->whereIn('entry_type', [CashLedgerEntry::TYPE_DEPOSIT, CashLedgerEntry::TYPE_WITHDRAWAL])
            ->where('entry_date', '>', $from)->where('entry_date', '<=', $to)
            ->where('created_at', '<=', $cutoff)->orderBy('entry_date')->orderBy('id')
            ->get(['entry_date', 'amount'])->map(fn (CashLedgerEntry $entry) => [
                'date' => $entry->entry_date->toDateString(), 'amount' => (float) $entry->amount,
            ])->all();
        $flowByDate = [];
        foreach ($externalFlows as $flow) {
            $flowByDate[$flow['date']] = ($flowByDate[$flow['date']] ?? 0.0) + $flow['amount'];
        }

        $observations = [];
        foreach ($dates as $date) {
            $value = 0.0;
            $complete = $selectedProfiles->isNotEmpty();
            foreach ($selectedProfiles as $profile) {
                $state = $this->history->asOf($profile, $date, $cutoff);
                if ($state['totals']['total_value'] === null || ! $state['completeness']['total_value_complete']) {
                    $complete = false;
                } else {
                    $value += (float) $state['totals']['total_value'];
                }
            }
            $observations[] = [
                'date' => $date, 'value' => $complete ? round($value, 4) : null,
                'external_flow' => $flowByDate[$date] ?? 0.0, 'complete' => $complete,
            ];
        }

        $accountPreference = AnalysisPreference::query()->where('user_id', $user->id)->where('scope_key', 'account')->first();
        $metrics = $this->performance->calculate(
            $observations,
            $accountPreference?->risk_free_rate === null ? 0.0 : (float) $accountPreference->risk_free_rate,
            $accountPreference?->annualization_days ?? 252,
        );
        $opening = $observations[0]['value'];
        $closing = $observations[array_key_last($observations)]['value'];
        $xirr = $opening === null || $closing === null ? null
            : $this->moneyWeighted->calculate($from, $to, (float) $opening, (float) $closing, $externalFlows);
        $benchmark = $accountPreference?->primaryBenchmark
            ?? Benchmark::query()->where('is_active', true)->orderByDesc('is_default')->first();
        $benchmarkResult = $this->benchmarkReturn($benchmark, $from, $to, $cutoff);

        return [
            'scope' => 'account', 'calculation_mode' => $mode, 'portfolio_ids' => $selectedIds,
            'period' => ['from' => $from, 'to' => $to], 'request_cutoff_at' => $cutoff,
            'xirr_percent' => $xirr, ...$metrics, 'benchmark' => $benchmarkResult,
            'excess_return_percent' => $metrics['twr_percent'] === null || $benchmarkResult['return_percent'] === null
                ? null : round($metrics['twr_percent'] - $benchmarkResult['return_percent'], 6),
            'evidence' => ['aggregation' => 'daily_account_wealth_and_flows_not_average_of_portfolio_returns'],
        ];
    }

    private function benchmarkReturn(?Benchmark $benchmark, string $from, string $to, string $cutoff): array
    {
        $stock = $benchmark ? Stock::query()->where('symbol', $benchmark->symbol)->first() : null;
        if ($benchmark === null || $stock === null) {
            return ['stable_key' => $benchmark?->stable_key, 'return_percent' => null, 'complete' => false];
        }
        $values = [];
        foreach (['from' => $from, 'to' => $to] as $key => $date) {
            $row = StockPrice::query()->where('stock_id', $stock->id)
                ->where('price_date', '<=', CarbonImmutable::parse($date)->endOfDay())
                ->where('created_at', '<=', $cutoff)->orderByDesc('price_date')->first();
            $level = $row?->adjusted_close_price ?? $row?->close_price;
            $values[$key] = $level === null ? null : (float) $level;
        }
        $return = $values['from'] === null || $values['to'] === null || $values['from'] <= 0
            ? null : round((($values['to'] / $values['from']) - 1) * 100, 6);

        return ['stable_key' => $benchmark->stable_key, 'return_percent' => $return, 'complete' => $return !== null];
    }
}
