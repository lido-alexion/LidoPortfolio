<?php

namespace Tests\Feature\V8;

use App\Services\Fundamentals\Historical\ExchangeRequestGate;
use App\Services\Fundamentals\Historical\ExchangeRequestDeferred;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExchangeRequestGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'fundamentals_bootstrap.exchange_min_gap_ms' => 5000,
            'fundamentals_bootstrap.exchange_daily_request_cap' => 1,
            'fundamentals_bootstrap.exchange_block_cooldown_seconds' => 21600,
        ]);
    }

    public function test_429_opens_a_shared_cooldown_and_prevents_the_next_request(): void
    {
        $gate = new ExchangeRequestGate;
        $sent = 0;
        try {
            $gate->request(function () use (&$sent) {
                $sent++;
                return new FakeExchangeResponse(429);
            });
            $this->fail('A 429 response should defer the source.');
        } catch (ExchangeRequestDeferred $deferred) {
            $this->assertGreaterThanOrEqual(21600, $deferred->retryAfterSeconds);
        }
        try {
            $gate->request(function () use (&$sent) {
                $sent++;
                return new FakeExchangeResponse(200);
            });
            $this->fail('The shared cooldown should block the next request.');
        } catch (ExchangeRequestDeferred) {
            $this->assertSame(1, $sent);
        }
    }

    public function test_daily_cap_stops_further_exchange_requests(): void
    {
        $gate = new ExchangeRequestGate;
        $sent = 0;
        $gate->request(function () use (&$sent) { $sent++; return new FakeExchangeResponse(200); });
        try {
            $gate->request(function () use (&$sent) { $sent++; return new FakeExchangeResponse(200); });
            $this->fail('The daily cap should defer further requests.');
        } catch (ExchangeRequestDeferred) {
            $this->assertSame(1, $sent);
        }
    }
}

class FakeExchangeResponse
{
    public function __construct(private readonly int $code) {}
    public function status(): int { return $this->code; }
    public function header(string $name): ?string { return null; }
}
