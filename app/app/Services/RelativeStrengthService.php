<?php

namespace App\Services;

use App\Models\Stock;
use Carbon\Carbon;

class RelativeStrengthService
{
    public function __construct(
        protected StockPriceHistoryService $history,
        protected IndexCatalogService $indexCatalog,
        protected DataQualityGuardService $dataQualityGuard,
    ) {}

    public function calculateForStock(Stock $stock, ?Carbon $asOf = null): array
    {
        if ($this->dataQualityGuard->isBlockedStock($stock)) {
            return [
                'relative_strength_1m' => null,
                'relative_strength_3m' => null,
                'relative_strength_6m' => null,
            ];
        }

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
        return $this->indexCatalog->primaryBenchmarkStock();
    }
}
