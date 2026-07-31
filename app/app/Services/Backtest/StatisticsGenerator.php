<?php

namespace App\Services\Backtest;

use App\Models\BacktestRun;
use App\Models\BacktestSnapshot;
use App\Models\BacktestTrade;
use App\Models\BacktestTransaction;
use Carbon\Carbon;

class StatisticsGenerator
{
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
            $cagr = round((pow($finalValue / $initial, 1 / $years) - 1) * 100.0, 6);
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

        return [
            'initial_capital' => $initial,
            'final_portfolio_value' => round($finalValue, 4),
            'absolute_return' => $absoluteReturn,
            'return_pct' => $returnPct,
            'cagr' => $cagr,
            'maximum_drawdown' => round($maxDd, 6),
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
        ];
    }
}
