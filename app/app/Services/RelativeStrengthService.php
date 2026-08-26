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

    /**
     * Evaluation RS fact (V4-FEAT-021). Optional Strategy benchmark symbol and lookback days.
     * Null lookback keeps the historical 3-month vs-benchmark path.
     * Unresolvable benchmark symbols fall back to the primary index.
     */
    public function evaluationRelativeStrength(
        Stock $stock,
        ?string $benchmarkSymbol = null,
        ?int $lookbackDays = null,
        ?Carbon $asOf = null,
    ): ?float {
        if ($this->dataQualityGuard->isBlockedStock($stock)) {
            return null;
        }

        $benchmark = $this->resolveEvaluationBenchmark($benchmarkSymbol);
        $this->history->ensureAnalyticsHistory($stock, 6);
        $this->history->ensureAnalyticsHistory($benchmark, 6);

        if ($lookbackDays !== null && $lookbackDays >= 1) {
            $stockReturn = $this->history->getGrowthPercentageForDays($stock, $lookbackDays, $asOf);
            $benchmarkReturn = $this->history->getGrowthPercentageForDays($benchmark, $lookbackDays, $asOf);
            if ($stockReturn === null || $benchmarkReturn === null) {
                return null;
            }

            return round($stockReturn - $benchmarkReturn, 4);
        }

        return $this->history->getRelativeStrength($stock, $benchmark, 3, $asOf);
    }

    public function benchmarkStock(): Stock
    {
        return $this->indexCatalog->primaryBenchmarkStock();
    }

    protected function resolveEvaluationBenchmark(?string $benchmarkSymbol): Stock
    {
        $symbol = strtoupper(trim((string) $benchmarkSymbol));
        if ($symbol === '') {
            return $this->benchmarkStock();
        }

        $def = $this->indexCatalog->definitionForSymbol($symbol);
        if ($def !== null && ($def['enabled'] ?? true) === true) {
            return $this->indexCatalog->ensureIndexStock($def);
        }

        return $this->benchmarkStock();
    }
}
