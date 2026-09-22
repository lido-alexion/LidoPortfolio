<?php

namespace Tests\Feature\Execution;

use App\Exceptions\DomainException;
use App\Services\Broker\BrokerOrderPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BrokerOrderPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_orders_are_used_during_the_nse_session(): void
    {
        $this->assertSame(
            BrokerOrderPolicy::REGULAR,
            app(BrokerOrderPolicy::class)->variety(Carbon::parse('2026-09-22 10:00:00', 'Asia/Kolkata')),
        );
    }

    public function test_amo_orders_are_used_in_the_supported_after_market_window(): void
    {
        $this->assertSame(
            BrokerOrderPolicy::AMO,
            app(BrokerOrderPolicy::class)->variety(Carbon::parse('2026-09-22 18:00:00', 'Asia/Kolkata')),
        );
    }

    public function test_transition_window_is_blocked_instead_of_guessing_a_variety(): void
    {
        try {
            app(BrokerOrderPolicy::class)->variety(Carbon::parse('2026-09-22 09:05:00', 'Asia/Kolkata'));
            $this->fail('Expected the unsupported transition window to be blocked.');
        } catch (DomainException $exception) {
            $this->assertSame('BROKER_ORDER_WINDOW_UNSUPPORTED', $exception->errorCode());
        }
    }

    public function test_weekends_are_blocked_even_when_the_clock_is_in_an_amo_window(): void
    {
        try {
            app(BrokerOrderPolicy::class)->variety(Carbon::parse('2026-09-26 18:00:00', 'Asia/Kolkata'));
            $this->fail('Expected the weekend order to be blocked.');
        } catch (DomainException $exception) {
            $this->assertSame('BROKER_ORDER_WINDOW_UNSUPPORTED', $exception->errorCode());
        }
    }
}
