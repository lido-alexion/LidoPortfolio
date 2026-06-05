<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Reconstructs open-holdings state as-of a historical date from the transaction ledger.
 */
class PortfolioHistoricalHoldingsService
{
    /**
     * @param  Collection<int, object>  $transactions  ordered by transaction_date, id
     * @return array<int, array{quantity: float, invested_amount: float, avg_buy_price: float}>
     */
    public function holdingsAsOf(Collection $transactions, Carbon $asOf): array
    {
        $asOfDate = $asOf->copy()->startOfDay()->toDateString();
        $byStock = [];

        foreach ($transactions as $transaction) {
            $txDate = Carbon::parse($transaction->transaction_date)->toDateString();
            if ($txDate > $asOfDate) {
                continue;
            }

            $stockId = (int) $transaction->stock_id;
            $qty = (float) $transaction->quantity;
            $price = (float) $transaction->price;
            $brokerage = (float) ($transaction->brokerage ?? 0);

            if (! isset($byStock[$stockId])) {
                $byStock[$stockId] = [
                    'quantity' => 0.0,
                    'invested_amount' => 0.0,
                    'avg_buy_price' => 0.0,
                ];
            }

            $state = &$byStock[$stockId];

            if ($transaction->type === 'buy') {
                $cost = ($qty * $price) + $brokerage;
                $state['invested_amount'] += $cost;
                $state['quantity'] += $qty;
                $state['avg_buy_price'] = $state['quantity'] > 0
                    ? $state['invested_amount'] / $state['quantity']
                    : 0.0;
            } else {
                if ($qty > $state['quantity'] + 0.00001) {
                    continue;
                }

                $state['quantity'] -= $qty;
                $state['invested_amount'] = $state['avg_buy_price'] * $state['quantity'];

                if ($state['quantity'] <= 0.00001) {
                    unset($byStock[$stockId]);
                }
            }
        }

        return $byStock;
    }
}
