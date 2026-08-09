<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockPrice;

class CorporateActionPriceAdjustmentService
{
    /**
     * @return array{price_divisor: float, volume_multiplier: float}
     */
    public function factorsForAction(string $actionType, int $ratioFrom, int $ratioTo): array
    {
        $factor = $this->ratioFactor($ratioFrom, $ratioTo);

        if ($actionType === 'split') {
            return [
                'price_divisor' => $factor,
                'volume_multiplier' => $factor,
            ];
        }

        $divisor = 1 + $factor;

        return [
            'price_divisor' => $divisor,
            'volume_multiplier' => $divisor,
        ];
    }

    /**
     * @return array{
     *   rows_to_adjust: int,
     *   price_divisor: float,
     *   volume_multiplier: float,
     *   adjusted_before_date: string
     * }
     */
    public function previewAdjustment(
        Stock $stock,
        string $exDate,
        string $actionType,
        int $ratioFrom,
        int $ratioTo,
    ): array {
        $factors = $this->factorsForAction($actionType, $ratioFrom, $ratioTo);

        return $this->previewAdjustmentByDivisors(
            $stock,
            $exDate,
            $factors['price_divisor'],
            $factors['volume_multiplier'],
        );
    }

    /**
     * Preview using authoritative stored divisors (F042 PriceAdjustmentFactor path).
     *
     * @return array{
     *   rows_to_adjust: int,
     *   price_divisor: float,
     *   volume_multiplier: float,
     *   adjusted_before_date: string
     * }
     */
    public function previewAdjustmentByDivisors(
        Stock $stock,
        string $exDate,
        float $priceDivisor,
        float $volumeMultiplier,
    ): array {
        return [
            'rows_to_adjust' => $this->rowsQuery($stock, $exDate)->count(),
            'price_divisor' => $priceDivisor,
            'volume_multiplier' => $volumeMultiplier,
            'adjusted_before_date' => $exDate,
        ];
    }

    /**
     * Restate OHLCV rows strictly before ex-date so charts stay continuous after ledger changes.
     *
     * @return array{
     *   rows_adjusted: int,
     *   price_divisor: float,
     *   volume_multiplier: float,
     *   adjusted_before_date: string
     * }
     */
    public function adjustHistoricalPrices(
        Stock $stock,
        string $exDate,
        string $actionType,
        int $ratioFrom,
        int $ratioTo,
    ): array {
        $factors = $this->factorsForAction($actionType, $ratioFrom, $ratioTo);

        return $this->adjustHistoricalPricesByDivisors(
            $stock,
            $exDate,
            $factors['price_divisor'],
            $factors['volume_multiplier'],
        );
    }

    /**
     * Restate OHLCV using stored F042 divisors (source of truth for factor-driven repair).
     *
     * @return array{
     *   rows_adjusted: int,
     *   price_divisor: float,
     *   volume_multiplier: float,
     *   adjusted_before_date: string
     * }
     */
    public function adjustHistoricalPricesByDivisors(
        Stock $stock,
        string $exDate,
        float $priceDivisor,
        float $volumeMultiplier,
    ): array {
        $rows = $this->rowsQuery($stock, $exDate)->get();
        $updated = 0;

        foreach ($rows as $row) {
            $updates = $this->buildAdjustedRow($row, $priceDivisor, $volumeMultiplier);
            if ($updates === []) {
                continue;
            }
            $row->update($updates);
            $updated++;
        }

        return [
            'rows_adjusted' => $updated,
            'price_divisor' => $priceDivisor,
            'volume_multiplier' => $volumeMultiplier,
            'adjusted_before_date' => $exDate,
        ];
    }

    /**
     * Representative before/after samples for preview (non-mutating).
     *
     * @return array<int, array<string, mixed>>
     */
    public function sampleAdjustmentPreview(
        Stock $stock,
        string $exDate,
        float $priceDivisor,
        float $volumeMultiplier,
        int $limit = 3,
    ): array {
        if ($priceDivisor <= 0 || $limit < 1) {
            return [];
        }

        return $this->rowsQuery($stock, $exDate)
            ->orderByDesc('price_date')
            ->limit($limit)
            ->get()
            ->map(function (StockPrice $row) use ($priceDivisor, $volumeMultiplier) {
                $proposed = $this->buildAdjustedRow($row, $priceDivisor, $volumeMultiplier);

                return [
                    'price_date' => $row->price_date?->toDateString() ?? (string) $row->price_date,
                    'before' => [
                        'open_price' => $row->open_price,
                        'high_price' => $row->high_price,
                        'low_price' => $row->low_price,
                        'close_price' => $row->close_price,
                        'adjusted_close_price' => $row->adjusted_close_price,
                        'volume' => $row->volume,
                    ],
                    'after' => [
                        'open_price' => $proposed['open_price'] ?? $row->open_price,
                        'high_price' => $proposed['high_price'] ?? $row->high_price,
                        'low_price' => $proposed['low_price'] ?? $row->low_price,
                        'close_price' => $proposed['close_price'] ?? $row->close_price,
                        'adjusted_close_price' => $proposed['adjusted_close_price'] ?? $row->adjusted_close_price,
                        'volume' => $proposed['volume'] ?? $row->volume,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<StockPrice>
     */
    protected function rowsQuery(Stock $stock, string $exDate)
    {
        return StockPrice::query()
            ->where('stock_id', $stock->id)
            ->where('price_date', '<', $exDate);
    }

    /**
     * @return array<string, float|int>
     */
    protected function buildAdjustedRow(StockPrice $row, float $priceDivisor, float $volumeMultiplier): array
    {
        if ($priceDivisor <= 0) {
            return [];
        }

        $updates = [];

        foreach (['open_price', 'high_price', 'low_price', 'close_price', 'adjusted_close_price'] as $field) {
            $value = $row->{$field};
            if ($value === null) {
                continue;
            }
            $updates[$field] = round((float) $value / $priceDivisor, 4);
        }

        if ($row->volume !== null) {
            $updates['volume'] = (int) round((float) $row->volume * $volumeMultiplier);
        }

        return $updates;
    }

    protected function ratioFactor(int $ratioFrom, int $ratioTo): float
    {
        return $ratioTo / $ratioFrom;
    }
}
