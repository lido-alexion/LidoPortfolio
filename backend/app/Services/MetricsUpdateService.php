<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockMetric;

class MetricsUpdateService
{
    public function __construct(
        protected RelativeStrengthService $relativeStrength,
        protected StoplossService $stoploss,
    ) {}

    public function updateStock(Stock $stock): StockMetric
    {
        $metric = $this->stoploss->updateMetricsForStock($stock);

        if (! $metric->tracking_active || $stock->is_benchmark) {
            return $metric;
        }

        $rs = $this->relativeStrength->calculateForStock($stock);
        $metric->update(array_merge($rs, ['updated_at' => now()]));

        return $metric->fresh();
    }

    public function updateAllTrackedStocks(): void
    {
        $heldStockIds = Holding::query()
            ->where('quantity', '>', 0)
            ->distinct()
            ->pluck('stock_id');

        $stocks = Stock::query()
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->whereIn('id', $heldStockIds)
            ->get();

        foreach ($stocks as $stock) {
            $metric = StockMetric::query()->where('stock_id', $stock->id)->first();
            if ($metric && ! $metric->tracking_active) {
                continue;
            }
            $this->updateStock($stock);
        }
    }
}
