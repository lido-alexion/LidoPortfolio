<?php

namespace App\Services\PatternDetection;

/**
 * Three-bar candlestick patterns (TD-004 split from PatternDetectionService).
 * Logic copied verbatim from the original private detect* methods.
 */
final class CandlestickThreeBarDetector implements PatternDetectorInterface
{
    public function ids(): array
    {
        return [
            'morning_star',
            'evening_star',
            'three_white_soldiers',
            'three_black_crows',
        ];
    }

    public function detect(array $bars, int $endIdx, string $id): bool
    {
        return match ($id) {
            'morning_star' => $this->detectMorningStar($bars, $endIdx),
            'evening_star' => $this->detectEveningStar($bars, $endIdx),
            'three_white_soldiers' => $this->detectThreeWhiteSoldiers($bars, $endIdx),
            'three_black_crows' => $this->detectThreeBlackCrows($bars, $endIdx),
            default => false,
        };
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectMorningStar(array $bars, int $endIdx): bool
    {
        if ($endIdx < 2) {
            return false;
        }
        $avg = CandleMetrics::avgBody($bars, $endIdx - 1);
        $m0 = CandleMetrics::metrics($bars, $endIdx - 2);
        $m1 = CandleMetrics::metrics($bars, $endIdx - 1);
        $m2 = CandleMetrics::metrics($bars, $endIdx);
        $mid = ($m0['open'] + $m0['close']) / 2;

        return $m0['body_signed'] < 0
            && $m0['body'] >= $avg
            && $m1['body_ratio'] <= 0.35
            && $m1['close'] < $m0['close']
            && $m2['body_signed'] > 0
            && $m2['close'] > $mid;
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectEveningStar(array $bars, int $endIdx): bool
    {
        if ($endIdx < 2) {
            return false;
        }
        $avg = CandleMetrics::avgBody($bars, $endIdx - 1);
        $m0 = CandleMetrics::metrics($bars, $endIdx - 2);
        $m1 = CandleMetrics::metrics($bars, $endIdx - 1);
        $m2 = CandleMetrics::metrics($bars, $endIdx);
        $mid = ($m0['open'] + $m0['close']) / 2;

        return $m0['body_signed'] > 0
            && $m0['body'] >= $avg
            && $m1['body_ratio'] <= 0.35
            && $m1['close'] > $m0['close']
            && $m2['body_signed'] < 0
            && $m2['close'] < $mid;
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectThreeWhiteSoldiers(array $bars, int $endIdx): bool
    {
        if ($endIdx < 2) {
            return false;
        }
        $avg = CandleMetrics::avgBody($bars, $endIdx);
        for ($i = $endIdx - 2; $i <= $endIdx; $i++) {
            $m = CandleMetrics::metrics($bars, $i);
            if ($m['body_signed'] <= 0 || $m['body'] < 0.5 * $avg) {
                return false;
            }
        }
        $c0 = $bars[$endIdx - 2]['close'];
        $c1 = $bars[$endIdx - 1]['close'];
        $c2 = $bars[$endIdx]['close'];
        if (! ($c2 > $c1 && $c1 > $c0)) {
            return false;
        }
        $m0 = CandleMetrics::metrics($bars, $endIdx - 2);
        $m1 = CandleMetrics::metrics($bars, $endIdx - 1);
        $m2 = CandleMetrics::metrics($bars, $endIdx);

        return $m1['open'] >= $m0['open'] && $m1['open'] <= $m0['close']
            && $m2['open'] >= $m1['open'] && $m2['open'] <= $m1['close'];
    }

    /** @param  list<array{open: float, high: float, low: float, close: float}>  $bars */
    private function detectThreeBlackCrows(array $bars, int $endIdx): bool
    {
        if ($endIdx < 2) {
            return false;
        }
        $avg = CandleMetrics::avgBody($bars, $endIdx);
        for ($i = $endIdx - 2; $i <= $endIdx; $i++) {
            $m = CandleMetrics::metrics($bars, $i);
            if ($m['body_signed'] >= 0 || $m['body'] < 0.5 * $avg) {
                return false;
            }
        }
        $c0 = $bars[$endIdx - 2]['close'];
        $c1 = $bars[$endIdx - 1]['close'];
        $c2 = $bars[$endIdx]['close'];
        if (! ($c2 < $c1 && $c1 < $c0)) {
            return false;
        }
        $m0 = CandleMetrics::metrics($bars, $endIdx - 2);
        $m1 = CandleMetrics::metrics($bars, $endIdx - 1);
        $m2 = CandleMetrics::metrics($bars, $endIdx);

        return $m1['open'] <= $m0['open'] && $m1['open'] >= $m0['close']
            && $m2['open'] <= $m1['open'] && $m2['open'] >= $m1['close'];
    }
}
