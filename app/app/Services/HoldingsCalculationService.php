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
        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->where('stock_id', $stock->id)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $quantity = 0.0;
        $avgBuyPrice = 0.0;
        $investedAmount = 0.0;
        $realizedProfit = 0.0;
        $wasZero = true;

        foreach ($transactions as $transaction) {
            $qty = (float) $transaction->quantity;
            $price = (float) $transaction->price;
            $fees = (float) $transaction->fees;

            if ($transaction->type === 'buy') {
                if ($wasZero && $quantity <= 0) {
                    $this->resetMetricsForNewEntry($stock);
                    $wasZero = false;
                }

                $cost = ($qty * $price) + $fees;
                $investedAmount += $cost;
                $quantity += $qty;
                $avgBuyPrice = $quantity > 0 ? $investedAmount / $quantity : 0;
            } else {
                if ($qty > $quantity + 0.00001) {
                    throw new InvalidArgumentException('Cannot sell more quantity than currently owned.');
                }

                $realizedProfit += (($price - $avgBuyPrice) * $qty) - $fees;
                $quantity -= $qty;
                $investedAmount = $avgBuyPrice * $quantity;

                if ($quantity <= 0.00001) {
                    $quantity = 0;
                    $avgBuyPrice = 0;
                    $investedAmount = 0;
                    $wasZero = true;
                    $this->deactivateTracking($stock);
                    app(AlertExpirationService::class)->expireForUserStockIfUnheld($user, $stock);
                }
            }
        }

        return Holding::query()->updateOrCreate(
            ['user_id' => $user->id, 'stock_id' => $stock->id],
            [
                'quantity' => round($quantity, 4),
                'avg_buy_price' => round($avgBuyPrice, 4),
                'invested_amount' => round($investedAmount, 4),
                'realized_profit' => round($realizedProfit, 4),
                'updated_at' => now(),
            ],
        );
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
