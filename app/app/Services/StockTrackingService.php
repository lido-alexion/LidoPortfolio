<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockMetric;
use App\Models\User;

class StockTrackingService
{
    /**
     * Portfolio / tracked stocks: owned, watchlist-style metrics, alerts, or stoploss monitoring.
     */
    public function isPortfolioTracked(Stock $stock, ?User $user = null): bool
    {
        if ($stock->is_benchmark) {
            return true;
        }

        $holdingQuery = Holding::query()->where('stock_id', $stock->id);
        if ($user) {
            $holdingQuery->where('user_id', $user->id);
        }

        if ($holdingQuery->where('quantity', '>', 0)->exists()) {
            return true;
        }

        if (StockMetric::query()
            ->where('stock_id', $stock->id)
            ->where('tracking_active', true)
            ->exists()) {
            return true;
        }

        if ($user) {
            if (Alert::query()
                ->where('user_id', $user->id)
                ->where('stock_id', $stock->id)
                ->exists()) {
                return true;
            }
        } elseif (Alert::query()->where('stock_id', $stock->id)->exists()) {
            return true;
        }

        $transactionQuery = $stock->transactions();
        if ($user) {
            $transactionQuery->where('user_id', $user->id);
        }

        if ($transactionQuery->exists()) {
            return true;
        }

        return false;
    }

    public function isExploratory(Stock $stock, ?User $user = null): bool
    {
        return ! $this->isPortfolioTracked($stock, $user);
    }
}
