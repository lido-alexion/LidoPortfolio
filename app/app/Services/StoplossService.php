<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockMetric;
use App\Models\StockPrice;
use App\Models\User;

class StoplossService
{
    public function __construct(
        protected SettingsService $settings,
    ) {}

    public function updateMetricsForStock(Stock $stock): StockMetric
    {
        $metric = StockMetric::query()->firstOrCreate(
            ['stock_id' => $stock->id],
            [
                'stoploss_percent' => (float) $this->settings->get('default_stoploss_percent', '10'),
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

        $latestPrice = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderByDesc('price_date')
            ->first();

        if (! $latestPrice) {
            return $metric;
        }

        $latestClose = (float) $latestPrice->close_price;
        $peakClose = (float) (StockPrice::query()
            ->where('stock_id', $stock->id)
            ->max('close_price') ?? 0);
        $highestClose = max((float) ($metric->highest_close ?? 0), $latestClose, $peakClose);
        $stopPercent = (float) $metric->stoploss_percent;
        $trailingStop = $highestClose * (1 - ($stopPercent / 100));

        $metric->update([
            'latest_close' => $latestClose,
            'highest_close' => $highestClose,
            'trailing_stop_price' => round($trailingStop, 4),
            'updated_at' => now(),
        ]);

        if ($latestClose <= $trailingStop) {
            $this->triggerStoplossAlert($stock, $metric, $latestClose);
        }

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

    protected function triggerStoplossAlert(Stock $stock, StockMetric $metric, float $latestClose): void
    {
        $userIds = Holding::query()
            ->where('stock_id', $stock->id)
            ->where('quantity', '>', 0)
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return;
        }

        $message = sprintf(
            'Stoploss triggered for %s (%s). Latest close: %.2f, Trailing stop: %.2f',
            $stock->name,
            $stock->symbol,
            $latestClose,
            (float) $metric->trailing_stop_price,
        );

        foreach ($userIds as $userId) {
            $exists = Alert::query()
                ->where('user_id', $userId)
                ->where('stock_id', $stock->id)
                ->where('alert_type', 'stoploss_triggered')
                ->whereDate('created_at', now()->toDateString())
                ->exists();

            if ($exists) {
                continue;
            }

            Alert::query()->create([
                'user_id' => $userId,
                'stock_id' => $stock->id,
                'alert_type' => 'stoploss_triggered',
                'message' => $message,
                'is_sent' => false,
                'created_at' => now(),
            ]);
        }
    }

    public function getActiveAlertsForUser(User $user): array
    {
        return Alert::query()
            ->active()
            ->with('stock')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->toArray();
    }
}
