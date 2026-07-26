<?php

namespace App\Services\PatternDetection;

/**
 * Shared geometry/metrics helpers used by the pattern detectors. Extracted
 * from PatternDetectionService verbatim (TD-004) so detector classes can
 * reuse the same formulas without duplicating logic.
 */
final class CandleMetrics
{
    public const TREND_LOOKBACK = 5;

    public const TREND_THRESHOLD = 0.03;

    public const EPS = 0.03;

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    public static function metrics(array $bars, int $idx): array
    {
        $bar = $bars[$idx];
        $close = $bar['close'];
        $open = $bar['open'];
        $high = $bar['high'];
        $low = $bar['low'];
        $range = $high - $low;
        $body = abs($close - $open);
        $bodySigned = $close - $open;
        $upperWick = $high - max($open, $close);
        $lowerWick = min($open, $close) - $low;

        return [
            'open' => $open,
            'close' => $close,
            'high' => $high,
            'low' => $low,
            'range' => $range,
            'body' => $body,
            'body_signed' => $bodySigned,
            'upper_wick' => $upperWick,
            'lower_wick' => $lowerWick,
            'body_ratio' => $range > 0 ? $body / $range : 0.0,
            'close_position' => $range > 0 ? ($close - $low) / $range : 0.5,
        ];
    }

    /** @param  list<array{close: float}>  $bars */
    public static function priorTrendPct(array $bars, int $endIdx): float
    {
        if ($endIdx < self::TREND_LOOKBACK) {
            return 0.0;
        }
        $start = $bars[$endIdx - self::TREND_LOOKBACK]['close'];
        $end = $bars[$endIdx - 1]['close'];
        if ($start <= 0) {
            return 0.0;
        }

        return ($end - $start) / $start;
    }

    /** @param  list<array{close: float}>  $bars */
    public static function isDowntrend(array $bars, int $endIdx): bool
    {
        return self::priorTrendPct($bars, $endIdx) <= -self::TREND_THRESHOLD;
    }

    /** @param  list<array{close: float}>  $bars */
    public static function isUptrend(array $bars, int $endIdx): bool
    {
        return self::priorTrendPct($bars, $endIdx) >= self::TREND_THRESHOLD;
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    public static function avgBody(array $bars, int $endIdx, int $lookback = 10): float
    {
        $start = max(0, $endIdx - $lookback + 1);
        $sum = 0.0;
        $count = 0;
        for ($i = $start; $i <= $endIdx; $i++) {
            $sum += self::metrics($bars, $i)['body'];
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    public static function hammerShape(array $m): bool
    {
        if ($m['range'] <= 0 || $m['body_ratio'] > 0.35) {
            return false;
        }
        if ($m['body'] > 0 && $m['lower_wick'] < 2 * $m['body']) {
            return false;
        }
        if ($m['body'] > 0 && $m['upper_wick'] > 0.25 * $m['body']) {
            return false;
        }
        if ($m['body'] === 0.0 && $m['lower_wick'] < 0.5 * $m['range']) {
            return false;
        }

        return $m['close_position'] >= 0.60;
    }

    /** @param  list<float>  $values */
    public static function linearSlope(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sumX += $i;
            $sumY += $values[$i];
            $sumXY += $i * $values[$i];
            $sumXX += $i * $i;
        }
        $denom = $n * $sumXX - $sumX * $sumX;
        if ($denom == 0.0) {
            return 0.0;
        }

        return ($n * $sumXY - $sumX * $sumY) / $denom;
    }

    /**
     * @param  list<array{close: float}>  $window
     * @return list<int>
     */
    public static function localPeaks(array $window, int $minSeparation = 2): array
    {
        $peaks = [];
        $count = count($window);
        for ($i = 1; $i < $count - 1; $i++) {
            if ($window[$i]['close'] >= $window[$i - 1]['close']
                && $window[$i]['close'] >= $window[$i + 1]['close']) {
                if ($peaks === [] || $i - $peaks[count($peaks) - 1] >= $minSeparation) {
                    $peaks[] = $i;
                }
            }
        }

        return $peaks;
    }

    /**
     * @param  list<array{close: float}>  $window
     * @return list<int>
     */
    public static function localTroughs(array $window, int $minSeparation = 2): array
    {
        $troughs = [];
        $count = count($window);
        for ($i = 1; $i < $count - 1; $i++) {
            if ($window[$i]['close'] <= $window[$i - 1]['close']
                && $window[$i]['close'] <= $window[$i + 1]['close']) {
                if ($troughs === [] || $i - $troughs[count($troughs) - 1] >= $minSeparation) {
                    $troughs[] = $i;
                }
            }
        }

        return $troughs;
    }
}
