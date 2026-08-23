<?php

namespace Tests\Unit\Lending;

use App\Services\Lending\RecallAmountCalculator;
use App\Services\Lending\RecallBridgeEligibilityCalculator;
use App\Services\Lending\RecallImmediateSettlementEvaluator;
use InvalidArgumentException;
use Tests\TestCase;

class RecallDomainCalculatorsTest extends TestCase
{
    public function test_full_recall_equals_entire_outstanding_including_non_5k(): void
    {
        $calc = new RecallAmountCalculator;
        $sized = $calc->full(12_000);
        $this->assertSame(RecallAmountCalculator::KIND_FULL, $sized['kind']);
        $this->assertEqualsWithDelta(12_000.0, $sized['amount'], 0.0001);
    }

    public function test_partial_recall_requires_5k_multiple(): void
    {
        $calc = new RecallAmountCalculator;
        $ok = $calc->partial(10_000, 20_000);
        $this->assertSame(RecallAmountCalculator::KIND_PARTIAL, $ok['kind']);
        $this->assertEqualsWithDelta(10_000.0, $ok['amount'], 0.0001);

        $this->expectException(InvalidArgumentException::class);
        $calc->partial(3_000, 20_000);
    }

    public function test_recall_cannot_exceed_outstanding(): void
    {
        $calc = new RecallAmountCalculator;
        $this->expectException(InvalidArgumentException::class);
        $calc->partial(15_000, 10_000);
    }

    public function test_for_shortfall_full_when_need_covers_outstanding(): void
    {
        $calc = new RecallAmountCalculator;
        $sized = $calc->forShortfall(20_000, 12_000);
        $this->assertNotNull($sized);
        $this->assertSame(RecallAmountCalculator::KIND_FULL, $sized['kind']);
        $this->assertEqualsWithDelta(12_000.0, $sized['amount'], 0.0001);
    }

    public function test_for_shortfall_partial_floors_to_5k(): void
    {
        $calc = new RecallAmountCalculator;
        $sized = $calc->forShortfall(14_000, 50_000);
        $this->assertNotNull($sized);
        $this->assertSame(RecallAmountCalculator::KIND_PARTIAL, $sized['kind']);
        $this->assertEqualsWithDelta(10_000.0, $sized['amount'], 0.0001);
    }

    public function test_bridge_eligibility_applies_to_shortfall_after_own_cash(): void
    {
        $calc = new RecallBridgeEligibilityCalculator;
        $eval = $calc->evaluate(20_000, 10_000, 100_000);
        $this->assertEqualsWithDelta(10_000.0, $eval['bridge_need'], 0.0001);
        $this->assertEqualsWithDelta(10_000.0, $eval['eligible_bridge'], 0.0001);
    }

    public function test_bridge_10_percent_cushion_limits_amount(): void
    {
        $calc = new RecallBridgeEligibilityCalculator;
        $this->assertEqualsWithDelta(5_500.0, $calc->requiredStockValueForBridge(5_000), 0.0001);
        $this->assertEqualsWithDelta(5_000.0, $calc->maxBridgeSupportedByStock(5_500), 0.0001);

        $eval = $calc->evaluate(20_000, 10_000, 5_500);
        $this->assertEqualsWithDelta(10_000.0, $eval['bridge_need'], 0.0001);
        $this->assertEqualsWithDelta(5_000.0, $eval['eligible_bridge'], 0.0001);
    }

    public function test_immediate_settlement_at_exactly_75_percent(): void
    {
        $eval = (new RecallImmediateSettlementEvaluator)->evaluate(20_000, 10_000, 5_500);
        $this->assertTrue($eval['allows_immediate']);
        $this->assertEqualsWithDelta(15_000.0, $eval['threshold'], 0.0001);
        $this->assertEqualsWithDelta(15_000.0, $eval['settle_amount'], 0.0001);
        $this->assertEqualsWithDelta(5_000.0, $eval['use_bridge_amount'], 0.0001);
    }

    public function test_immediate_settlement_settles_maximum_not_only_75(): void
    {
        $eval = (new RecallImmediateSettlementEvaluator)->evaluate(20_000, 10_000, 8_800);
        $this->assertTrue($eval['allows_immediate']);
        $this->assertEqualsWithDelta(18_000.0, $eval['settle_amount'], 0.0001);
    }

    public function test_below_75_percent_does_not_allow_immediate(): void
    {
        $eval = (new RecallImmediateSettlementEvaluator)->evaluate(20_000, 10_000, 2_200);
        $this->assertFalse($eval['allows_immediate']);
        $this->assertEqualsWithDelta(0.0, $eval['settle_amount'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $eval['use_bridge_amount'], 0.0001);
    }

    public function test_threshold_is_against_recall_amount_not_loan_outstanding(): void
    {
        $eval = (new RecallImmediateSettlementEvaluator)->evaluate(8_000, 7_000, 0);
        $this->assertEqualsWithDelta(6_000.0, $eval['threshold'], 0.0001);
        $this->assertTrue($eval['allows_immediate']);
        $this->assertEqualsWithDelta(7_000.0, $eval['settle_amount'], 0.0001);
    }

    public function test_100_percent_settlement(): void
    {
        $eval = (new RecallImmediateSettlementEvaluator)->evaluate(20_000, 20_000, 0);
        $this->assertTrue($eval['allows_immediate']);
        $this->assertEqualsWithDelta(20_000.0, $eval['settle_amount'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $eval['use_bridge_amount'], 0.0001);
    }
}
