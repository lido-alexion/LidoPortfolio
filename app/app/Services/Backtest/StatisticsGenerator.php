<?php

namespace App\Services\Backtest;

use App\Models\BacktestRun;
use App\Models\BacktestSnapshot;
use App\Models\BacktestTrade;
use App\Models\BacktestTransaction;
use App\Services\Analytics\PerformanceRiskCalculator;
use Carbon\Carbon;

class StatisticsGenerator
{
    public function __construct(private PerformanceRiskCalculator $risk) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(BacktestRun $run, SimulationContext $ctx): array
    {
        $initial = (float) $run->initial_capital;
        $lastSnap = BacktestSnapshot::query()
            ->where('backtest_run_id', $run->id)
            ->orderByDesc('snapshot_date')
            ->first();

        $finalValue = $lastSnap ? (float) $lastSnap->portfolio_value : $initial;
        $absoluteReturn = round($finalValue - $initial, 4);
        $returnPct = $initial > 0 ? round(($absoluteReturn / $initial) * 100.0, 6) : 0.0;

        $from = Carbon::parse($run->from_date->toDateString());
        $to = Carbon::parse(($lastSnap?->snapshot_date ?? $run->to_date)->toDateString());
        $years = max(1 / 365.25, $from->floatDiffInDays($to) / 365.25);
        $cagr = null;
        if ($initial > 0 && $finalValue > 0 && $years > 0) {
            $cagr = BacktestMath::cagrPercent($initial, $finalValue, max(1, (int) round($from->floatDiffInDays($to))));
            // Portfolio CAGR is meaningful over the full window even if shorter than trade min;
            // recompute with actual days when below trade threshold but still multi-week.
            if ($cagr === null && $from->floatDiffInDays($to) >= 7) {
                $raw = (pow($finalValue / $initial, 1 / $years) - 1) * 100.0;
                $cagr = BacktestMath::clampDecimal12_6(is_finite($raw) ? round($raw, 6) : null);
            }
        }

        $maxDd = (float) BacktestSnapshot::query()
            ->where('backtest_run_id', $run->id)
            ->max('drawdown_pct');

        $trades = BacktestTrade::query()
            ->where('backtest_run_id', $run->id)
            ->where('is_open', false)
            ->get();

        $winners = $trades->filter(fn ($t) => (float) $t->profit_loss > 0);
        $losers = $trades->filter(fn ($t) => (float) $t->profit_loss < 0);
        $totalTrades = $trades->count();
        $winCount = $winners->count();
        $loseCount = $losers->count();

        $holdingDays = $trades->pluck('holding_days')->filter(fn ($d) => $d !== null)->map(fn ($d) => (int) $d);

        $txCount = BacktestTransaction::query()->where('backtest_run_id', $run->id)->count();

        $utilDays = max(1, (int) $ctx->get('utilization_days', 1));
        $avgUtil = round(((float) $ctx->get('utilization_sum', 0)) / $utilDays, 4);
        $curve = [[
            'date' => $run->from_date->copy()->subDay()->toDateString(),
            'value' => $initial,
            'complete' => true,
        ], ...BacktestSnapshot::query()->where('backtest_run_id', $run->id)->orderBy('snapshot_date')->get()
            ->map(fn (BacktestSnapshot $snapshot): array => [
                'date' => $snapshot->snapshot_date->toDateString(),
                'value' => (float) $snapshot->portfolio_value,
                'complete' => true,
            ])->all()];
        $performance = $this->risk->calculate($curve);
        $recommendationOutcomes = is_array($ctx->get('recommendation_outcomes')) ? $ctx->get('recommendation_outcomes') : [];
        $pendingCount = count(is_array($ctx->get('pending_execution_drafts')) ? $ctx->get('pending_execution_drafts') : []);
        $limitations = [...$performance['limitations'], 'benchmark_and_excess_return_not_available'];
        if ($pendingCount > 0) {
            $limitations[] = 'recommendations_at_period_end_have_no_next_eligible_session_within_requested_period';
        }

        return [
            'initial_capital' => $initial,
            'final_portfolio_value' => round($finalValue, 4),
            'absolute_return' => $absoluteReturn,
            'return_pct' => BacktestMath::clampDecimal12_6($returnPct),
            'cagr' => $cagr,
            'xirr_percent' => $cagr,
            'twr_percent' => $performance['twr_percent'],
            'volatility_percent' => $performance['volatility_percent'],
            'sharpe_ratio' => $performance['sharpe_ratio'],
            'maximum_drawdown' => BacktestMath::clampDecimal12_6(round($maxDd, 6)),
            'total_trades' => $totalTrades,
            'total_transactions' => $txCount,
            'winning_trades' => $winCount,
            'losing_trades' => $loseCount,
            'win_rate' => $totalTrades > 0 ? round(($winCount / $totalTrades) * 100.0, 4) : 0.0,
            'largest_winner' => $winners->isEmpty() ? 0.0 : round((float) $winners->max('profit_loss'), 4),
            'largest_loser' => $losers->isEmpty() ? 0.0 : round((float) $losers->min('profit_loss'), 4),
            'average_winner' => $winners->isEmpty() ? 0.0 : round((float) $winners->avg('profit_loss'), 4),
            'average_loser' => $losers->isEmpty() ? 0.0 : round((float) $losers->avg('profit_loss'), 4),
            'average_holding_period' => $holdingDays->isEmpty() ? 0.0 : round((float) $holdingDays->avg(), 2),
            'longest_holding_period' => $holdingDays->isEmpty() ? 0 : (int) $holdingDays->max(),
            'shortest_holding_period' => $holdingDays->isEmpty() ? 0 : (int) $holdingDays->min(),
            'average_portfolio_utilization' => $avgUtil,
            'cash_remaining' => $lastSnap ? (float) $lastSnap->cash : (float) $ctx->cash(),
            'maximum_concurrent_positions' => (int) $ctx->get('max_concurrent_positions', 0),
            'simulated_charges' => round((float) $ctx->get('total_fees', 0), 4),
            'charge_impact_percent' => $initial > 0
                ? round(((float) $ctx->get('total_fees', 0) / $initial) * 100, 6) : null,
            'equity_curve' => $curve,
            'recommendations_generated' => (int) $ctx->get('recommendations_generated', 0),
            'recommendation_outcomes' => $recommendationOutcomes,
            'execution_assumptions' => $run->execution_assumptions_json,
            'unresolved_end_recommendations' => $pendingCount,
            'completeness' => 'complete_with_limitations',
            'limitations' => array_values(array_unique($limitations)),
        ];
    }
}
