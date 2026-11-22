<?php

namespace Tests\Unit;

use App\Services\PriceProviders\KitePriceRequestGate;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class KitePriceRequestGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_circuit_opens_after_three_failures_and_stays_user_scoped(): void
    {
        $gate = app(KitePriceRequestGate::class);
        $gate->failed('historical', 10);
        $gate->failed('historical', 10);
        $gate->failed('historical', 10);

        try {
            $gate->before('historical', 10);
            $this->fail('Expected the circuit for user 10 to be open.');
        } catch (RuntimeException $error) {
            $this->assertSame('Kite market-data circuit is open.', $error->getMessage());
        }

        $gate->failed('historical', 10);
        $this->assertSame(3, Cache::get('kite:price:circuit:10:historical'));
        $gate->before('historical', 11);
    }
}
