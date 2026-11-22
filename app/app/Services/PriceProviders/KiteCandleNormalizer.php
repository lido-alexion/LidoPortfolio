<?php

namespace App\Services\PriceProviders;

use Carbon\Carbon;

/** Maps Kite's raw, unadjusted daily candles into StoX's OHLCV row shape. */
final class KiteCandleNormalizer
{
    /** @return array{price_date:string,open_price:?float,high_price:?float,low_price:?float,close_price:float,volume:?int}|null */
    public function normalize(array $candle): ?array
    {
        if (count($candle) < 6 || (! is_string($candle[0] ?? null) && ! is_numeric($candle[0] ?? null))
            || trim((string) ($candle[0] ?? '')) === '') {
            return null;
        }

        foreach ([1, 2, 3, 4] as $index) {
            if (! is_numeric($candle[$index] ?? null) || ! is_finite((float) $candle[$index])) {
                return null;
            }
        }

        $open = (float) $candle[1];
        $high = (float) $candle[2];
        $low = (float) $candle[3];
        $close = (float) $candle[4];
        if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0 || $high < max($open, $close, $low) || $low > min($open, $close, $high)) {
            return null;
        }

        $volume = isset($candle[5]) && is_numeric($candle[5]) && is_finite((float) $candle[5]) && (float) $candle[5] >= 0
            ? (int) $candle[5]
            : null;

        try {
            $date = Carbon::parse((string) $candle[0], 'Asia/Kolkata')->timezone('Asia/Kolkata')->toDateString();
        } catch (\Throwable) {
            return null;
        }

        return [
            'price_date' => $date,
            'open_price' => $open,
            'high_price' => $high,
            'low_price' => $low,
            'close_price' => $close,
            'volume' => $volume,
        ];
    }
}
