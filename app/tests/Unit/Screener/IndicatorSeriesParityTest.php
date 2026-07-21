<?php

namespace Tests\Unit\Screener;

use App\Services\Screener\ScreenerCatalog;
use App\Services\Screener\ScreenerEvaluationService;
use App\Services\Screener\TechnicalIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * The stock-major backtest reads full indicator series instead of re-evaluating
 * bar prefixes per day. These tests pin the contract that evaluateSeries() is
 * causal and consistent with the single-value evaluate() path.
 */
class IndicatorSeriesParityTest extends TestCase
{
    /**
     * Deterministic pseudo-random OHLCV walk.
     *
     * @return list<array{open:float,high:float,low:float,close:float,volume:float}>
     */
    private function makeBars(int $count = 300): array
    {
        mt_srand(424242);
        $bars = [];
        $close = 100.0;
        for ($i = 0; $i < $count; $i++) {
            $close = max(5.0, $close + (mt_rand(-300, 320) / 100.0));
            $spread = mt_rand(10, 250) / 100.0;
            $open = $close + (mt_rand(-100, 100) / 100.0);
            $bars[] = [
                'open' => round($open, 2),
                'high' => round(max($open, $close) + $spread, 2),
                'low' => round(min($open, $close) - $spread, 2),
                'close' => round($close, 2),
                'volume' => (float) mt_rand(1_000, 90_000),
            ];
        }

        return $bars;
    }

    public function test_series_last_value_matches_single_evaluate_for_every_indicator(): void
    {
        $bars = $this->makeBars(300);
        $engine = (new TechnicalIndicatorService)->withBars($bars);
        $last = count($bars) - 1;

        foreach (ScreenerCatalog::indicatorIds() as $id) {
            $expr = ['indicator' => $id];
            $single = $engine->evaluate($expr);
            $series = $engine->evaluateSeries($expr);

            $this->assertCount(count($bars), $series, "series length for {$id}");
            $this->assertNotNull($single, "single evaluate for {$id} should produce a value on full data");
            $this->assertNotNull($series[$last], "series last value for {$id} should produce a value on full data");
            $this->assertEqualsWithDelta($single, $series[$last], 1e-6, "series[last] vs evaluate() for {$id}");
        }
    }

    public function test_series_is_causal_prefix_values_match_prefix_evaluation(): void
    {
        $bars = $this->makeBars(300);
        $svc = new TechnicalIndicatorService;
        $fullEngine = $svc->withBars($bars);

        // Seeded/recursive indicators are the risky ones: their value depends on
        // the whole history start, so causality must hold exactly, not just for
        // pure window functions.
        $exprs = [
            ['indicator' => 'ema', 'params' => ['period' => 20]],
            ['indicator' => 'rsi', 'params' => ['period' => 14]],
            ['indicator' => 'atr', 'params' => ['period' => 14]],
            ['indicator' => 'macd_hist'],
            ['indicator' => 'stoch_d'],
            ['indicator' => 'high_52w'],
            ['indicator' => 'high_n', 'params' => ['period' => 20]],
            ['indicator' => 'change_pct', 'params' => ['period' => 5]],
            ['indicator' => 'bb_pct_b'],
            ['indicator' => 'volume_ratio'],
            ['indicator' => 'price_vs_sma_pct'],
            ['indicator' => 'ema_spread_pct'],
        ];

        foreach ([60, 140, 270] as $prefixEnd) {
            $prefixEngine = $svc->withBars(array_slice($bars, 0, $prefixEnd + 1));
            foreach ($exprs as $expr) {
                $fromSeries = $fullEngine->evaluateSeries($expr)[$prefixEnd];
                $fromPrefix = $prefixEngine->evaluate($expr);
                $this->assertNotNull($fromPrefix, "prefix evaluate for {$expr['indicator']} at {$prefixEnd}");
                $this->assertEqualsWithDelta(
                    $fromPrefix,
                    $fromSeries,
                    1e-6,
                    "series[{$prefixEnd}] must equal evaluate() on bars[0..{$prefixEnd}] for {$expr['indicator']}",
                );
            }
        }
    }

    public function test_evaluate_across_dates_matches_per_day_evaluation_and_skips_short_history(): void
    {
        $svc = new ScreenerEvaluationService(new TechnicalIndicatorService);

        // 30 sessions, close rises 100 → 129. Condition: sma(5) > 110.
        $bars = [];
        $dates = [];
        $day = new \DateTimeImmutable('2026-06-01');
        for ($i = 0; $i < 30; $i++) {
            while ((int) $day->format('N') >= 6) {
                $day = $day->modify('+1 day');
            }
            $c = 100.0 + $i;
            $date = $day->format('Y-m-d');
            $dates[] = $date;
            $bars[] = [
                'date' => $date,
                'open' => $c,
                'high' => $c + 1,
                'low' => $c - 1,
                'close' => $c,
                'volume' => 1000.0,
            ];
            $day = $day->modify('+1 day');
        }

        $definition = [
            'root' => [
                'type' => 'condition',
                'left' => ['indicator' => 'sma', 'params' => ['period' => 5]],
                'operator' => 'gt',
                'weight_factor' => 1,
                'right' => ['type' => 'constant', 'value' => 110],
            ],
        ];

        $results = $svc->evaluateAcrossDates($definition, $bars, $dates);

        $this->assertCount(30, $results);
        foreach ($dates as $i => $date) {
            $expected = $svc->evaluateStock($definition, array_slice($bars, 0, $i + 1));
            $this->assertSame($expected['skipped'], $results[$date]['skipped'], "skipped mismatch on {$date}");
            $this->assertSame($expected['matched'], $results[$date]['matched'], "matched mismatch on {$date}");
        }

        // First 4 sessions lack sma(5) history → skipped; the flip to matched
        // happens once sma(5) crosses 110 (closes 108..112 → sma 110, then >110).
        $this->assertTrue($results[$dates[0]]['skipped']);
        $this->assertTrue($results[$dates[3]]['skipped']);
        $this->assertFalse($results[$dates[4]]['skipped']);
        $this->assertFalse($results[$dates[4]]['matched']);
        $this->assertFalse($results[$dates[12]]['matched']); // sma = 110, not > 110
        $this->assertTrue($results[$dates[13]]['matched']);
        $this->assertTrue($results[$dates[29]]['matched']);
    }

    public function test_evaluate_across_dates_entity_alignment_uses_last_entity_bar_on_or_before_date(): void
    {
        $svc = new ScreenerEvaluationService(new TechnicalIndicatorService);

        $mkBars = function (array $closes, string $startDate): array {
            $bars = [];
            $day = new \DateTimeImmutable($startDate);
            foreach ($closes as $c) {
                while ((int) $day->format('N') >= 6) {
                    $day = $day->modify('+1 day');
                }
                $bars[] = [
                    'date' => $day->format('Y-m-d'),
                    'open' => (float) $c,
                    'high' => $c + 2.0,
                    'low' => $c - 2.0,
                    'close' => (float) $c,
                    'volume' => 1000.0,
                ];
                $day = $day->modify('+1 day');
            }

            return $bars;
        };

        // Stock has a wide daily range (4/close ≈ 4%); index bars are narrower
        // (4/2000 ≈ 0.2%). Condition: NIFTY50 range_pct < stock range_pct.
        $stockBars = $mkBars(array_map(fn ($i) => 100.0 + $i, range(0, 9)), '2026-06-01');
        $indexBars = $mkBars(array_map(fn ($i) => 2000.0 + $i, range(0, 9)), '2026-06-01');
        $dates = array_column($stockBars, 'date');

        $definition = [
            'root' => [
                'type' => 'condition',
                'left' => ['indicator' => 'range_pct', 'entity' => 'NIFTY50'],
                'operator' => 'lt',
                'weight_factor' => 1,
                'right' => ['indicator' => 'range_pct'],
            ],
        ];

        $results = $svc->evaluateAcrossDates($definition, $stockBars, $dates, ['NIFTY50' => $indexBars]);
        foreach ($dates as $date) {
            $this->assertFalse($results[$date]['skipped']);
            $this->assertTrue($results[$date]['matched'], "entity condition should match on {$date}");
        }

        // Entity with no bars yet on the first dates → left side null → no match,
        // but later dates align to the most recent index bar on or before them.
        $lateIndexBars = array_slice($indexBars, 5);
        $lateResults = $svc->evaluateAcrossDates($definition, $stockBars, $dates, ['NIFTY50' => $lateIndexBars]);
        $this->assertFalse($lateResults[$dates[0]]['matched']);
        $this->assertFalse($lateResults[$dates[4]]['matched']);
        $this->assertTrue($lateResults[$dates[5]]['matched']);
        $this->assertTrue($lateResults[$dates[9]]['matched']);
    }
}
