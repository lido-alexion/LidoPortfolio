<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Reconstructs open-holdings state as-of a historical date from the transaction ledger.
 *
 * F015 callers use holdingsAsOf() (map only). F014 uses holdingsAsOfDetailed() for warnings.
 */
class PortfolioHistoricalHoldingsService
{
    /**
     * @param  Collection<int, object>  $transactions  ordered by transaction_date, id
     * @return array<int, array{quantity: float, invested_amount: float, avg_buy_price: float}>
     */
    public function holdingsAsOf(Collection $transactions, Carbon $asOf): array
    {
        return $this->holdingsAsOfDetailed($transactions, $asOf)['holdings'];
    }

    /**
     * @param  Collection<int, object>  $transactions  ordered by transaction_date, id
     * @return array{
     *   holdings: array<int, array{quantity: float, invested_amount: float, avg_buy_price: float}>,
     *   warnings: list<array{code: string, message: string, stock_id: int, transaction_id: int|null, transaction_date: string|null, quantity: float|null, held_quantity: float|null}>
     * }
     */
    public function holdingsAsOfDetailed(Collection $transactions, Carbon $asOf): array
    {
        $asOfDate = $asOf->copy()->startOfDay()->toDateString();
        $byStock = [];
        $warnings = [];

        foreach ($transactions as $transaction) {
            $txDate = Carbon::parse($transaction->transaction_date)->toDateString();
            if ($txDate > $asOfDate) {
                continue;
            }

            $stockId = (int) $transaction->stock_id;
            $qty = (float) $transaction->quantity;
            $price = (float) $transaction->price;
            $type = strtolower((string) ($transaction->type ?? ''));

            if ($type === 'buy') {
                if (! isset($byStock[$stockId])) {
                    $byStock[$stockId] = [
                        'quantity' => 0.0,
                        'invested_amount' => 0.0,
                        'avg_buy_price' => 0.0,
                    ];
                }

                $state = &$byStock[$stockId];
                // Fee-exclusive cost basis (PD-F014-04): price × qty only.
                $state['invested_amount'] += $qty * $price;
                $state['quantity'] += $qty;
                $state['avg_buy_price'] = $state['quantity'] > 0
                    ? $state['invested_amount'] / $state['quantity']
                    : 0.0;
                unset($state);
            } else {
                $held = isset($byStock[$stockId]) ? (float) $byStock[$stockId]['quantity'] : 0.0;
                if ($qty > $held + 0.00001) {
                    $warnings[] = [
                        'code' => 'historical_oversell',
                        'message' => 'Sell quantity exceeds reconstructed holding quantity as of this date; sell was skipped for reconstruction.',
                        'stock_id' => $stockId,
                        'transaction_id' => isset($transaction->id) ? (int) $transaction->id : null,
                        'transaction_date' => $txDate,
                        'quantity' => $qty,
                        'held_quantity' => round($held, 4),
                    ];
                    continue;
                }

                $state = &$byStock[$stockId];
                $state['quantity'] -= $qty;
                $state['invested_amount'] = $state['avg_buy_price'] * $state['quantity'];

                if ($state['quantity'] <= 0.00001) {
                    unset($byStock[$stockId]);
                }
                unset($state);
            }
        }

        return [
            'holdings' => $byStock,
            'warnings' => $warnings,
        ];
    }
}
