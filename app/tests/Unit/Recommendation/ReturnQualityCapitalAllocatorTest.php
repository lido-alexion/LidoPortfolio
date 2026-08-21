<?php

namespace Tests\Unit\Recommendation;

use App\Engines\Recommendation\Allocation\CapitalAllocationStrategy;
use App\Engines\Recommendation\Allocation\ReturnQualityCapitalAllocator;
use Tests\TestCase;

class ReturnQualityCapitalAllocatorTest extends TestCase
{
    private ReturnQualityCapitalAllocator $alloc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alloc = new ReturnQualityCapitalAllocator;
    }

    private function draft(
        string|int $key,
        float $desired,
        float $price,
        ?float $maxPos = null,
    ): array {
        return [
            'key' => $key,
            'score' => 80.0,
            'confidence' => 0.8,
            'priority' => 90,
            'desired_amount' => $desired,
            'reference_price' => $price,
            'action' => 'OPEN_POSITION',
            'max_position_amount' => $maxPos,
        ];
    }

    // ═══ SEQUENTIAL BEHAVIOUR ═══

    public function test_first_candidate_filled_before_second(): void
    {
        $out = $this->alloc->allocate(20_000, [
            $this->draft('A', 15_000, 100),
            $this->draft('B', 15_000, 100),
        ]);

        $this->assertEquals(150, $out['A']['quantity']);
        $this->assertEquals(15_000, $out['A']['allocated_amount']);
        $this->assertEquals(50, $out['B']['quantity']);
        $this->assertEquals(5_000, $out['B']['allocated_amount']);
    }

    public function test_capital_is_not_proportionally_distributed(): void
    {
        $out = $this->alloc->allocate(10_000, [
            $this->draft('A', 10_000, 100),
            $this->draft('B', 10_000, 100),
        ]);

        $this->assertEquals(10_000, $out['A']['allocated_amount']);
        $this->assertEquals(0.0, $out['B']['allocated_amount']);
    }

    public function test_remaining_capital_flows_to_next_candidate(): void
    {
        $out = $this->alloc->allocate(25_000, [
            $this->draft('A', 10_000, 100),
            $this->draft('B', 10_000, 100),
            $this->draft('C', 10_000, 100),
        ]);

        $this->assertEquals(10_000, $out['A']['allocated_amount']);
        $this->assertEquals(10_000, $out['B']['allocated_amount']);
        $this->assertEquals(5_000, $out['C']['allocated_amount']);
    }

    // ═══ FULL FUNDING ═══

    public function test_target_fully_funded(): void
    {
        $out = $this->alloc->allocate(50_000, [
            $this->draft('A', 20_000, 100),
        ]);

        $this->assertEquals(20_000, $out['A']['allocated_amount']);
        $this->assertEquals(200, $out['A']['quantity']);
        $this->assertEquals('FULLY_FUNDED', $out['A']['funding_status']);
        $this->assertEqualsWithDelta(0.0, $out['A']['unfunded_amount'], 0.01);
    }

    public function test_target_limited_by_available_capital(): void
    {
        $out = $this->alloc->allocate(5_000, [
            $this->draft('A', 20_000, 100),
        ]);

        $this->assertEquals(5_000, $out['A']['allocated_amount']);
        $this->assertEquals(50, $out['A']['quantity']);
    }

    // ═══ PARTIAL FUNDING (OD-05) ═══

    public function test_partial_funding_when_insufficient_capital(): void
    {
        $out = $this->alloc->allocate(10_000, [
            $this->draft('A', 18_000, 100),
        ]);

        $this->assertEquals(10_000, $out['A']['allocated_amount']);
        $this->assertEquals('PARTIALLY_FUNDED', $out['A']['funding_status']);
    }

    public function test_original_target_preserved_in_partial(): void
    {
        $out = $this->alloc->allocate(10_000, [
            $this->draft('A', 18_000, 100),
        ]);

        $this->assertEquals(18_000, $out['A']['target_amount']);
    }

    public function test_unfunded_remainder_preserved(): void
    {
        $out = $this->alloc->allocate(10_000, [
            $this->draft('A', 18_000, 100),
        ]);

        $this->assertEquals(8_000, $out['A']['unfunded_amount']);
    }

    // ═══ UNFUNDED ═══

    public function test_zero_capital_produces_unfunded(): void
    {
        $out = $this->alloc->allocate(0, [
            $this->draft('A', 18_000, 100),
        ]);

        $this->assertEquals(0.0, $out['A']['allocated_amount']);
        $this->assertEquals(0, $out['A']['quantity']);
        $this->assertEquals('UNFUNDED', $out['A']['funding_status']);
    }

    public function test_unfunded_target_is_not_erased(): void
    {
        $out = $this->alloc->allocate(0, [
            $this->draft('A', 18_000, 100),
        ]);

        $this->assertEquals(18_000, $out['A']['target_amount']);
    }

    public function test_allocator_does_not_convert_to_watch(): void
    {
        $out = $this->alloc->allocate(0, [
            $this->draft('A', 18_000, 100),
        ]);

        $this->assertArrayNotHasKey('action', $out['A']);
        $this->assertNotEquals('WATCH', $out['A']['funding_status']);
    }

    public function test_insufficient_for_one_share_produces_unfunded(): void
    {
        $out = $this->alloc->allocate(50, [
            $this->draft('A', 18_000, 100),
        ]);

        $this->assertEquals(0, $out['A']['quantity']);
        $this->assertEquals('UNFUNDED', $out['A']['funding_status']);
        $this->assertEquals(18_000, $out['A']['target_amount']);
    }

    // ═══ ORDERING ═══

    public function test_ranking_computable_order_is_respected(): void
    {
        $out = $this->alloc->allocate(10_000, [
            $this->draft('RANKED_1ST', 10_000, 100),
            $this->draft('RANKED_2ND', 10_000, 100),
        ]);

        $this->assertEquals(10_000, $out['RANKED_1ST']['allocated_amount']);
        $this->assertEquals(0.0, $out['RANKED_2ND']['allocated_amount']);
    }

    public function test_od23_fallback_order_is_respected(): void
    {
        $out = $this->alloc->allocate(10_000, [
            $this->draft('OD23_1ST', 10_000, 200),
            $this->draft('OD23_2ND', 10_000, 200),
        ]);

        $this->assertEquals(10_000, $out['OD23_1ST']['allocated_amount']);
        $this->assertEquals(0.0, $out['OD23_2ND']['allocated_amount']);
    }

    public function test_no_v1_score_weighting_occurs(): void
    {
        $drafts = [
            array_merge($this->draft('LOW_SCORE', 10_000, 100), ['score' => 20]),
            array_merge($this->draft('HIGH_SCORE', 10_000, 100), ['score' => 99]),
        ];

        $out = $this->alloc->allocate(10_000, $drafts);

        $this->assertEquals(10_000, $out['LOW_SCORE']['allocated_amount']);
        $this->assertEquals(0.0, $out['HIGH_SCORE']['allocated_amount']);
    }

    // ═══ STRATEGY CAPITAL ═══

    public function test_uses_supplied_available_capital(): void
    {
        $out = $this->alloc->allocate(7_500, [
            $this->draft('A', 10_000, 100),
        ]);

        $this->assertEquals(7_500, $out['A']['allocated_amount']);
    }

    // ═══ CONSTRAINTS ═══

    public function test_whole_share_quantity(): void
    {
        $out = $this->alloc->allocate(10_000, [
            $this->draft('A', 10_000, 333),
        ]);

        $this->assertEquals(30, $out['A']['quantity']);
        $this->assertEquals(9_990, $out['A']['allocated_amount']);
    }

    public function test_max_position_constraint(): void
    {
        $out = $this->alloc->allocate(50_000, [
            $this->draft('A', 30_000, 100, 15_000),
        ]);

        $this->assertEquals(15_000, $out['A']['allocated_amount']);
        $this->assertEquals(150, $out['A']['quantity']);
        $this->assertEquals('FULLY_FUNDED', $out['A']['funding_status']);
    }

    // ═══ OD-06 ATOMIC ALLOCATION ═══

    public function test_atomic_allocation_examples(): void
    {
        $this->assertEquals(25_000, ReturnQualityCapitalAllocator::atomicAllocation(23_700));
        $this->assertEquals(30_000, ReturnQualityCapitalAllocator::atomicAllocation(25_000));
        $this->assertEquals(20_000, ReturnQualityCapitalAllocator::atomicAllocation(19_000));
        $this->assertEquals(5_000, ReturnQualityCapitalAllocator::atomicAllocation(4_000));
    }

    public function test_atomic_reservation_in_result(): void
    {
        $out = $this->alloc->allocate(50_000, [
            $this->draft('A', 23_700, 100),
        ]);

        $this->assertEquals(25_000, $out['A']['atomic_reservation']);
    }

    // ═══ EDGE CASES ═══

    public function test_empty_drafts(): void
    {
        $out = $this->alloc->allocate(50_000, []);
        $this->assertEquals([], $out);
    }

    public function test_zero_price_produces_zero_allocation(): void
    {
        $out = $this->alloc->allocate(50_000, [
            $this->draft('A', 10_000, 0),
        ]);

        $this->assertEquals(0, $out['A']['quantity']);
        $this->assertEquals(0.0, $out['A']['allocated_amount']);
    }

    public function test_zero_desired_amount(): void
    {
        $out = $this->alloc->allocate(50_000, [
            $this->draft('A', 0, 100),
        ]);

        $this->assertEquals(0, $out['A']['quantity']);
        $this->assertEquals('UNFUNDED', $out['A']['funding_status']);
    }

    // ═══ V1 INTERFACE COMPATIBILITY ═══

    public function test_implements_capital_allocation_strategy(): void
    {
        $this->assertInstanceOf(
            CapitalAllocationStrategy::class,
            $this->alloc
        );
    }
}
