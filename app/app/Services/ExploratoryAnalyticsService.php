<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\Stock;
use Carbon\Carbon;

class ExploratoryAnalyticsService
{
    public function __construct(
        protected StockPriceHistoryService $history,
        protected StockValidationService $validation,
        protected RelativeStrengthService $relativeStrength,
        protected StockTrackingService $tracking,
    ) {}

    /**
     * @param  array<int, int>  $periodMonths
     * @return array<string, mixed>
     */
    public function analyze(
        PortfolioProfile $profile,
        string $symbol,
        string $exchange = 'NSE',
        ?string $benchmarkSymbol = 'NIFTY50',
        array $periodMonths = [1, 3],
    ): array {
        $result = $this->validation->validateAndPersist($symbol, $exchange);
        if (! $result->valid || ! $result->stock) {
            return [
                'valid' => false,
                'errors' => $result->errors,
            ];
        }

        $stock = $result->stock;
        $benchmark = $this->relativeStrength->benchmarkStock();
        if ($benchmarkSymbol && $benchmarkSymbol !== 'NIFTY50') {
            $benchResult = $this->validation->validate($benchmarkSymbol, 'NSE');
            if ($benchResult->valid && $benchResult->stock) {
                $benchmark = $benchResult->stock;
            }
        }

        $maxMonths = max($periodMonths ?: [1, 3]);
        $stockFetch = $this->history->ensureAnalyticsHistory($stock, $maxMonths);
        $benchmarkFetch = $this->history->ensureAnalyticsHistory($benchmark, $maxMonths);

        $asOf = now()->startOfDay();
        $growth = [];
        $benchmarkGrowth = [];
        $relativeStrength = [];
        $periodCloses = [];

        foreach ($periodMonths as $months) {
            $key = "{$months}m";
            $startTarget = $asOf->copy()->subMonths($months);

            $growth[$key] = $this->history->getGrowthPercentage($stock, $months, $asOf);
            $benchmarkGrowth[$key] = $this->history->getGrowthPercentage($benchmark, $months, $asOf);
            $relativeStrength[$key] = $this->history->getRelativeStrength($stock, $benchmark, $months, $asOf);

            $periodCloses[$key] = [
                'stock_start_close' => $this->history->getCloseOnOrBeforeDate($stock, $startTarget),
                'stock_end_close' => $this->history->getCloseOnOrBeforeDate($stock, $asOf),
                'benchmark_start_close' => $this->history->getCloseOnOrBeforeDate($benchmark, $startTarget),
                'benchmark_end_close' => $this->history->getCloseOnOrBeforeDate($benchmark, $asOf),
                'start_date' => $startTarget->toDateString(),
                'end_date' => $asOf->toDateString(),
            ];
        }

        $latestClose = $this->history->getLatestClose($stock, $asOf);
        $benchmarkClose = $this->history->getLatestClose($benchmark, $asOf);

        return [
            'valid' => true,
            'stock' => $stock->fresh(),
            'benchmark' => [
                'symbol' => $benchmark->symbol,
                'name' => $benchmark->name,
                'latest_close' => $benchmarkClose,
            ],
            'tracking' => [
                'is_portfolio_tracked' => $this->tracking->isPortfolioTracked($stock, $profile),
                'is_exploratory' => $this->tracking->isExploratory($stock, $profile),
            ],
            'latest_close' => $latestClose,
            'as_of' => $asOf->toDateString(),
            'growth_percent' => $growth,
            'benchmark_growth_percent' => $benchmarkGrowth,
            'relative_strength' => $relativeStrength,
            'period_closes' => $periodCloses,
            'history' => [
                'available' => $this->history->getAvailableHistoryRange($stock),
                'benchmark_available' => $this->history->getAvailableHistoryRange($benchmark),
                'stock_fetch' => $stockFetch,
                'benchmark_fetch' => $benchmarkFetch,
            ],
            'chart' => $this->buildComparisonChart($growth, $benchmarkGrowth, $relativeStrength, $periodMonths),
        ];
    }

    /**
     * @param  array<string, float|null>  $growth
     * @param  array<string, float|null>  $benchmarkGrowth
     * @param  array<string, float|null>  $relativeStrength
     * @param  array<int, int>  $periodMonths
     * @return array<int, array<string, mixed>>
     */
    protected function buildComparisonChart(
        array $growth,
        array $benchmarkGrowth,
        array $relativeStrength,
        array $periodMonths,
    ): array {
        $rows = [];
        foreach ($periodMonths as $months) {
            $key = "{$months}m";
            $rows[] = [
                'period' => strtoupper($key),
                'growth_percent' => $growth[$key] ?? 0,
                'benchmark_growth_percent' => $benchmarkGrowth[$key] ?? 0,
                'relative_strength' => $relativeStrength[$key] ?? 0,
            ];
        }

        return $rows;
    }
}
