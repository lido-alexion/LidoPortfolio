<?php

namespace Tests\Unit\Screener;

use App\Services\Screener\ScreenerCatalog;
use App\Services\Screener\ScreenerEvaluationService;
use App\Services\Screener\TechnicalIndicatorService;
use PHPUnit\Framework\TestCase;

class TechnicalIndicatorServiceTest extends TestCase
{
    public function test_sma_and_ema_known_values(): void
    {
        $closes = [1.0, 2.0, 3.0, 4.0, 5.0];
        $svc = new TechnicalIndicatorService;

        $sma = $svc->sma($closes, 3);
        $this->assertNull($sma[0]);
        $this->assertNull($sma[1]);
        $this->assertEqualsWithDelta(2.0, $sma[2], 1e-9);
        $this->assertEqualsWithDelta(3.0, $sma[3], 1e-9);
        $this->assertEqualsWithDelta(4.0, $sma[4], 1e-9);

        $ema = $svc->ema($closes, 3);
        $this->assertEqualsWithDelta(2.0, $ema[2], 1e-9); // SMA seed
        // k = 2/4 = 0.5; next = (4-2)*0.5 + 2 = 3
        $this->assertEqualsWithDelta(3.0, $ema[3], 1e-9);
        // next = (5-3)*0.5 + 3 = 4
        $this->assertEqualsWithDelta(4.0, $ema[4], 1e-9);
    }

    public function test_rsi_all_gains_is_100(): void
    {
        $closes = [];
        for ($i = 0; $i < 20; $i++) {
            $closes[] = 10.0 + $i;
        }
        $svc = new TechnicalIndicatorService;
        $rsi = $svc->rsi($closes, 14);
        $last = null;
        for ($i = count($rsi) - 1; $i >= 0; $i--) {
            if ($rsi[$i] !== null) {
                $last = $rsi[$i];
                break;
            }
        }
        $this->assertNotNull($last);
        $this->assertEqualsWithDelta(100.0, $last, 1e-6);
    }

    public function test_high_52w_and_low_52w_use_up_to_252_sessions(): void
    {
        $svc = new TechnicalIndicatorService;
        $bars = [];
        for ($i = 0; $i < 260; $i++) {
            $bars[] = [
                'open' => 100.0,
                'high' => 100.0 + ($i === 200 ? 50.0 : 1.0),
                'low' => 100.0 - ($i === 150 ? 40.0 : 1.0),
                'close' => 100.0,
                'volume' => 1000.0,
            ];
        }
        $engine = $svc->withBars($bars);

        $high52 = $engine->evaluate(['indicator' => 'high_52w']);
        $low52 = $engine->evaluate(['indicator' => 'low_52w']);

        $this->assertEqualsWithDelta(150.0, $high52, 1e-9);
        $this->assertEqualsWithDelta(60.0, $low52, 1e-9);

        // Spike at index 0 is outside the last-252 window when 260 bars exist.
        $bars[0]['high'] = 999.0;
        $capped = $svc->withBars($bars)->evaluate(['indicator' => 'high_52w']);
        $this->assertEqualsWithDelta(150.0, $capped, 1e-9);

        // Shorter than 252: use all available history (not skipped).
        $short = array_slice($bars, -200);
        $shortHigh = $svc->withBars($short)->evaluate(['indicator' => 'high_52w']);
        $this->assertEqualsWithDelta(150.0, $shortHigh, 1e-9);

        $eval = new ScreenerEvaluationService(new TechnicalIndicatorService);
        $definition = [
            'root' => [
                'type' => 'condition',
                'left' => ['indicator' => 'close'],
                'operator' => 'gte',
                'right' => ['indicator' => 'low_52w'],
            ],
        ];
        $this->assertSame(1, $eval->maxLookback($definition));
        $this->assertSame(1, ScreenerCatalog::minBars('high_52w', []));
    }

    public function test_atr_period_one_needs_one_session(): void
    {
        $svc = new TechnicalIndicatorService;
        $bars = [
            ['open' => 10.0, 'high' => 12.0, 'low' => 9.0, 'close' => 11.0, 'volume' => 100.0],
        ];
        $value = $svc->withBars($bars)->evaluate(['indicator' => 'atr', 'params' => ['period' => 1]]);
        $this->assertEqualsWithDelta(3.0, $value, 1e-9);
        $this->assertSame(1, ScreenerCatalog::minBars('atr', ['period' => 1]));
        $this->assertSame(14, ScreenerCatalog::minBars('atr', ['period' => 14]));
    }

    public function test_sma_period_one_needs_one_session(): void
    {
        $svc = new TechnicalIndicatorService;
        $bars = [
            ['open' => 10.0, 'high' => 11.0, 'low' => 9.0, 'close' => 10.5, 'volume' => 100.0],
        ];
        $value = $svc->withBars($bars)->evaluate(['indicator' => 'sma', 'params' => ['period' => 1]]);
        $this->assertEqualsWithDelta(10.5, $value, 1e-9);
        $this->assertSame(1, ScreenerCatalog::minBars('sma', ['period' => 1]));

        $eval = new ScreenerEvaluationService(new TechnicalIndicatorService);
        $result = $eval->evaluateStock([
            'root' => [
                'type' => 'condition',
                'left' => ['indicator' => 'sma', 'params' => ['period' => 1]],
                'operator' => 'gt',
                'right' => ['type' => 'constant', 'value' => 10],
            ],
        ], $bars);
        $this->assertFalse($result['skipped']);
        $this->assertTrue($result['matched']);
    }

    public function test_weight_factor_scales_right_hand_side(): void
    {
        $eval = new ScreenerEvaluationService(new TechnicalIndicatorService);
        $bars = [
            ['open' => 10.0, 'high' => 11.0, 'low' => 9.0, 'close' => 10.0, 'volume' => 100.0],
        ];

        $withoutWeight = $eval->evaluateStock([
            'root' => [
                'type' => 'condition',
                'left' => ['indicator' => 'close'],
                'operator' => 'gt',
                'right' => ['type' => 'constant', 'value' => 10],
            ],
        ], $bars);
        $this->assertFalse($withoutWeight['matched']);

        $withHalf = $eval->evaluateStock([
            'root' => [
                'type' => 'condition',
                'left' => ['indicator' => 'close'],
                'operator' => 'gt',
                'weight_factor' => 0.5,
                'right' => ['type' => 'constant', 'value' => 10],
            ],
        ], $bars);
        $this->assertTrue($withHalf['matched']);
        $this->assertSame(0.5, $withHalf['metrics'][0]['weight_factor']);
        $this->assertEqualsWithDelta(5.0, $withHalf['metrics'][0]['right_scaled'], 1e-9);

        $missingDefaultsToOne = $eval->evaluateStock([
            'root' => [
                'type' => 'condition',
                'left' => ['indicator' => 'close'],
                'operator' => 'gt',
                'right' => ['type' => 'constant', 'value' => 9],
            ],
        ], $bars);
        $this->assertTrue($missingDefaultsToOne['matched']);
        $this->assertSame(1.0, $missingDefaultsToOne['metrics'][0]['weight_factor']);
    }

    public function test_left_entity_evaluates_on_index_bars(): void
    {
        $eval = new ScreenerEvaluationService(new TechnicalIndicatorService);
        // Stock range_pct = (12-9)/10 = 30%; index range_pct = (101-100)/100 = 1%.
        $stockBars = [
            ['open' => 10.0, 'high' => 12.0, 'low' => 9.0, 'close' => 10.0, 'volume' => 100.0],
        ];
        $indexBars = [
            ['open' => 100.0, 'high' => 101.0, 'low' => 100.0, 'close' => 100.0, 'volume' => null],
        ];
        $definition = [
            'root' => [
                'type' => 'condition',
                'left' => ['entity' => 'NIFTY50', 'indicator' => 'range_pct', 'params' => []],
                'operator' => 'lt',
                'right' => ['indicator' => 'range_pct', 'params' => []],
            ],
        ];

        $result = $eval->evaluateStock($definition, $stockBars, ['NIFTY50' => $indexBars]);
        $this->assertFalse($result['skipped']);
        $this->assertTrue($result['matched']);
        $this->assertSame('NIFTY50', $result['metrics'][0]['left_entity']);
        $this->assertSame('Nifty 50 range_pct', $result['metrics'][0]['left']);
        $this->assertEqualsWithDelta(1.0, $result['metrics'][0]['left_value'], 1e-9);
        $this->assertEqualsWithDelta(30.0, $result['metrics'][0]['right_value'], 1e-9);

        // Missing index bars → condition false but stock is not skipped.
        $missing = $eval->evaluateStock($definition, $stockBars, []);
        $this->assertFalse($missing['skipped']);
        $this->assertFalse($missing['matched']);
        $this->assertNull($missing['metrics'][0]['left_value']);
    }

    public function test_entity_lookbacks_and_stock_lookback_split(): void
    {
        $eval = new ScreenerEvaluationService(new TechnicalIndicatorService);
        $definition = [
            'root' => [
                'type' => 'group',
                'op' => 'AND',
                'children' => [
                    [
                        'type' => 'condition',
                        'left' => ['entity' => 'NIFTY50', 'indicator' => 'sma', 'params' => ['period' => 200]],
                        'operator' => 'gt',
                        'right' => ['type' => 'constant', 'value' => 0],
                    ],
                    [
                        'type' => 'condition',
                        'left' => ['indicator' => 'sma', 'params' => ['period' => 20]],
                        'operator' => 'gt',
                        'right' => ['indicator' => 'sma', 'params' => ['period' => 50]],
                    ],
                ],
            ],
        ];

        $this->assertSame(200, $eval->maxLookback($definition));
        $this->assertSame(50, $eval->stockLookback($definition));
        $this->assertSame(['NIFTY50' => 200], $eval->entityLookbacks($definition));

        // A stock with 50 bars must not be skipped just because the index side needs 200.
        $bars = [];
        for ($i = 0; $i < 50; $i++) {
            $c = 100.0 + $i;
            $bars[] = ['open' => $c, 'high' => $c + 1, 'low' => $c - 1, 'close' => $c, 'volume' => 100.0];
        }
        $result = $eval->evaluateStock($definition, $bars, ['NIFTY50' => []]);
        $this->assertFalse($result['skipped']);
        $this->assertFalse($result['matched']);
    }

    public function test_condition_tree_and_or_and_insufficient_bars(): void
    {
        $eval = new ScreenerEvaluationService(new TechnicalIndicatorService);
        $bars = [];
        for ($i = 0; $i < 60; $i++) {
            $c = 100.0 + $i * 0.5;
            $bars[] = [
                'open' => $c,
                'high' => $c + 1,
                'low' => $c - 1,
                'close' => $c,
                'volume' => 1000.0,
            ];
        }

        $definition = [
            'root' => [
                'type' => 'group',
                'op' => 'AND',
                'children' => [
                    [
                        'type' => 'condition',
                        'left' => ['indicator' => 'ema', 'params' => ['period' => 10]],
                        'operator' => 'gt',
                        'right' => ['indicator' => 'ema', 'params' => ['period' => 20]],
                    ],
                    [
                        'type' => 'group',
                        'op' => 'OR',
                        'children' => [
                            [
                                'type' => 'condition',
                                'left' => ['indicator' => 'rsi', 'params' => ['period' => 14]],
                                'operator' => 'gt',
                                'right' => ['type' => 'constant', 'value' => 50],
                            ],
                            [
                                'type' => 'condition',
                                'left' => ['indicator' => 'close'],
                                'operator' => 'lt',
                                'right' => ['type' => 'constant', 'value' => 0],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame(20, $eval->maxLookback($definition));

        $result = $eval->evaluateStock($definition, $bars);
        $this->assertFalse($result['skipped']);
        $this->assertTrue($result['matched']);

        $short = array_slice($bars, -10);
        $skip = $eval->evaluateStock($definition, $short);
        $this->assertTrue($skip['skipped']);
        $this->assertFalse($skip['matched']);
        $this->assertSame('insufficient_data', $skip['skip_reason']);
    }
}
