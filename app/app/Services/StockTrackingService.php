<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockMetric;

class StockTrackingService
{
    /**
     * Portfolio / tracked stocks: owned, watchlist-style metrics, alerts, or stoploss monitoring.
     */
    public function isPortfolioTracked(Stock $stock, ?PortfolioProfile $profile = null): bool
    {
        if ($stock->is_benchmark) {
            return true;
        }

        $holdingQuery = Holding::query()->where('stock_id', $stock->id);
        if ($profile) {
            $holdingQuery->where('profile_id', $profile->id);
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

        if ($profile) {
            if (Alert::query()
                ->where('profile_id', $profile->id)
                ->where('stock_id', $stock->id)
                ->exists()) {
                return true;
            }
        } elseif (Alert::query()->where('stock_id', $stock->id)->exists()) {
            return true;
        }

        $transactionQuery = $stock->transactions();
        if ($profile) {
            $transactionQuery->where('profile_id', $profile->id);
        }

        if ($transactionQuery->exists()) {
            return true;
        }

        return false;
    }

    public function isExploratory(Stock $stock, ?PortfolioProfile $profile = null): bool
    {
        return ! $this->isPortfolioTracked($stock, $profile);
    }
}
