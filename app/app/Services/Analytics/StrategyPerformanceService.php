<?php

namespace App\Services\Analytics;

use App\Models\HoldingAdoption;
use App\Models\TradingStrategy;
use App\Models\Transaction;
use App\Services\PortfolioHistoricalHoldingsService;
use App\Services\StockQuoteService;
use App\Services\XirrService;
use Carbon\Carbon;

final class StrategyPerformanceService
{
    public function __construct(
        private PortfolioHistoricalHoldingsService $holdings,
        private StockQuoteService $quotes,
        private XirrService $xirr,
    ) {}

    public function calculate(TradingStrategy $strategy, string $to): array
    {
        $ownerKey = 'strategy:'.$strategy->id;
        $transactions = Transaction::query()
            ->where('profile_id', $strategy->profile_id)->where('owner_key', $ownerKey)
            ->where('transaction_date', '<=', $to)->orderBy('transaction_date')->orderBy('id')->get();
        $hasAdoption = HoldingAdoption::query()
            ->where('profile_id', $strategy->profile_id)->where('to_strategy_id', $strategy->id)
            ->where('created_at', '<=', Carbon::parse($to)->endOfDay())->exists();

        if ($transactions->isEmpty() || $hasAdoption) {
            return [
                'scope' => 'strategy', 'strategy_id' => $strategy->id, 'as_of' => $to,
                'xirr_percent' => null, 'twr_percent' => null, 'completeness' => 'incomplete',
                'limitations' => [$hasAdoption
                    ? 'ownership_adoption_capital_history_not_reconstructable'
                    : 'no_owner_attributed_capital_history'],
                'cash_account_invented' => false,
            ];
        }

        $state = $this->holdings->holdingsAsOf($transactions, Carbon::parse($to)->startOfDay());
        $terminalValue = 0.0;
        foreach ($state as $stockId => $holding) {
            $close = $this->quotes->latestClose((int) $stockId, Carbon::parse($to)->startOfDay());
            if ($close === null || $close <= 0.0) {
                return [
                    'scope' => 'strategy', 'strategy_id' => $strategy->id, 'as_of' => $to,
                    'xirr_percent' => null, 'twr_percent' => null, 'completeness' => 'incomplete',
                    'limitations' => ['missing_terminal_price'], 'cash_account_invented' => false,
                ];
            }
            $terminalValue += (float) $holding['quantity'] * $close;
        }

        return [
            'scope' => 'strategy', 'strategy_id' => $strategy->id,
            'period' => ['from' => $transactions->first()->transaction_date->toDateString(), 'to' => $to],
            'xirr_percent' => $this->xirr->calculateFromTransactions(
                $transactions, $terminalValue, Carbon::parse($to)->startOfDay(),
            ),
            'twr_percent' => null, 'terminal_holdings_value' => round($terminalValue, 4),
            'completeness' => 'estimate_with_limitations',
            'limitations' => ['strategy_twr_unavailable_without_authoritative_daily_logical_capital_history'],
            'cash_account_invented' => false,
        ];
    }
}
