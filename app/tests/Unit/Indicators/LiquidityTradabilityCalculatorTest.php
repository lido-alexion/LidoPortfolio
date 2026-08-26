<?php

namespace Tests\Unit\Indicators;

use App\Services\Indicators\LiquidityTradabilityCalculator;
use PHPUnit\Framework\TestCase;

class LiquidityTradabilityCalculatorTest extends TestCase
{
    private function barsFromCloses(array $closes, ?array $volumes = null, ?array $opens = null): array
    {
        $out = [];
        foreach ($closes as $i => $close) {
            $open = $opens[$i] ?? $close;
            $out[] = [
                'open' => (float) $open,
                'high' => max((float) $open, (float) $close),
                'low' => min((float) $open, (float) $close),
                'close' => (float) $close,
                'volume' => $volumes[$i] ?? 1000.0,
            ];
        }

        return $out;
    }

    public function test_average_turnover_and_relative_turnover(): void
    {
        $calc = new LiquidityTradabilityCalculator;
        $closes = array_fill(0, 80, 10.0);
        $volumes = array_merge(array_fill(0, 60, 100.0), array_fill(0, 20, 400.0));
        $bars = $this->barsFromCloses($closes, $volumes);

        $avg = $calc->averageTurnoverSeries($bars, 20);
        $this->assertNotNull($avg[array_key_last($avg)]);
        $this->assertEqualsWithDelta(4000.0, $avg[array_key_last($avg)], 0.01);

        $rel = $calc->relativeTurnoverSeries($bars, 20, 60);
        $last = $rel[array_key_last($rel)];
        $this->assertNotNull($last);
        $this->assertGreaterThan(1.0, $last);
    }

    public function test_gap_frequency_and_fill_ratio(): void
    {
        $calc = new LiquidityTradabilityCalculator;
        $closes = [];
        $opens = [];
        for ($i = 0; $i < 30; $i++) {
            $closes[] = 100.0;
            // Every 5th bar gaps up 3% then fills within same day via low at prior close.
            if ($i > 0 && $i % 5 === 0) {
                $opens[] = 103.0;
            } else {
                $opens[] = 100.0;
            }
        }
        $bars = [];
        foreach ($closes as $i => $close) {
            $open = $opens[$i];
            $bars[] = [
                'open' => $open,
                'high' => max($open, $close),
                'low' => $i > 0 && $i % 5 === 0 ? 100.0 : min($open, $close),
                'close' => $close,
                'volume' => 1000.0,
            ];
        }

        $freq = $calc->gapFrequencySeries($bars, 20, 1.0);
        $this->assertNotNull($freq[array_key_last($freq)]);
        $this->assertGreaterThan(0.0, $freq[array_key_last($freq)]);

        $fill = $calc->gapFillRatioSeries($bars, 20, 1.0, 5);
        $this->assertNotNull($fill[array_key_last($fill)]);
        $this->assertEqualsWithDelta(1.0, $fill[array_key_last($fill)], 0.01);
    }

    public function test_circuit_heuristic_and_composites(): void
    {
        $calc = new LiquidityTradabilityCalculator;
        $closes = [100.0];
        for ($i = 1; $i < 40; $i++) {
            $closes[] = ($i % 10 === 0) ? $closes[$i - 1] * 1.10 : $closes[$i - 1];
        }
        $bars = [];
        foreach ($closes as $i => $close) {
            $prev = $i > 0 ? $closes[$i - 1] : $close;
            $locked = ($i % 10 === 0);
            $bars[] = [
                'open' => $close,
                'high' => $locked ? $close : max($prev, $close) * 1.01,
                'low' => $locked ? $close : min($prev, $close) * 0.99,
                'close' => $close,
                'volume' => 1000.0,
            ];
        }

        $freq = $calc->circuitFrequencySeries($bars, 30, 9.5, 0.5);
        $risk = $calc->circuitRiskSeries($bars, 30, 9.5, 0.5);
        $this->assertNotNull($freq[array_key_last($freq)]);
        $this->assertGreaterThan(0.0, $freq[array_key_last($freq)]);
        $this->assertNotNull($risk[array_key_last($risk)]);

        $liq = $calc->liquidityScore([
            'relative_turnover' => 1.2,
            'average_turnover' => 1_000_000.0,
            'average_volume' => 50_000.0,
        ]);
        $this->assertNotNull($liq);
        $this->assertGreaterThan(0.0, $liq);
        $this->assertLessThanOrEqual(100.0, $liq);

        $trad = $calc->tradabilityScore([
            'gap_frequency' => 0.1,
            'gap_fill_ratio' => 0.8,
            'circuit_frequency' => 0.05,
            'circuit_risk' => 20.0,
        ]);
        $this->assertNotNull($trad);
        $this->assertGreaterThan(50.0, $trad);
    }

    public function test_composites_null_when_no_parts_and_cap_mapped_components(): void
    {
        $calc = new LiquidityTradabilityCalculator;

        $this->assertNull($calc->liquidityScore([
            'relative_turnover' => null,
            'average_turnover' => null,
            'average_volume' => null,
        ]));
        $this->assertNull($calc->liquidityScore([
            'relative_turnover' => null,
            'average_turnover' => 0.0,
            'average_volume' => 0.0,
        ]));
        $this->assertNull($calc->tradabilityScore([
            'gap_frequency' => null,
            'gap_fill_ratio' => null,
            'circuit_frequency' => null,
            'circuit_risk' => null,
        ]));

        $capped = $calc->liquidityScore(['relative_turnover' => 3.0]);
        $this->assertEqualsWithDelta(100.0, $capped, 1e-9);

        $tradCapped = $calc->tradabilityScore([
            'gap_frequency' => 2.0,
            'circuit_risk' => 150.0,
        ]);
        $this->assertEqualsWithDelta(0.0, $tradCapped, 1e-9);
    }
}
