<?php

namespace App\Services;

use App\Models\Stock;
use Carbon\Carbon;

class IndexPresentationService
{
    public function __construct(
        protected IndexCatalogService $catalog,
        protected MarketPriceService $marketPrices,
        protected StockPriceHistoryService $history,
        protected IndexConstituentService $constituents,
    ) {}

    /**
     * @return array{
     *   primary_symbol: string,
     *   indexes: array<int, array<string, mixed>>
     * }
     */
    public function pageOverview(): array
    {
        $primary = $this->catalog->primarySymbol();
        $indexes = [];

        foreach ($this->catalog->enabledDefinitions() as $def) {
            $stock = $this->catalog->ensureIndexStock($def);
            $summary = $this->marketPrices->summaryForStock($stock);
            $history = $this->marketPrices->historyForStock($stock);

            $indexes[] = [
                'symbol' => $def['symbol'],
                'name' => $def['name'],
                'exchange' => $def['exchange'],
                'tier' => $def['tier'] ?? 'broad',
                'description' => $def['description'],
                'is_primary' => $def['symbol'] === $primary,
                'stock_id' => $stock->id,
                'latest_close' => $summary['latest_close'],
                'latest_price_date' => $summary['latest_price_date'],
                'price_from' => $history['from_date'],
                'price_to' => $history['to_date'],
                'price_count' => $history['price_count'],
                'has_price_history' => $history['has_price_history'],
                'change_percent' => [
                    '1d' => $summary['daily_change_percent'],
                    '15d' => $this->history->getGrowthPercentageForDays($stock, 15),
                    '1m' => $this->history->getGrowthPercentage($stock, 1),
                    '3m' => $this->history->getGrowthPercentage($stock, 3),
                    '6m' => $this->history->getGrowthPercentage($stock, 6),
                    '1y' => $this->history->getGrowthPercentage($stock, 12),
                ],
                'constituents_available' => $this->catalog->supportsConstituents($def),
            ];
        }

        return [
            'primary_symbol' => $primary,
            'indexes' => $indexes,
        ];
    }

    /**
     * @return array{
     *   months: int,
     *   baseline_date: ?string,
     *   series: array<int, array{symbol: string, name: string, exchange: string, points: array<int, array{date: string, gain_percent: float}>}>
     * }
     */
    public function comparison(int $months = 12): array
    {
        $months = max(1, min(12, $months));
        $asOf = now()->copy()->startOfDay();
        $startTarget = $asOf->copy()->subMonths($months);
        $baselineDate = null;
        $series = [];

        foreach ($this->catalog->enabledDefinitions() as $def) {
            if (($def['tier'] ?? 'broad') === 'volatility') {
                continue;
            }
            $stock = $this->catalog->ensureIndexStock($def);
            $points = $this->history->getNormalizedGainSeriesForStock($stock, $months, $asOf);
            if ($points === []) {
                continue;
            }

            if ($baselineDate === null) {
                $baselineDate = $this->history->getCloseOnOrBeforeDate($stock, $startTarget) !== null
                    ? $startTarget->toDateString()
                    : ($points[0]['date'] ?? null);
            }

            $series[] = [
                'symbol' => $def['symbol'],
                'name' => $def['name'],
                'exchange' => $def['exchange'],
                'tier' => $def['tier'] ?? 'broad',
                'points' => $points,
            ];
        }

        return [
            'months' => $months,
            'baseline_date' => $baselineDate,
            'series' => $series,
        ];
    }

    /**
     * @return array{
     *   symbol: string,
     *   name: string,
     *   available: bool,
     *   constituents: array<int, array{symbol: string, name: string|null, stock_id: int|null}>,
     *   message: string|null
     * }
     */
    public function constituents(string $symbol): array
    {
        $def = $this->catalog->definitionForSymbol($symbol);
        if ($def === null || ! ($def['enabled'] ?? true)) {
            abort(404, 'Index not found.');
        }

        $available = $this->catalog->supportsConstituents($def);
        if (! $available) {
            return [
                'symbol' => $def['symbol'],
                'name' => $def['name'],
                'available' => false,
                'constituents' => [],
                'message' => 'Constituent list is not available for this index.',
            ];
        }

        $constituents = $this->constituents->constituentsForSymbol($def['symbol']);

        return [
            'symbol' => $def['symbol'],
            'name' => $def['name'],
            'available' => true,
            'constituents' => $constituents,
            'message' => $constituents === []
                ? 'Could not load constituents from NSE right now. Cached list is empty — try again later, or run index constituent refresh after market hours.'
                : null,
        ];
    }
}