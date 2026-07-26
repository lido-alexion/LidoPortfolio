<?php

namespace App\Services\PatternDetection;

/**
 * Single-bar candlestick shapes (TD-004 split from PatternDetectionService).
 * Logic copied verbatim from the original private detect* methods.
 */
final class CandlestickSingleBarDetector implements PatternDetectorInterface
{
    public function ids(): array
    {
        return [
            'doji',
            'hammer',
            'inverted_hammer',
            'hanging_man',
            'shooting_star',
            'bullish_marubozu',
            'bearish_marubozu',
            'spinning_top',
        ];
    }

    public function detect(array $bars, int $endIdx, string $id): bool
    {
        return match ($id) {
            'doji' => $this->detectDoji($bars, $endIdx),
            'hammer' => $this->detectHammer($bars, $endIdx),
            'inverted_hammer' => $this->detectInvertedHammer($bars, $endIdx),
            'hanging_man' => $this->detectHangingMan($bars, $endIdx),
            'shooting_star' => $this->detectShootingStar($bars, $endIdx),
            'bullish_marubozu' => $this->detectBullishMarubozu($bars, $endIdx),
            'bearish_marubozu' => $this->detectBearishMarubozu($bars, $endIdx),
            'spinning_top' => $this->detectSpinningTop($bars, $endIdx),
            default => false,
        };
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectDoji(array $bars, int $endIdx): bool
    {
        $m = CandleMetrics::metrics($bars, $endIdx);

        return $m['range'] > 0 && $m['body_ratio'] <= 0.10;
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectHammer(array $bars, int $endIdx): bool
    {
        return CandleMetrics::hammerShape(CandleMetrics::metrics($bars, $endIdx)) && CandleMetrics::isDowntrend($bars, $endIdx);
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectInvertedHammer(array $bars, int $endIdx): bool
    {
        $m = CandleMetrics::metrics($bars, $endIdx);
        if ($m['range'] <= 0 || $m['body_ratio'] > 0.35) {
            return false;
        }
        if ($m['body'] > 0 && $m['upper_wick'] < 2 * $m['body']) {
            return false;
        }
        if ($m['body'] > 0 && $m['lower_wick'] > 0.25 * $m['body']) {
            return false;
        }

        return $m['close_position'] <= 0.40 && CandleMetrics::isDowntrend($bars, $endIdx);
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectHangingMan(array $bars, int $endIdx): bool
    {
        return CandleMetrics::hammerShape(CandleMetrics::metrics($bars, $endIdx)) && CandleMetrics::isUptrend($bars, $endIdx);
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectShootingStar(array $bars, int $endIdx): bool
    {
        $m = CandleMetrics::metrics($bars, $endIdx);
        if ($m['range'] <= 0 || $m['body_ratio'] > 0.35) {
            return false;
        }
        if ($m['body'] > 0 && $m['upper_wick'] < 2 * $m['body']) {
            return false;
        }
        if ($m['body'] > 0 && $m['lower_wick'] > 0.25 * $m['body']) {
            return false;
        }

        return $m['close_position'] <= 0.35 && CandleMetrics::isUptrend($bars, $endIdx);
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectBullishMarubozu(array $bars, int $endIdx): bool
    {
        $m = CandleMetrics::metrics($bars, $endIdx);

        return $m['body_signed'] > 0
            && $m['body_ratio'] >= 0.90
            && $m['upper_wick'] <= 0.05 * $m['range']
            && $m['lower_wick'] <= 0.05 * $m['range'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectBearishMarubozu(array $bars, int $endIdx): bool
    {
        $m = CandleMetrics::metrics($bars, $endIdx);

        return $m['body_signed'] < 0
            && $m['body_ratio'] >= 0.90
            && $m['upper_wick'] <= 0.05 * $m['range']
            && $m['lower_wick'] <= 0.05 * $m['range'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectSpinningTop(array $bars, int $endIdx): bool
    {
        $m = CandleMetrics::metrics($bars, $endIdx);
        if ($m['range'] <= 0 || $m['body_ratio'] > 0.30) {
            return false;
        }
        if ($m['upper_wick'] < 0.25 * $m['range'] || $m['lower_wick'] < 0.25 * $m['range']) {
            return false;
        }

        return abs($m['upper_wick'] - $m['lower_wick']) <= 0.20 * $m['range'];
    }
}
