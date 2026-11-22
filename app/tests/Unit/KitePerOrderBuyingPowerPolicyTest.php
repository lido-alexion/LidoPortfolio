<?php

namespace Tests\Unit;

use App\Services\Execution\KitePerOrderBuyingPowerPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class KitePerOrderBuyingPowerPolicyTest extends TestCase
{
    private function evidence(array $overrides = []): array
    {
        return array_merge([
            'status' => 'success', 'account_id' => 'KITE123', 'available_equity' => 1050,
            'captured_at' => '2026-11-22T10:00:00+05:30',
            'account_identity_verified' => true,
            'provider_timestamp_verified' => true,
            'order_margin_verified' => true,
        ], $overrides);
    }

    private function assess(array $evidence, string $side = 'BUY', string $type = 'LIMIT', float $qty = 10, ?float $price = 100): array
    {
        return (new KitePerOrderBuyingPowerPolicy())->assess(
            $evidence, 'KITE123', $side, $type, $qty, $price,
            CarbonImmutable::parse('2026-11-22T10:00:05+05:30'), 15, 5,
        );
    }

    public function test_fresh_funds_cover_gross_and_conservative_charge_buffer_without_double_counting(): void
    {
        $result = $this->assess($this->evidence());
        self::assertTrue($result['allowed']);
        self::assertSame(1000.0, $result['gross_required']);
        self::assertSame(50.0, $result['charges_buffer']);
        self::assertSame(1050.0, $result['required_total']);
        self::assertSame('broker_equity_buying_power_not_stox_accounting_cash', $result['cash_semantics']);
    }

    public function test_insufficient_funds_stale_error_wrong_account_and_reconnect_are_blocked(): void
    {
        self::assertSame('insufficient_broker_buying_power', $this->assess($this->evidence(['available_equity' => 1049.99]))['reason']);
        self::assertSame('broker_funds_stale', $this->assess($this->evidence(['captured_at' => '2026-11-22T09:59:00+05:30']))['reason']);
        self::assertSame('broker_funds_unavailable', $this->assess($this->evidence(['status' => 'error', 'available_equity' => null]))['reason']);
        self::assertSame('wrong_broker_account', $this->assess($this->evidence(['account_id' => 'OTHER']))['reason']);
        // Reconnection to another Kite account invalidates evidence scoped to the previous identity.
        self::assertSame('wrong_broker_account', (new KitePerOrderBuyingPowerPolicy())->assess($this->evidence(), 'RECONNECTED', 'BUY', 'LIMIT', 1, 10, CarbonImmutable::parse('2026-11-22T10:00:05+05:30'), 15, 5)['reason']);
    }

    public function test_unverified_account_timestamp_or_order_margin_never_authorizes_a_buy(): void
    {
        foreach ([
            ['account_identity_verified' => false, 'broker_account_identity_unverified'],
            ['provider_timestamp_verified' => false, 'broker_funds_timestamp_unverified'],
            ['order_margin_verified' => false, 'broker_order_margin_unverified'],
        ] as [$override, $reason]) {
            self::assertSame($reason, $this->assess($this->evidence($override))['reason']);
        }
    }

    public function test_timestamp_ttl_rejects_future_and_expired_evidence(): void
    {
        self::assertSame('broker_funds_stale', $this->assess($this->evidence([
            'captured_at' => '2026-11-22T10:00:06+05:30',
        ]))['reason']);
        self::assertSame('broker_funds_stale', $this->assess($this->evidence([
            'captured_at' => '2026-11-22T09:59:49+05:30',
        ]))['reason']);
    }

    public function test_invalid_amounts_and_charge_buffers_fail_closed(): void
    {
        $policy = new KitePerOrderBuyingPowerPolicy();
        $now = CarbonImmutable::parse('2026-11-22T10:00:05+05:30');
        foreach ([NAN, INF, -1.0] as $quantity) {
            self::assertSame('invalid_order_limits', $policy->assess($this->evidence(), 'KITE123', 'BUY', 'LIMIT', $quantity, 100, $now, 15, 5)['reason']);
        }
        self::assertSame('charge_buffer_invalid', $policy->assess($this->evidence(), 'KITE123', 'BUY', 'LIMIT', 1, 100, $now, 15, -1)['reason']);
        self::assertSame('broker_funds_unavailable', $this->assess($this->evidence(['available_equity' => NAN]))['reason']);
    }

    public function test_market_orders_and_invalid_limits_fail_closed_but_sells_do_not_use_buying_power(): void
    {
        self::assertSame('unsupported_order_type', $this->assess($this->evidence(), 'BUY', 'MARKET')['reason']);
        self::assertSame('invalid_order_limits', $this->assess($this->evidence(), 'BUY', 'LIMIT', 1, 0)['reason']);
        self::assertTrue($this->assess([], 'SELL', 'MARKET', 10, null)['allowed']);
    }

    public function test_repeated_identical_evidence_produces_same_decision_without_mutating_evidence(): void
    {
        $evidence = $this->evidence();
        $original = $evidence;
        self::assertSame($this->assess($evidence), $this->assess($evidence));
        self::assertSame($original, $evidence);
    }
}
