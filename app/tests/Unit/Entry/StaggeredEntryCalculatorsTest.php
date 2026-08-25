<?php

namespace Tests\Unit\Entry;

use App\Services\Entry\BuyCooldownEvaluator;
use App\Services\Entry\StaggeredEntryCalculator;
use App\Services\Entry\WholeShareQuantityCalculator;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class StaggeredEntryCalculatorsTest extends TestCase
{
    public function test_buy_cooldown_day0_allowed_day1_blocked_day2_elapsed(): void
    {
        $eval = new BuyCooldownEvaluator;
        $day0 = Carbon::parse('2026-08-24');

        $this->assertFalse($eval->isActive(null, $day0));
        $this->assertTrue($eval->isActive($day0, $day0)); // Day 0 after opportunity
        $this->assertTrue($eval->isActive($day0, Carbon::parse('2026-08-25')));
        $this->assertFalse($eval->isActive($day0, Carbon::parse('2026-08-26')));
        $this->assertSame(
            '2026-08-26',
            $eval->nextEligibleDate($day0)->toDateString()
        );
    }

    public function test_first_entry_uses_exact_fifty_percent_default(): void
    {
        $calc = new StaggeredEntryCalculator;
        $this->assertSame(5000.0, $calc->firstEntryAmount(10000.0));
        $this->assertSame(50.0, $calc->normalizeFirstEntryPct(null));
        $this->assertSame(50.0, $calc->normalizeFirstEntryPct(0));
        $this->assertSame(40.0, $calc->normalizeFirstEntryPct(40));
    }

    public function test_first_entry_not_confused_with_full_target(): void
    {
        $calc = new StaggeredEntryCalculator;
        $cycle = $calc->thisCycleIntendedAmount(36000.0, 0.0, false, 50.0);
        $this->assertTrue($cycle['is_first_entry']);
        $this->assertSame(18000.0, $cycle['this_cycle_amount']);
        $this->assertNotSame(36000.0, $cycle['this_cycle_amount']);
    }

    public function test_subsequent_uses_remaining_not_second_fifty_percent(): void
    {
        $calc = new StaggeredEntryCalculator;
        $cycle = $calc->thisCycleIntendedAmount(12000.0, 5000.0, true, 50.0);
        $this->assertFalse($cycle['is_first_entry']);
        $this->assertSame(7000.0, $cycle['this_cycle_amount']);
        $this->assertSame(7000.0, $cycle['remaining_amount']);
    }

    public function test_remaining_is_max_zero_when_filled_exceeds_target(): void
    {
        $calc = new StaggeredEntryCalculator;
        $this->assertSame(0.0, $calc->remainingAmount(8000.0, 10000.0));
        $cycle = $calc->thisCycleIntendedAmount(8000.0, 10000.0, true);
        $this->assertSame(0.0, $cycle['this_cycle_amount']);
    }

    public function test_whole_share_floors_and_preserves_residual(): void
    {
        $calc = new WholeShareQuantityCalculator;
        $out = $calc->fromAmount(2500.0, 600.0);
        $this->assertSame(4, $out['quantity']);
        $this->assertSame(2400.0, $out['notional']);
        $this->assertSame(100.0, $out['residual']);
    }

    public function test_twenty_k_target_nineteen_k_filled_remaining(): void
    {
        $calc = new StaggeredEntryCalculator;
        $this->assertSame(1000.0, $calc->remainingAmount(20000.0, 19000.0));
        $cycle = $calc->thisCycleIntendedAmount(20000.0, 19000.0, true);
        $this->assertSame(1000.0, $cycle['this_cycle_amount']);
    }
}
