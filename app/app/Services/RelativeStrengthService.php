<?php

namespace App\Services;

use App\Models\Stock;
use Carbon\Carbon;

class RelativeStrengthService
{
    public function __construct(protected StockPriceHistoryService $history) {}

    public function calculateForStock(Stock $stock, ?Carbon $asOf = null): array
    {
        $benchmark = $this->benchmarkStock();
        $this->history->ensureAnalyticsHistory($stock, 6);
        $this->history->ensureAnalyticsHistory($benchmark, 6);

        return [
            'relative_strength_1m' => $this->history->getRelativeStrength($stock, $benchmark, 1, $asOf),
            'relative_strength_3m' => $this->history->getRelativeStrength($stock, $benchmark, 3, $asOf),
            'relative_strength_6m' => $this->history->getRelativeStrength($stock, $benchmark, 6, $asOf),
        ];
    }

    public function relativeStrength(Stock $stock, Stock $benchmark, int $months, Carbon $asOf): ?float
    {
        return $this->history->getRelativeStrength($stock, $benchmark, $months, $asOf);
    }

    public function benchmarkStock(): Stock
    {
        $stock = Stock::query()->firstOrCreate(
            ['symbol' => 'NIFTY50', 'exchange' => 'NSE'],
            [
                'name' => 'NIFTY 50 Index',
                'is_active' => true,
                'is_benchmark' => true,
                'yahoo_symbol' => '^NSEI',
                'alpha_vantage_symbol' => 'NSEI',
            ],
        );

        $stock = app(ProviderResolverService::class)->applyProviderSymbols($stock);
        if ($stock->isDirty()) {
            $stock->save();
        }

        return $stock->fresh();
    }
}
