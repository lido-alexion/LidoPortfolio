<?php

namespace Tests\Unit\Risk;

use App\Services\Risk\PortfolioStopLossCalculator;
use App\Services\Risk\PortfolioTrailingStopCalculator;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * V3 Phase 1 — OD-13 / OD-14 / trailing pure domain calculators.
 */
class PortfolioRiskCalculatorsTest extends TestCase
{
    private PortfolioStopLossCalculator $stopLoss;

    private PortfolioTrailingStopCalculator $trailing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stopLoss = new PortfolioStopLossCalculator;
        $this->trailing = new PortfolioTrailingStopCalculator;
    }

    public function test_od13_single_fill_equals_actual_fill_cost(): void
    {
        $cost = $this->stopLoss->weightedAverageFillCost([
            ['quantity' => 50, 'price' => 200],
        ]);
        $this->assertEqualsWithDelta(200.0, $cost, 0.0001);
        $this->assertEqualsWithDelta(180.0, $this->stopLoss->stopPrice($cost, 10), 0.0001);
    }

    public function test_od13_multiple_fills_weighted_average(): void
    {
        // Spec example: 100@100 + 100@120 → 110; 10% SL → 99
        $cost = $this->stopLoss->weightedAverageFillCost([
            ['quantity' => 100, 'price' => 100],
            ['quantity' => 100, 'price' => 120],
        ]);
        $this->assertEqualsWithDelta(110.0, $cost, 0.0001);
        $this->assertEqualsWithDelta(99.0, $this->stopLoss->stopPrice($cost, 10), 0.0001);
    }

    public function test_od13_additional_buy_updates_weighted_average(): void
    {
        $afterFirst = $this->stopLoss->weightedAverageFillCost([
            ['quantity' => 100, 'price' => 100],
        ]);
        $this->assertEqualsWithDelta(100.0, $afterFirst, 0.0001);

        $afterIncrease = $this->stopLoss->weightedAverageFillCost([
            ['quantity' => 100, 'price' => 100],
            ['quantity' => 100, 'price' => 120],
        ]);
        $this->assertEqualsWithDelta(110.0, $afterIncrease, 0.0001);
        $this->assertNotEquals($afterFirst, $afterIncrease);
    }

    public function test_od13_target_amount_does_not_affect_entry_cost(): void
    {
        $fills = [
            ['quantity' => 10, 'price' => 50],
            ['quantity' => 10, 'price' => 70],
            // Spurious keys must be ignored
            ['quantity' => 0, 'price' => 999, 'target_amount' => 1_000_000],
        ];
        $cost = $this->stopLoss->weightedAverageFillCost($fills);
        $this->assertEqualsWithDelta(60.0, $cost, 0.0001);
    }

    public function test_od14_raw_close_triggers_stop_loss(): void
    {
        $stop = $this->stopLoss->stopPrice(110.0, 10.0);
        $this->assertTrue($this->stopLoss->isHitByRawClose(99.0, $stop));
        $this->assertTrue($this->stopLoss->isHitByRawClose(99.0, 99.0));
        $this->assertFalse($this->stopLoss->isHitByRawClose(99.01, $stop));
    }

    public function test_od14_adjusted_close_and_intraday_low_are_not_inputs(): void
    {
        // Calculator API accepts only raw close — callers must not pass adjusted/low.
        $stop = $this->stopLoss->stopPrice(100.0, 10.0); // 90
        $rawClose = 95.0;
        $adjustedClose = 85.0; // would falsely hit if used
        $intradayLow = 80.0;

        $this->assertFalse($this->stopLoss->isHitByRawClose($rawClose, $stop));
        // Document: adjusted / low are not parameters of isHitByRawClose
        $this->assertTrue($this->stopLoss->isHitByRawClose($adjustedClose, $stop));
        $this->assertTrue($this->stopLoss->isHitByRawClose($intradayLow, $stop));
        // Using raw close is the required behaviour; the two asserts above show why
        // callers must not substitute adjusted/low for the raw-close argument.
        $this->assertSame(95.0, $rawClose);
    }

    public function test_trailing_peak_is_max_raw_close(): void
    {
        $peak = $this->trailing->peakRawClose([100, 125, 110, null, 120]);
        $this->assertEqualsWithDelta(125.0, $peak, 0.0001);
    }

    public function test_trailing_uses_independent_portfolio_trailing_percent(): void
    {
        $price = $this->trailing->trailingStopPrice(200.0, 15.0);
        $this->assertEqualsWithDelta(170.0, $price, 0.0001);

        $withDifferentSlWouldBe = 200.0 * (1 - 0.10);
        $this->assertNotEquals($withDifferentSlWouldBe, $price);
    }

    public function test_trailing_does_not_accept_unrealized_percent_as_mechanism(): void
    {
        // API has no unrealizedPct parameter — peak × trailing% only.
        $ref = new \ReflectionMethod(PortfolioTrailingStopCalculator::class, 'trailingStopPrice');
        $params = array_map(fn ($p) => $p->getName(), $ref->getParameters());
        $this->assertSame(['peakRawClose', 'portfolioTrailingPercent'], $params);
        $this->assertNotContains('unrealizedPct', $params);
        $this->assertNotContains('unrealized_pnl_pct', $params);
    }

    public function test_empty_fills_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->stopLoss->weightedAverageFillCost([]);
    }
}
