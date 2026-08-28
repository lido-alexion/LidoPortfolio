<?php

namespace App\Services\Protection;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\PositionProtection;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\ProfileSettingsService;
use App\Services\Risk\OwnershipEpisodeService;
use App\Services\Risk\PortfolioStopLossCalculator;

/**
 * Strategy-derived GTT prices. No independent broker-order price.
 *
 * Target trigger = OD-12 rupee target_amount / quantity.
 * Stop trigger = OD-13 weighted-average fill × (1 − portfolio SL %).
 */
class ProtectionTriggerPriceResolver
{
    public function __construct(
        protected OwnershipEpisodeService $episodes,
        protected PortfolioStopLossCalculator $stopLoss,
        protected ProfileSettingsService $settings,
    ) {}

    public function triggerPrice(PortfolioProfile $profile, Holding $holding, string $type): ?float
    {
        if ($type === PositionProtection::TYPE_TARGET) {
            return $this->targetPrice($holding);
        }
        if ($type === PositionProtection::TYPE_STOP) {
            return $this->stopPrice($profile, $holding);
        }

        return null;
    }

    public function targetPrice(Holding $holding): ?float
    {
        $qty = (float) $holding->quantity;
        $target = $holding->target_amount !== null ? (float) $holding->target_amount : 0.0;
        if ($qty <= 0.0001 || $target <= 0.0001) {
            return null;
        }

        $price = $target / $qty;

        return $price > 0.0001 ? round($price, 4) : null;
    }

    public function stopPrice(PortfolioProfile $profile, Holding $holding): ?float
    {
        $stock = $holding->stock ?? Stock::query()->find($holding->stock_id);
        if (! $stock) {
            return null;
        }
        $fills = $this->episodes->fillsForCurrentEpisode($profile, $holding, $stock);
        if ($fills === []) {
            return null;
        }
        try {
            $avg = $this->stopLoss->weightedAverageFillCost($fills);
            $percent = (float) $this->settings->get($profile, 'default_stoploss_percent', '10');
            $price = round($this->stopLoss->stopPrice($avg, $percent), 4);

            return $price > 0.0001 ? $price : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function lastPrice(Holding $holding, float $fallback): float
    {
        $close = StockPrice::query()
            ->where('stock_id', $holding->stock_id)
            ->orderByDesc('price_date')
            ->value('close_price');
        if ($close !== null && (float) $close > 0) {
            return (float) $close;
        }

        return $fallback;
    }
}
