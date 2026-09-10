<?php

namespace App\Services\Simulation;

use App\Models\PortfolioReplayCheckpoint;
use App\Models\PortfolioReplayRun;
use App\Services\Analytics\PerformanceRiskCalculator;

final class ReplayStatisticsService
{
    public function __construct(private PerformanceRiskCalculator $risk) {}

    /** @return array<string,mixed> */
    public function generate(PortfolioReplayRun $run): array
    {
        $checkpoints = PortfolioReplayCheckpoint::query()->where('replay_run_id', $run->id)
            ->orderBy('effective_session_date')->get();
        $checkpointCurve = $checkpoints->map(fn (PortfolioReplayCheckpoint $checkpoint): array => [
            'date' => $checkpoint->effective_session_date->toDateString(),
            'value' => isset($checkpoint->state_after['valuation']['total_value'])
                ? (float) $checkpoint->state_after['valuation']['total_value'] : null,
            'complete' => empty($checkpoint->limitations),
        ])->all();
        $startingValue = (float) ($run->starting_cash ?? $run->starting_state['cash_balance'] ?? 0);
        $curve = [[
            'date' => $run->period_start->copy()->subDay()->toDateString(),
            'value' => $startingValue,
            'complete' => true,
        ], ...$checkpointCurve];
        $performance = $this->risk->calculate($curve);
        $checkpointLimitations = $checkpoints->flatMap(fn (PortfolioReplayCheckpoint $checkpoint): array => $checkpoint->limitations ?? [])
            ->unique()->values()->all();
        $finalState = $checkpoints->last()?->state_after ?? $run->starting_state;
        $transactions = collect($finalState['transactions'] ?? []);
        $recommendations = collect($finalState['pending_recommendations'] ?? []);
        $opening = $curve[0]['value'];
        $closing = $curve !== [] ? $curve[array_key_last($curve)]['value'] : $opening;

        return [
            'initial_value' => $opening,
            'final_value' => $closing,
            'absolute_return' => $opening !== null && $closing !== null ? round($closing - $opening, 4) : null,
            'return_percent' => $opening !== null && $closing !== null && $opening > 0
                ? round((($closing - $opening) / $opening) * 100, 6) : null,
            ...$performance,
            'equity_curve' => $curve,
            'transaction_count' => $transactions->count(),
            'simulated_charges' => round($transactions->sum(fn (array $transaction): float => (float) ($transaction['fees'] ?? 0)), 4),
            'recommendation_count' => $recommendations->count(),
            'recommendation_outcomes' => $recommendations->countBy(fn (array $recommendation): string => (string) ($recommendation['status'] ?? 'unknown'))->all(),
            'ending_holdings' => $finalState['holdings'] ?? [],
            'strategy_contributions' => collect($finalState['strategies'] ?? [])->map(fn (array $strategy): array => [
                'strategy_id' => $strategy['strategy_id'] ?? null,
                'allocated_capital' => $strategy['strategy_capital_allocation'] ?? null,
                'owned_market_value' => $strategy['owned_market_value'] ?? null,
                'unused_allocation' => $strategy['unused_allocation'] ?? null,
                'lent' => $strategy['lent'] ?? null,
                'borrowed' => $strategy['borrowed'] ?? null,
            ])->values()->all(),
            'capital' => $finalState['capital'] ?? null,
            'completeness' => $checkpointLimitations === [] ? $performance['completeness'] : 'incomplete',
            'limitations' => array_values(array_unique([...$checkpointLimitations, ...$performance['limitations']])),
        ];
    }
}
