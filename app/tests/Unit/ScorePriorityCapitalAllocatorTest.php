<?php

namespace Tests\Unit;

use App\Engines\Recommendation\Allocation\ScorePriorityCapitalAllocator;
use PHPUnit\Framework\TestCase;

class ScorePriorityCapitalAllocatorTest extends TestCase
{
    public function test_allocates_highest_score_first_within_cash(): void
    {
        $allocator = new ScorePriorityCapitalAllocator;
        $out = $allocator->allocate(10000, [
            [
                'key' => 'b',
                'score' => 90,
                'confidence' => 80,
                'priority' => 90,
                'desired_amount' => 10000,
                'reference_price' => 2000,
                'action' => 'OPEN_POSITION',
                'max_position_amount' => null,
            ],
            [
                'key' => 'a',
                'score' => 95,
                'confidence' => 80,
                'priority' => 95,
                'desired_amount' => 10000,
                'reference_price' => 1000,
                'action' => 'OPEN_POSITION',
                'max_position_amount' => null,
            ],
        ]);

        $this->assertSame(6, $out['a']['quantity']);
        $this->assertEquals(6000.0, $out['a']['allocated_amount']);
        $this->assertSame(2, $out['b']['quantity']);
        $this->assertEquals(4000.0, $out['b']['allocated_amount']);
        $this->assertEqualsWithDelta(10000.0, $out['a']['allocated_amount'] + $out['b']['allocated_amount'], 0.01);
    }

    public function test_zero_cash_yields_zero_allocations(): void
    {
        $allocator = new ScorePriorityCapitalAllocator;
        $out = $allocator->allocate(0, [
            [
                'key' => 0,
                'score' => 99,
                'confidence' => 90,
                'priority' => 99,
                'desired_amount' => 5000,
                'reference_price' => 100,
                'action' => 'OPEN_POSITION',
                'max_position_amount' => null,
            ],
        ]);

        $this->assertSame(0, $out[0]['quantity']);
        $this->assertEquals(0.0, $out[0]['allocated_amount']);
    }
}
