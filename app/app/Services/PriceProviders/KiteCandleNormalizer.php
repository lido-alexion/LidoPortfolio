<?php

namespace App\Services\PriceProviders;

use Carbon\Carbon;
use DateTimeImmutable;

/** Maps Kite's raw, unadjusted daily candles into StoX's OHLCV row shape. */
final class KiteCandleNormalizer
{
    /** @return array{price_date:string,open_price:?float,high_price:?float,low_price:?float,close_price:float,volume:?int}|null */
    public function normalize(array $candle): ?array
    {
        $timestamp = $candle[0] ?? null;
        if (count($candle) < 6 || ! is_string($timestamp)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:?\d{2})$/', trim($timestamp))) {
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

        if (! is_numeric($candle[5] ?? null) || ! is_finite((float) $candle[5])
            || (float) $candle[5] < 0 || floor((float) $candle[5]) !== (float) $candle[5]
            || (float) $candle[5] > PHP_INT_MAX) {
            return null;
        }

        try {
            $date = Carbon::instance(new DateTimeImmutable($timestamp))->timezone('Asia/Kolkata');
        } catch (\Throwable) {
            return null;
        }
        if ($date->isWeekend()) {
            return null;
        }

        return [
            'price_date' => $date->toDateString(),
            'open_price' => $open,
            'high_price' => $high,
            'low_price' => $low,
            'close_price' => $close,
            'volume' => (int) $candle[5],
        ];
    }
}
