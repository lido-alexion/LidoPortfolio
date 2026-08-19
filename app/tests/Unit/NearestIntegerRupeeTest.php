<?php

namespace Tests\Unit;

use App\Support\NearestIntegerRupee;
use InvalidArgumentException;
use Tests\TestCase;

class NearestIntegerRupeeTest extends TestCase
{
    public function test_exact_examples_from_od24(): void
    {
        $this->assertSame(150000, NearestIntegerRupee::round(750000 / 5));
        $this->assertSame(107143, NearestIntegerRupee::round(750000 / 7));
        $this->assertSame(93750, NearestIntegerRupee::round(750000 / 8));
    }

    public function test_half_rounds_upward_not_bankers(): void
    {
        $this->assertSame(1, NearestIntegerRupee::round(0.5));
        $this->assertSame(2, NearestIntegerRupee::round(1.5));
        $this->assertSame(3, NearestIntegerRupee::round(2.5));
        $this->assertSame(375001, NearestIntegerRupee::round(750001 / 2));
    }

    public function test_does_not_use_language_round(): void
    {
        $this->assertSame(3, NearestIntegerRupee::round(2.5));
        $this->assertNotSame((int) round(2.5, 0, PHP_ROUND_HALF_EVEN), NearestIntegerRupee::round(2.5));
    }

    public function test_rejects_negative_domain(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NearestIntegerRupee::round(-0.1);
    }
}
