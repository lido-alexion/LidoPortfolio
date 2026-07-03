<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class TransactionRealizationService
{
    /**
     * Recompute FIFO realized P/L and proportional squared-off fees for all sell
     * transactions on a profile + stock ledger.
     */
    public function recalculateForProfileStock(PortfolioProfile $profile, Stock $stock): void
    {
        Transaction::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('type', 'sell')
            ->update([
                'realized_pl' => null,
                'squared_off_fees' => null,
            ]);

        $transactions = Transaction::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $this->applyFifoRealizations($transactions);
    }

    /**
     * @return int Number of profile/stock ledgers processed
     */
    public function backfillAll(?int $profileId = null): int
    {
        $query = Transaction::query()
            ->select(['profile_id', 'stock_id'])
            ->distinct();

        if ($profileId !== null) {
            $query->where('profile_id', $profileId);
        }

        $pairs = $query->get();
        $processed = 0;

        foreach ($pairs as $pair) {
            $profile = PortfolioProfile::query()->find($pair->profile_id);
            $stock = Stock::query()->find($pair->stock_id);
            if ($profile === null || $stock === null) {
                continue;
            }

            $this->recalculateForProfileStock($profile, $stock);
            $processed++;
        }

        return $processed;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    protected function applyFifoRealizations(Collection $transactions): void
    {
        /** @var list<array{remaining: float, original_qty: float, price: float, fees: float}> $lots */
        $lots = [];

        foreach ($transactions as $transaction) {
            if ($transaction->type === 'buy') {
                $lots[] = [
                    'remaining' => (float) $transaction->quantity,
                    'original_qty' => (float) $transaction->quantity,
                    'price' => (float) $transaction->price,
                    'fees' => (float) $transaction->fees,
                ];

                continue;
            }

            $sellQty = (float) $transaction->quantity;
            $sellPrice = (float) $transaction->price;
            $sellFees = (float) $transaction->fees;
            $remainingSell = $sellQty;
            $realizedPl = 0.0;
            $allocatedFees = $sellFees;

            while ($remainingSell > 0.00001) {
                if ($lots === []) {
                    throw new InvalidArgumentException('Cannot sell more quantity than currently owned (FIFO).');
                }

                $lot = &$lots[0];
                $matchQty = min($remainingSell, $lot['remaining']);
                $realizedPl += ($sellPrice - $lot['price']) * $matchQty;

                if ($lot['original_qty'] > 0) {
                    $allocatedFees += ($matchQty / $lot['original_qty']) * $lot['fees'];
                }

                $lot['remaining'] -= $matchQty;
                $remainingSell -= $matchQty;

                if ($lot['remaining'] <= 0.00001) {
                    array_shift($lots);
                }
            }

            $transaction->realized_pl = round($realizedPl, 4);
            $transaction->squared_off_fees = round($allocatedFees, 4);
            $transaction->save();
        }
    }
}
