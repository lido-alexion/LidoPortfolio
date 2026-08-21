<?php

namespace Tests\Unit;

use App\Support\FloorToRupee5000;
use PHPUnit\Framework\TestCase;

class FloorToRupee5000Test extends TestCase
{
    public function test_lending_floor_examples(): void
    {
        $this->assertSame(0.0, FloorToRupee5000::floor(0));
        $this->assertSame(0.0, FloorToRupee5000::floor(4999));
        $this->assertSame(5000.0, FloorToRupee5000::floor(5000));
        $this->assertSame(5000.0, FloorToRupee5000::floor(9999));
        $this->assertSame(10000.0, FloorToRupee5000::floor(10000));
        $this->assertSame(0.0, FloorToRupee5000::floor(-1));
    }
}
