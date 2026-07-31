<?php

namespace App\Services\Backtest;

/**
 * Safe numeric helpers for backtest persistence (MySQL DECIMAL bounds).
 */
final class BacktestMath
{
    /** DECIMAL(12,6) absolute max (exclusive of sign overflow cushion). */
    public const DECIMAL_12_6_MAX = 999999.999999;

    /** Minimum calendar days before trade-level CAGR is considered meaningful. */
    public const MIN_CAGR_HOLDING_DAYS = 30;

    /**
     * Annualized return % from a price ratio over calendar days.
     * Returns null when the holding period is too short or the value is non-finite / out of column range.
     */
    public static function cagrPercent(float $startValue, float $endValue, int $holdingDays): ?float
    {
        if ($startValue <= 0 || $endValue <= 0 || $holdingDays < self::MIN_CAGR_HOLDING_DAYS) {
            return null;
        }

        $years = $holdingDays / 365.25;
        if ($years <= 0) {
            return null;
        }

        $ratio = $endValue / $startValue;
        if ($ratio <= 0 || ! is_finite($ratio)) {
            return null;
        }

        $raw = (pow($ratio, 1 / $years) - 1) * 100.0;
        if (! is_finite($raw)) {
            return null;
        }

        return self::clampDecimal12_6(round($raw, 6));
    }

    /**
     * Clamp a percentage (or any metric) into DECIMAL(12,6), or null if non-finite.
     */
    public static function clampDecimal12_6(mixed $value): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }
        $f = (float) $value;
        if (! is_finite($f)) {
            return null;
        }
        if ($f > self::DECIMAL_12_6_MAX) {
            return self::DECIMAL_12_6_MAX;
        }
        if ($f < -self::DECIMAL_12_6_MAX) {
            return -self::DECIMAL_12_6_MAX;
        }

        return $f;
    }
}
