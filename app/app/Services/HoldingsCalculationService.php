<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockMetric;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class HoldingsCalculationService
{
    public function recalculateForUser(User $user): Collection
    {
        $stockIds = Transaction::query()
            ->where('user_id', $user->id)
            ->distinct()
            ->pluck('stock_id');

        $holdings = collect();

        foreach ($stockIds as $stockId) {
            $holdings->push($this->recalculateForUserStock($user, Stock::query()->findOrFail($stockId)));
        }

        Holding::query()
            ->where('user_id', $user->id)
            ->whereNotIn('stock_id', $stockIds)
            ->delete();

        return $holdings;
    }

    public function recalculateForUserStock(User $user, Stock $stock): Holding
    {
        $transactions = $this->transactionsForUserStock($user, $stock);
        $state = $this->replayTransactions($transactions, $user, $stock);

        return Holding::query()->updateOrCreate(
            ['user_id' => $user->id, 'stock_id' => $stock->id],
            [
                'quantity' => round($state['quantity'], 4),
                'avg_buy_price' => round($state['avg_buy_price'], 4),
                'invested_amount' => round($state['invested_amount'], 4),
                'total_fees' => round($state['total_fees'], 4),
                'realized_profit' => round($state['realized_profit'], 4),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Dry-run replay after hypothetically removing a transaction.
     *
     * @throws InvalidArgumentException when remaining ledger is invalid (e.g. orphan sells)
     */
    public function assertReplayValidAfterDeleting(User $user, Transaction $toDelete): void
    {
        $remaining = $this->transactionsForUserStock($user, Stock::query()->findOrFail($toDelete->stock_id))
            ->reject(fn (Transaction $tx) => (int) $tx->id === (int) $toDelete->id)
            ->values();

        $this->replayTransactions($remaining, $user, $toDelete->stock, dryRun: true);
    }

    /**
     * @return Collection<int, Transaction>
     */
    protected function transactionsForUserStock(User $user, Stock $stock): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('stock_id', $stock->id)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array{
     *   quantity: float,
     *   avg_buy_price: float,
     *   invested_amount: float,
     *   total_fees: float,
     *   realized_profit: float
     * }
     *
     * @throws InvalidArgumentException
     */
    public function replayTransactions(
        Collection $transactions,
        ?User $user = null,
        ?Stock $stock = null,
        bool $dryRun = false,
    ): array {
        $quantity = 0.0;
        $avgBuyPrice = 0.0;
        $investedAmount = 0.0;
        $totalFees = 0.0;
        $realizedProfit = 0.0;
        $wasZero = true;

        foreach ($transactions as $transaction) {
            $qty = (float) $transaction->quantity;
            $price = (float) $transaction->price;
            $fees = (float) $transaction->fees;

            if ($transaction->type === 'buy') {
                if (! $dryRun && $wasZero && $quantity <= 0 && $user !== null && $stock !== null) {
                    $this->resetMetricsForNewEntry($stock);
                    $wasZero = false;
                    $totalFees = 0.0;
                } elseif ($wasZero && $quantity <= 0) {
                    $wasZero = false;
                    $totalFees = 0.0;
                }

                $investedAmount += $qty * $price;
                $totalFees += $fees;
                $quantity += $qty;
                $avgBuyPrice = $quantity > 0 ? $investedAmount / $quantity : 0;
            } else {
                if ($qty > $quantity + 0.00001) {
                    throw new InvalidArgumentException('Cannot sell more quantity than currently owned.');
                }

                $realizedProfit += (($price - $avgBuyPrice) * $qty) - $fees;
                $totalFees += $fees;
                $quantity -= $qty;
                $investedAmount = $avgBuyPrice * $quantity;

                if ($quantity <= 0.00001) {
                    $quantity = 0;
                    $avgBuyPrice = 0;
                    $investedAmount = 0;
                    $totalFees = 0;
                    $wasZero = true;

                    if (! $dryRun && $user !== null && $stock !== null) {
                        $this->deactivateTracking($stock);
                        app(AlertExpirationService::class)->expireForUserStockIfUnheld($user, $stock);
                    }
                }
            }
        }

        return [
            'quantity' => $quantity,
            'avg_buy_price' => $avgBuyPrice,
            'invested_amount' => $investedAmount,
            'total_fees' => $totalFees,
            'realized_profit' => $realizedProfit,
        ];
    }

    public function getAvailableQuantity(User $user, Stock $stock): float
    {
        $holding = Holding::query()
            ->where('user_id', $user->id)
            ->where('stock_id', $stock->id)
            ->first();

        return $holding ? (float) $holding->quantity : 0.0;
    }

    protected function deactivateTracking(Stock $stock): void
    {
        StockMetric::query()->updateOrCreate(
            ['stock_id' => $stock->id],
            ['tracking_active' => false, 'updated_at' => now()],
        );
    }

    protected function resetMetricsForNewEntry(Stock $stock): void
    {
        $defaultStoploss = (float) app(SettingsService::class)->get('default_stoploss_percent', '10');

        StockMetric::query()->updateOrCreate(
            ['stock_id' => $stock->id],
            [
                'highest_close' => null,
                'latest_close' => null,
                'trailing_stop_price' => null,
                'tracking_active' => true,
                'stoploss_percent' => $defaultStoploss,
                'updated_at' => now(),
            ],
        );
    }
}
