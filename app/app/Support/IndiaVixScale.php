<?php

namespace App\Support;

/**
 * NSE charting occasionally returns India VIX OHLC scaled ×100 (e.g. 1264.5 instead of 12.645).
 * Real India VIX has never approached 100 even in crisis peaks (~80–90), so values ≥ 100 are
 * treated as mis-scaled hundredths and divided back down.
 */
class IndiaVixScale
{
    public const SYMBOL = 'INDIAVIX';

    /** Above this, a close is treated as ×100 mis-scaled (legitimate VIX stays below). */
    public const MAX_SANE_CLOSE = 100.0;

    /** Reject absurd values that are not a simple ×100 glitch (e.g. wrong index). */
    public const MAX_RESCALE_CLOSE = 10000.0;

    public const SCALE_FACTOR = 100.0;

    public static function isIndiaVixSymbol(?string $symbol): bool
    {
        return strtoupper(trim((string) $symbol)) === self::SYMBOL;
    }

    public static function needsRescale(float $close): bool
    {
        return $close >= self::MAX_SANE_CLOSE && $close < self::MAX_RESCALE_CLOSE;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function normalizeRow(array $row): array
    {
        $close = (float) ($row['close_price'] ?? $row['close'] ?? 0);
        if (! self::needsRescale($close)) {
            return $row;
        }

        foreach (['open_price', 'high_price', 'low_price', 'close_price', 'adjusted_close_price', 'open', 'high', 'low', 'close'] as $key) {
            if (! array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
                continue;
            }
            if (! is_numeric($row[$key])) {
                continue;
            }
            $row[$key] = round(((float) $row[$key]) / self::SCALE_FACTOR, 4);
        }

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeRows(array $rows): array
    {
        return array_map(fn (array $row) => self::normalizeRow($row), $rows);
    }

    public static function normalizeClose(float $close): float
    {
        return self::needsRescale($close)
            ? round($close / self::SCALE_FACTOR, 4)
            : $close;
    }
}
