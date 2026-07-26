<?php

namespace App\Services\PatternDetection;

/**
 * Multi-bar continuation/consolidation chart patterns based on linear
 * slope and range geometry (TD-004 split from PatternDetectionService).
 * Logic copied verbatim from the original private detect* methods.
 */
final class ChartContinuationDetector implements PatternDetectorInterface
{
    public function ids(): array
    {
        return [
            'ascending_triangle',
            'descending_triangle',
            'bull_flag',
            'bear_flag',
        ];
    }

    public function detect(array $bars, int $endIdx, string $id): bool
    {
        return match ($id) {
            'ascending_triangle' => $this->detectAscendingTriangle($bars, $endIdx),
            'descending_triangle' => $this->detectDescendingTriangle($bars, $endIdx),
            'bull_flag' => $this->detectBullFlag($bars, $endIdx),
            'bear_flag' => $this->detectBearFlag($bars, $endIdx),
            default => false,
        };
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectAscendingTriangle(array $bars, int $endIdx): bool
    {
        if ($endIdx < 14) {
            return false;
        }
        $window = array_slice($bars, $endIdx - 14, 15);
        $highs = array_map(fn ($b) => $b['high'], $window);
        $lows = array_map(fn ($b) => $b['low'], $window);
        $highSpread = max($highs) - min($highs);
        $avgHigh = array_sum($highs) / count($highs);
        if ($avgHigh <= 0 || $highSpread / $avgHigh > 0.02) {
            return false;
        }
        if (CandleMetrics::linearSlope($lows) <= 0) {
            return false;
        }
        $widthStart = $highs[0] - $lows[0];
        $widthEnd = $highs[count($highs) - 1] - $lows[count($lows) - 1];
        if ($widthEnd >= $widthStart) {
            return false;
        }

        return $window[count($window) - 1]['close'] > max($highs) * 0.995;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectDescendingTriangle(array $bars, int $endIdx): bool
    {
        if ($endIdx < 14) {
            return false;
        }
        $window = array_slice($bars, $endIdx - 14, 15);
        $highs = array_map(fn ($b) => $b['high'], $window);
        $lows = array_map(fn ($b) => $b['low'], $window);
        $lowSpread = max($lows) - min($lows);
        $avgLow = array_sum($lows) / count($lows);
        if ($avgLow <= 0 || $lowSpread / $avgLow > 0.02) {
            return false;
        }
        if (CandleMetrics::linearSlope($highs) >= 0) {
            return false;
        }
        $widthStart = $highs[0] - $lows[0];
        $widthEnd = $highs[count($highs) - 1] - $lows[count($lows) - 1];
        if ($widthEnd >= $widthStart) {
            return false;
        }

        return $window[count($window) - 1]['close'] < min($lows) * 1.005;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectBullFlag(array $bars, int $endIdx): bool
    {
        if ($endIdx < 11) {
            return false;
        }
        $window = array_slice($bars, $endIdx - 11, 12);
        $poleStart = $window[0]['close'];
        $poleEnd = $window[3]['close'];
        if ($poleStart <= 0 || ($poleEnd - $poleStart) / $poleStart < 0.06) {
            return false;
        }
        $flag = array_slice($window, 4);
        $flagSlope = CandleMetrics::linearSlope(array_map(fn ($b) => $b['close'], $flag));
        if ($flagSlope >= 0) {
            return false;
        }
        $flagHigh = max(array_map(fn ($b) => $b['high'], $flag));

        return $window[count($window) - 1]['close'] > $flagHigh;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectBearFlag(array $bars, int $endIdx): bool
    {
        if ($endIdx < 11) {
            return false;
        }
        $window = array_slice($bars, $endIdx - 11, 12);
        $poleStart = $window[0]['close'];
        $poleEnd = $window[3]['close'];
        if ($poleStart <= 0 || ($poleEnd - $poleStart) / $poleStart < -0.06) {
            return false;
        }
        $flag = array_slice($window, 4);
        $flagSlope = CandleMetrics::linearSlope(array_map(fn ($b) => $b['close'], $flag));
        if ($flagSlope <= 0) {
            return false;
        }
        $flagLow = min(array_map(fn ($b) => $b['low'], $flag));

        return $window[count($window) - 1]['close'] < $flagLow;
    }
}
