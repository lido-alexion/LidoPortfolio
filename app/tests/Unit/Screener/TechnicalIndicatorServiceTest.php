<?php

namespace Tests\Unit\Screener;

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
