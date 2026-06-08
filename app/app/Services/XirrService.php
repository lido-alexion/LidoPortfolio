<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class XirrService
{
    public function __construct(
        protected StockQuoteService $quotes,
    ) {}

    public function calculatePortfolioXirr(User $user, ?Carbon $asOf = null, ?float $terminalValue = null): ?float
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->orderBy('transaction_date')
            ->get();

        if ($terminalValue === null) {
            $terminalValue = $this->terminalValueForOpenHoldings($user, $asOf);
        }

        return $this->calculateFromTransactions($transactions, $terminalValue, $asOf);
    }

    public function calculateStockXirr(User $user, int $stockId, ?Carbon $asOf = null, ?float $terminalValue = null): ?float
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->where('stock_id', $stockId)
            ->orderBy('transaction_date')
            ->get();

        if ($terminalValue === null) {
            $holdingQty = $transactions->reduce(function (float $qty, Transaction $tx) {
                $change = (float) $tx->quantity;

                return $tx->type === 'buy' ? $qty + $change : $qty - $change;
            }, 0.0);

            $terminalValue = $holdingQty > 0
                ? $holdingQty * $this->quotes->latestClose($stockId, $asOf)
                : 0.0;
        }

        return $this->calculateFromTransactions($transactions, $terminalValue, $asOf);
    }

    public function calculateFromTransactions(Collection $transactions, float $terminalValue, Carbon $asOf): ?float
    {
        $flows = [];

        foreach ($transactions as $transaction) {
            $amount = (float) $transaction->quantity * (float) $transaction->price;
            $amount += (float) $transaction->fees;
            $flows[] = [
                'date' => Carbon::parse($transaction->transaction_date)->startOfDay(),
                'amount' => $transaction->type === 'buy' ? -$amount : $amount,
            ];
        }

        if ($terminalValue > 0) {
            $flows[] = ['date' => $asOf->copy()->startOfDay(), 'amount' => $terminalValue];
        }

        if (count($flows) < 2) {
            return null;
        }

        $hasNegative = collect($flows)->contains(fn ($f) => $f['amount'] < 0);
        $hasPositive = collect($flows)->contains(fn ($f) => $f['amount'] > 0);

        if (! $hasNegative || ! $hasPositive) {
            return null;
        }

        return $this->solveXirr($flows);
    }

    protected function terminalValueForOpenHoldings(User $user, Carbon $asOf): float
    {
        $holdings = $user->holdings()
            ->where('quantity', '>', 0)
            ->get(['stock_id', 'quantity']);

        $total = 0.0;
        foreach ($holdings as $holding) {
            $total += ((float) $holding->quantity) * $this->quotes->latestClose((int) $holding->stock_id, $asOf);
        }

        return $total;
    }

    protected function solveXirr(array $flows, float $guess = 0.1): ?float
    {
        $rate = $guess;

        for ($i = 0; $i < 100; $i++) {
            $npv = 0.0;
            $derivative = 0.0;

            foreach ($flows as $flow) {
                // Carbon 3+ returns signed day deltas; XIRR needs elapsed time from the first flow.
                $years = $flow['date']->diffInDays($flows[0]['date'], true) / 365.0;
                $denominator = pow(1 + $rate, $years);
                if ($denominator == 0.0) {
                    return null;
                }
                $npv += $flow['amount'] / $denominator;
                $derivative -= ($years * $flow['amount']) / ($denominator * (1 + $rate));
            }

            if (abs($npv) < 1e-7) {
                return round($rate * 100, 4);
            }

            if (abs($derivative) < 1e-10) {
                break;
            }

            $rate -= $npv / $derivative;

            if ($rate <= -0.9999) {
                $rate = -0.9999;
            }
        }

        return null;
    }
}
