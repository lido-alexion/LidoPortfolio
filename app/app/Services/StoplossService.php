<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockMetric;

class StoplossService
{
    public function __construct(
        protected SettingsService $settings,
        protected ProfileSettingsService $profileSettings,
        protected HoldingPresentationService $holdingPresentation,
        protected StockQuoteService $quotes,
    ) {}

    public function updateMetricsForStock(Stock $stock): StockMetric
    {
        $metric = StockMetric::query()->firstOrCreate(
            ['stock_id' => $stock->id],
            [
                'stoploss_percent' => $this->defaultStoplossPercentForStock($stock),
                'tracking_active' => true,
                'updated_at' => now(),
            ],
        );

        if (! $metric->tracking_active) {
            return $metric;
        }

        $hasActiveHolding = Holding::query()
            ->where('stock_id', $stock->id)
            ->where('quantity', '>', 0)
            ->exists();

        if (! $hasActiveHolding && ! $stock->is_benchmark) {
            $metric->update(['tracking_active' => false, 'updated_at' => now()]);

            return $metric->fresh();
        }

        if ($stock->is_benchmark) {
            return $metric;
        }

        $latestClose = $this->quotes->latestClose((int) $stock->id);

        if ($latestClose <= 0) {
            return $metric;
        }

        $highestSinceBuy = $this->maxHighestCloseSinceBuy($stock);
        $highestClose = $highestSinceBuy !== null
            ? max($latestClose, $highestSinceBuy)
            : $latestClose;
        $stopPercent = (float) $metric->stoploss_percent;
        $trailingStop = $highestClose * (1 - ($stopPercent / 100));

        $metric->update([
            'latest_close' => $latestClose,
            'highest_close' => $highestClose,
            'trailing_stop_price' => round($trailingStop, 4),
            'updated_at' => now(),
        ]);

        return $metric->fresh();
    }

    public function processAllActiveStocks(): void
    {
        $stockIds = Holding::query()
            ->where('quantity', '>', 0)
            ->distinct()
            ->pluck('stock_id');

        foreach ($stockIds as $stockId) {
            $stock = Stock::query()->find($stockId);
            if ($stock) {
                $this->updateMetricsForStock($stock);
            }
        }
    }

    protected function maxHighestCloseSinceBuy(Stock $stock): ?float
    {
        $holdings = Holding::query()
            ->with('profile')
            ->where('stock_id', $stock->id)
            ->where('quantity', '>', 0)
            ->get();

        $max = null;

        foreach ($holdings as $holding) {
            $profile = $holding->profile;
            if (! $profile) {
                continue;
            }

            $summary = $this->holdingPresentation->enrichHolding($profile, $holding)['stoploss_summary'] ?? [];
            $highest = $summary['highest_close_since_buy'] ?? null;

            if ($highest === null) {
                continue;
            }

            $max = $max === null ? (float) $highest : max($max, (float) $highest);
        }

        return $max;
    }

    protected function defaultStoplossPercentForStock(Stock $stock): float
    {
        $profileId = Holding::query()
            ->where('stock_id', $stock->id)
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->value('profile_id');

        if (! $profileId) {
            return 10.0;
        }

        $profile = PortfolioProfile::query()->find($profileId);

        return $profile
            ? (float) $this->profileSettings->get($profile, 'default_stoploss_percent', '10')
            : 10.0;
    }
}
