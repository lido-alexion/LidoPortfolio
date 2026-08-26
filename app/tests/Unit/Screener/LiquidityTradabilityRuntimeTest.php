<?php

namespace Tests\Unit\Screener;

use App\Services\Indicators\LiquidityTradabilityCalculator;
use App\Services\Screener\ScreenerCatalog;
use App\Services\Screener\ScreenerEvaluationService;
use App\Services\Screener\TechnicalIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * V4-FEAT-006: composite liquidity/tradability scores must be reachable through
 * TechnicalIndicatorService and must reuse LiquidityTradabilityCalculator.
 */
class LiquidityTradabilityRuntimeTest extends TestCase
{
    /**
     * @return list<array{open:float,high:float,low:float,close:float,volume:float}>
     */
    private function liquidBars(): array
    {
        $closes = array_fill(0, 80, 10.0);
        $volumes = array_merge(array_fill(0, 60, 100.0), array_fill(0, 20, 400.0));
        $out = [];
        foreach ($closes as $i => $close) {
            $out[] = [
                'open' => (float) $close,
                'high' => (float) $close,
                'low' => (float) $close,
                'close' => (float) $close,
                'volume' => $volumes[$i],
            ];
        }

        return $out;
    }

    /**
     * Mix of quiet sessions and opening gaps so tradability primaries are defined.
     *
     * @return list<array{open:float,high:float,low:float,close:float,volume:float}>
     */
    private function tradableBars(): array
    {
        $bars = [];
        for ($i = 0; $i < 80; $i++) {
            $close = 100.0;
            $gaps = $i > 0 && $i % 5 === 0;
            $open = $gaps ? 103.0 : 100.0;
            $bars[] = [
                'open' => $open,
                'high' => max($open, $close),
                'low' => $gaps ? 100.0 : min($open, $close),
                'close' => $close,
                'volume' => 1000.0,
            ];
        }

        return $bars;
    }

    public function test_liquidity_score_through_tis_matches_calculator(): void
    {
        $bars = $this->liquidBars();
        $engine = (new TechnicalIndicatorService)->withBars($bars);
        $calc = new LiquidityTradabilityCalculator;

        $rel = $engine->evaluate(['indicator' => 'relative_turnover']);
        $to = $engine->evaluate(['indicator' => 'average_turnover']);
        $vol = $engine->evaluate(['indicator' => 'average_volume']);
        $this->assertNotNull($rel);
        $this->assertNotNull($to);
        $this->assertNotNull($vol);

        $expected = $calc->liquidityScore([
            'relative_turnover' => $rel,
            'average_turnover' => $to,
            'average_volume' => $vol,
        ]);
        $actual = $engine->evaluate(['indicator' => 'liquidity_score']);

        $this->assertNotNull($expected);
        $this->assertNotNull($actual);
        $this->assertEqualsWithDelta($expected, $actual, 1e-9);
        $this->assertGreaterThanOrEqual(0.0, $actual);
        $this->assertLessThanOrEqual(100.0, $actual);
    }

    public function test_tradability_score_through_tis_matches_calculator(): void
    {
        $bars = $this->tradableBars();
        $engine = (new TechnicalIndicatorService)->withBars($bars);
        $calc = new LiquidityTradabilityCalculator;

        $parts = [
            'gap_frequency' => $engine->evaluate(['indicator' => 'gap_frequency']),
            'gap_fill_ratio' => $engine->evaluate(['indicator' => 'gap_fill_ratio']),
            'circuit_frequency' => $engine->evaluate(['indicator' => 'circuit_frequency']),
            'circuit_risk' => $engine->evaluate(['indicator' => 'circuit_risk']),
        ];
        $this->assertNotNull($parts['gap_frequency']);
        $this->assertNotNull($parts['gap_fill_ratio']);

        $expected = $calc->tradabilityScore($parts);
        $actual = $engine->evaluate(['indicator' => 'tradability_score']);

        $this->assertNotNull($expected);
        $this->assertNotNull($actual);
        $this->assertEqualsWithDelta($expected, $actual, 1e-9);
        $this->assertGreaterThanOrEqual(0.0, $actual);
        $this->assertLessThanOrEqual(100.0, $actual);
    }

    public function test_composite_series_last_bar_uses_same_bar_primaries(): void
    {
        $bars = $this->liquidBars();
        $engine = (new TechnicalIndicatorService)->withBars($bars);
        $calc = new LiquidityTradabilityCalculator;
        $last = count($bars) - 1;

        $relSeries = $engine->evaluateSeries(['indicator' => 'relative_turnover']);
        $toSeries = $engine->evaluateSeries(['indicator' => 'average_turnover']);
        $volSeries = $engine->evaluateSeries(['indicator' => 'average_volume']);
        $liqSeries = $engine->evaluateSeries(['indicator' => 'liquidity_score']);

        $this->assertCount(count($bars), $liqSeries);
        $expected = $calc->liquidityScore([
            'relative_turnover' => $relSeries[$last],
            'average_turnover' => $toSeries[$last],
            'average_volume' => $volSeries[$last],
        ]);
        $this->assertEqualsWithDelta($expected, $liqSeries[$last], 1e-9);
        $this->assertEqualsWithDelta(
            $engine->evaluate(['indicator' => 'liquidity_score']),
            $liqSeries[$last],
            1e-9,
        );
    }

    public function test_primaries_through_tis_match_calculator_series(): void
    {
        $bars = $this->liquidBars();
        $engine = (new TechnicalIndicatorService)->withBars($bars);
        $calc = new LiquidityTradabilityCalculator;

        $this->assertEqualsWithDelta(
            $calc->averageTurnoverSeries($bars, 20)[79],
            $engine->evaluate(['indicator' => 'average_turnover']),
            1e-9,
        );
        $this->assertEqualsWithDelta(
            $calc->relativeTurnoverSeries($bars, 20, 60)[79],
            $engine->evaluate(['indicator' => 'relative_turnover']),
            1e-9,
        );

        $tradable = $this->tradableBars();
        $tradEngine = (new TechnicalIndicatorService)->withBars($tradable);
        $this->assertEqualsWithDelta(
            $calc->gapFrequencySeries($tradable, 60, 1.0)[79],
            $tradEngine->evaluate(['indicator' => 'gap_frequency']),
            1e-9,
        );
    }

    public function test_insufficient_bars_yield_null_composites(): void
    {
        $bars = [
            ['open' => 10.0, 'high' => 11.0, 'low' => 9.0, 'close' => 10.0, 'volume' => 100.0],
        ];
        $engine = (new TechnicalIndicatorService)->withBars($bars);

        $this->assertNull($engine->evaluate(['indicator' => 'liquidity_score']));
        $this->assertNull($engine->evaluate(['indicator' => 'tradability_score']));
        $this->assertNull($engine->evaluate(['indicator' => 'relative_turnover']));
        $this->assertNull($engine->evaluate(['indicator' => 'gap_frequency']));
    }

    public function test_empty_bars_yield_null_composites(): void
    {
        $engine = (new TechnicalIndicatorService)->withBars([]);

        $this->assertNull($engine->evaluate(['indicator' => 'liquidity_score']));
        $this->assertNull($engine->evaluate(['indicator' => 'tradability_score']));
    }

    public function test_partial_liquidity_inputs_mean_of_available_components(): void
    {
        // 25 bars: ADV/turnover defined (period 20); relative_turnover needs baseline 60.
        $bars = [];
        for ($i = 0; $i < 25; $i++) {
            $bars[] = [
                'open' => 10.0,
                'high' => 10.0,
                'low' => 10.0,
                'close' => 10.0,
                'volume' => 100.0,
            ];
        }
        $engine = (new TechnicalIndicatorService)->withBars($bars);
        $calc = new LiquidityTradabilityCalculator;

        $this->assertNull($engine->evaluate(['indicator' => 'relative_turnover']));
        $to = $engine->evaluate(['indicator' => 'average_turnover']);
        $vol = $engine->evaluate(['indicator' => 'average_volume']);
        $this->assertNotNull($to);
        $this->assertNotNull($vol);

        $expected = $calc->liquidityScore([
            'relative_turnover' => null,
            'average_turnover' => $to,
            'average_volume' => $vol,
        ]);
        $this->assertNotNull($expected);
        $this->assertEqualsWithDelta($expected, $engine->evaluate(['indicator' => 'liquidity_score']), 1e-9);
    }

    public function test_unrelated_sma_unchanged_on_same_bars(): void
    {
        $bars = $this->liquidBars();
        $engine = (new TechnicalIndicatorService)->withBars($bars);
        $closes = array_column($bars, 'close');
        $sma = (new TechnicalIndicatorService)->sma($closes, 20);
        $this->assertEqualsWithDelta($sma[79], $engine->evaluate(['indicator' => 'sma', 'params' => ['period' => 20]]), 1e-9);
    }

    public function test_screener_runtime_can_evaluate_composites_without_catalog_ids(): void
    {
        $this->assertNotContains('liquidity_score', ScreenerCatalog::indicatorIds());
        $this->assertNotContains('tradability_score', ScreenerCatalog::indicatorIds());
        $this->assertSame(60, ScreenerCatalog::minBars('liquidity_score', []));
        $this->assertSame(60, ScreenerCatalog::minBars('tradability_score', []));

        $eval = new ScreenerEvaluationService(new TechnicalIndicatorService);
        $result = $eval->evaluateStock([
            'root' => [
                'type' => 'condition',
                'left' => ['indicator' => 'liquidity_score'],
                'operator' => 'gt',
                'right' => ['type' => 'constant', 'value' => 0],
            ],
        ], $this->liquidBars());

        $this->assertFalse($result['skipped']);
        $this->assertTrue($result['matched']);
        $this->assertNotNull($result['metrics'][0]['left_value'] ?? null);
    }
}
