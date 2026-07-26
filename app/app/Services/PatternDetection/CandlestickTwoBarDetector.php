<?php

namespace App\Services\PatternDetection;

/**
 * Two-bar candlestick patterns (TD-004 split from PatternDetectionService).
 * Logic copied verbatim from the original private detect* methods.
 */
final class CandlestickTwoBarDetector implements PatternDetectorInterface
{
    public function ids(): array
    {
        return [
            'bullish_engulfing',
            'bearish_engulfing',
            'bullish_harami',
            'bearish_harami',
            'piercing_line',
            'dark_cloud_cover',
        ];
    }

    public function detect(array $bars, int $endIdx, string $id): bool
    {
        return match ($id) {
            'bullish_engulfing' => $this->detectBullishEngulfing($bars, $endIdx),
            'bearish_engulfing' => $this->detectBearishEngulfing($bars, $endIdx),
            'bullish_harami' => $this->detectBullishHarami($bars, $endIdx),
            'bearish_harami' => $this->detectBearishHarami($bars, $endIdx),
            'piercing_line' => $this->detectPiercingLine($bars, $endIdx),
            'dark_cloud_cover' => $this->detectDarkCloudCover($bars, $endIdx),
            default => false,
        };
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectBullishEngulfing(array $bars, int $endIdx): bool
    {
        if ($endIdx < 1) {
            return false;
        }
        $m0 = CandleMetrics::metrics($bars, $endIdx - 1);
        $m1 = CandleMetrics::metrics($bars, $endIdx);
        if ($m0['body_signed'] >= 0 || $m1['body_signed'] <= 0) {
            return false;
        }
        $min0 = min($m0['open'], $m0['close']);
        $max0 = max($m0['open'], $m0['close']);
        $min1 = min($m1['open'], $m1['close']);
        $max1 = max($m1['open'], $m1['close']);

        return $min1 <= $min0 && $max1 >= $max0 && $m1['body'] > $m0['body'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectBearishEngulfing(array $bars, int $endIdx): bool
    {
        if ($endIdx < 1) {
            return false;
        }
        $m0 = CandleMetrics::metrics($bars, $endIdx - 1);
        $m1 = CandleMetrics::metrics($bars, $endIdx);
        if ($m0['body_signed'] <= 0 || $m1['body_signed'] >= 0) {
            return false;
        }
        $min0 = min($m0['open'], $m0['close']);
        $max0 = max($m0['open'], $m0['close']);
        $min1 = min($m1['open'], $m1['close']);
        $max1 = max($m1['open'], $m1['close']);

        return $min1 <= $min0 && $max1 >= $max0 && $m1['body'] > $m0['body'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectBullishHarami(array $bars, int $endIdx): bool
    {
        if ($endIdx < 1) {
            return false;
        }
        $m0 = CandleMetrics::metrics($bars, $endIdx - 1);
        $m1 = CandleMetrics::metrics($bars, $endIdx);
        if ($m0['body_signed'] >= 0 || $m1['body_signed'] <= 0) {
            return false;
        }
        $min0 = min($m0['open'], $m0['close']);
        $max0 = max($m0['open'], $m0['close']);
        $min1 = min($m1['open'], $m1['close']);
        $max1 = max($m1['open'], $m1['close']);

        return $min1 > $min0 && $max1 < $max0 && $m1['body'] < $m0['body'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectBearishHarami(array $bars, int $endIdx): bool
    {
        if ($endIdx < 1) {
            return false;
        }
        $m0 = CandleMetrics::metrics($bars, $endIdx - 1);
        $m1 = CandleMetrics::metrics($bars, $endIdx);
        if ($m0['body_signed'] <= 0 || $m1['body_signed'] >= 0) {
            return false;
        }
        $min0 = min($m0['open'], $m0['close']);
        $max0 = max($m0['open'], $m0['close']);
        $min1 = min($m1['open'], $m1['close']);
        $max1 = max($m1['open'], $m1['close']);

        return $min1 > $min0 && $max1 < $max0 && $m1['body'] < $m0['body'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectPiercingLine(array $bars, int $endIdx): bool
    {
        if ($endIdx < 1 || ! CandleMetrics::isDowntrend($bars, $endIdx)) {
            return false;
        }
        $m0 = CandleMetrics::metrics($bars, $endIdx - 1);
        $m1 = CandleMetrics::metrics($bars, $endIdx);
        if ($m0['body_signed'] >= 0 || $m1['body_signed'] <= 0) {
            return false;
        }
        $mid = ($m0['open'] + $m0['close']) / 2;

        return $m1['close'] > $mid && $m1['close'] < $m0['open'] && $m1['open'] <= $m0['close'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectDarkCloudCover(array $bars, int $endIdx): bool
    {
        if ($endIdx < 1 || ! CandleMetrics::isUptrend($bars, $endIdx)) {
            return false;
        }
        $m0 = CandleMetrics::metrics($bars, $endIdx - 1);
        $m1 = CandleMetrics::metrics($bars, $endIdx);
        if ($m0['body_signed'] <= 0 || $m1['body_signed'] >= 0) {
            return false;
        }
        $mid = ($m0['open'] + $m0['close']) / 2;

        return $m1['close'] < $mid && $m1['close'] > $m0['close'] && $m1['open'] >= $m0['close'];
    }
}
