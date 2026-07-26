<?php

namespace App\Services\PatternDetection;

/**
 * Multi-bar reversal chart patterns based on local peak/trough geometry
 * (TD-004 split from PatternDetectionService). Logic copied verbatim from
 * the original private detect* methods.
 */
final class ChartReversalDetector implements PatternDetectorInterface
{
    public function ids(): array
    {
        return [
            'double_top',
            'double_bottom',
            'head_and_shoulders',
            'inverse_head_and_shoulders',
        ];
    }

    public function detect(array $bars, int $endIdx, string $id): bool
    {
        return match ($id) {
            'double_top' => $this->detectDoubleTop($bars, $endIdx),
            'double_bottom' => $this->detectDoubleBottom($bars, $endIdx),
            'head_and_shoulders' => $this->detectHeadAndShoulders($bars, $endIdx),
            'inverse_head_and_shoulders' => $this->detectInverseHeadAndShoulders($bars, $endIdx),
            default => false,
        };
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectDoubleTop(array $bars, int $endIdx): bool
    {
        if ($endIdx < 9) {
            return false;
        }
        $window = array_slice($bars, max(0, $endIdx - 29), $endIdx - max(0, $endIdx - 29) + 1);
        $peaks = CandleMetrics::localPeaks($window, 3);
        if (count($peaks) < 2) {
            return false;
        }
        $p1 = $peaks[count($peaks) - 2];
        $p2 = $peaks[count($peaks) - 1];
        $h1 = $window[$p1]['close'];
        $h2 = $window[$p2]['close'];
        if ($h1 <= 0 || abs($h1 - $h2) / $h1 > CandleMetrics::EPS) {
            return false;
        }
        $valley = INF;
        for ($i = $p1 + 1; $i < $p2; $i++) {
            $valley = min($valley, $window[$i]['close']);
        }
        if ($valley === INF || ($h1 - $valley) / $h1 < 0.03) {
            return false;
        }

        return $window[count($window) - 1]['close'] <= $valley * 1.01;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectDoubleBottom(array $bars, int $endIdx): bool
    {
        if ($endIdx < 9) {
            return false;
        }
        $window = array_slice($bars, max(0, $endIdx - 29), $endIdx - max(0, $endIdx - 29) + 1);
        $troughs = CandleMetrics::localTroughs($window, 3);
        if (count($troughs) < 2) {
            return false;
        }
        $t1 = $troughs[count($troughs) - 2];
        $t2 = $troughs[count($troughs) - 1];
        $l1 = $window[$t1]['close'];
        $l2 = $window[$t2]['close'];
        if ($l1 <= 0 || abs($l1 - $l2) / $l1 > CandleMetrics::EPS) {
            return false;
        }
        $peak = -INF;
        for ($i = $t1 + 1; $i < $t2; $i++) {
            $peak = max($peak, $window[$i]['close']);
        }
        if ($peak === -INF || ($peak - $l1) / $l1 < 0.03) {
            return false;
        }

        return $window[count($window) - 1]['close'] >= $peak * 0.99;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectHeadAndShoulders(array $bars, int $endIdx): bool
    {
        if ($endIdx < 14) {
            return false;
        }
        $window = array_slice($bars, $endIdx - 14, 15);
        $peaks = CandleMetrics::localPeaks($window, 2);
        if (count($peaks) < 3) {
            return false;
        }
        $slice = array_slice($peaks, -3);
        [$i1, $i2, $i3] = $slice;
        $h = $window[$i2]['close'];
        $s1 = $window[$i1]['close'];
        $s2 = $window[$i3]['close'];
        if (! ($h > $s1 && $h > $s2) || $s1 <= 0 || abs($s1 - $s2) / $s1 > CandleMetrics::EPS) {
            return false;
        }
        $neckline = min(
            min(array_column(array_slice($window, $i1, $i2 - $i1 + 1), 'low')),
            min(array_column(array_slice($window, $i2, $i3 - $i2 + 1), 'low'))
        );

        return $window[count($window) - 1]['close'] <= $neckline;
    }

    /** @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars */
    private function detectInverseHeadAndShoulders(array $bars, int $endIdx): bool
    {
        if ($endIdx < 14) {
            return false;
        }
        $window = array_slice($bars, $endIdx - 14, 15);
        $troughs = CandleMetrics::localTroughs($window, 2);
        if (count($troughs) < 3) {
            return false;
        }
        $slice = array_slice($troughs, -3);
        [$i1, $i2, $i3] = $slice;
        $head = $window[$i2]['close'];
        $s1 = $window[$i1]['close'];
        $s2 = $window[$i3]['close'];
        if (! ($head < $s1 && $head < $s2) || $s1 <= 0 || abs($s1 - $s2) / $s1 > CandleMetrics::EPS) {
            return false;
        }
        $neckline = max(
            max(array_column(array_slice($window, $i1, $i2 - $i1 + 1), 'high')),
            max(array_column(array_slice($window, $i2, $i3 - $i2 + 1), 'high'))
        );

        return $window[count($window) - 1]['close'] >= $neckline;
    }
}
