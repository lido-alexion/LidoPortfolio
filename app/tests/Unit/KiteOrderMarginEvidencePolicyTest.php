<?php

namespace Tests\Unit;

use App\Services\Execution\KiteOrderMarginEvidencePolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class KiteOrderMarginEvidencePolicyTest extends TestCase
{
    private function policy(): KiteOrderMarginEvidencePolicy { return new KiteOrderMarginEvidencePolicy(); }

    private function evidence(array $overrides = []): array
    {
        return array_merge([
            'status' => 'success',
            'provider_account_id' => 'KITE123',
            'account_identity_verified' => true,
            'provider_timestamp_verified' => true,
            'provider_timestamp' => '2026-11-23T10:00:04+05:30',
            'request_started_at' => '2026-11-23T10:00:03+05:30',
            'response_received_at' => '2026-11-23T10:00:05+05:30',
            'order_margin_verified' => true,
            'request_matches_order' => true,
            'positions_included' => true,
            'open_orders_included' => true,
            'pending_order_count' => 0,
            'margin_context_order_count' => 0,
            'required_cash_semantics_verified' => true,
            'required_cash' => 900,
            'charges' => 10,
            'available_cash' => 1000,
            'available_collateral' => 500,
            'candidate_order_hash' => $this->policy()->candidateFingerprint($this->order()),
        ], $overrides);
    }

    private function order(array $overrides = []): array
    {
        return array_merge([
            'symbol' => 'ABC', 'exchange' => 'NSE', 'side' => 'BUY', 'product' => 'CNC',
            'variety' => 'regular', 'order_type' => 'LIMIT', 'quantity' => 10, 'limit_price' => 100,
        ], $overrides);
    }

    private function quote(array $overrides = []): array
    {
        return array_merge([
            'price' => 100,
            'provider_timestamp' => '2026-11-23T10:00:04+05:30',
            'provider_timestamp_verified' => true,
        ], $overrides);
    }

    private function assess(array $evidence = [], array $order = [], array $quote = []): array
    {
        $candidate = $this->order($order);
        $facts = array_merge($this->evidence(), $evidence);
        if (! array_key_exists('candidate_order_hash', $evidence)) {
            $facts['candidate_order_hash'] = $this->policy()->candidateFingerprint($candidate);
        }
        return (new KiteOrderMarginEvidencePolicy())->assess(
            $facts, 'KITE123', $candidate, $this->quote($quote),
            CarbonImmutable::parse('2026-11-23T10:00:05+05:30'), 15, 1100, 2,
        );
    }

    public function test_wrong_account_missing_provider_time_and_stale_evidence_fail_closed(): void
    {
        self::assertSame('wrong_or_unverified_broker_account', $this->assess(['provider_account_id' => 'OTHER'])['reason']);
        self::assertSame('provider_timestamp_unverified', $this->assess(['provider_timestamp_verified' => false])['reason']);
        self::assertSame('evidence_timestamp_invalid', $this->assess(['provider_timestamp' => null])['reason']);
        self::assertSame('broker_evidence_stale', $this->assess(['provider_timestamp' => '2026-11-23T09:59:40+05:30'])['reason']);
        self::assertSame('broker_evidence_unavailable', $this->assess(['status' => 'error'])['reason']);
    }

    public function test_malformed_margin_result_and_unproven_open_order_context_fail_closed(): void
    {
        self::assertSame('broker_margin_values_invalid', $this->assess(['required_cash' => 'NaN'])['reason']);
        self::assertSame('open_order_reservations_unproven', $this->assess(['open_orders_included' => false])['reason']);
        self::assertSame('open_order_snapshot_incomplete', $this->assess(['pending_order_count' => 2, 'margin_context_order_count' => 1])['reason']);
        self::assertSame('order_margin_request_mismatch', $this->assess(['request_matches_order' => false])['reason']);
        self::assertSame('positions_unproven', $this->assess(['positions_included' => false])['reason']);
    }

    public function test_multiple_pending_orders_are_included_once_without_local_double_reservation(): void
    {
        $first = $this->assess(['pending_order_count' => 2, 'margin_context_order_count' => 2, 'local_reserved_amount' => 700]);
        $second = $this->assess(['pending_order_count' => 2, 'margin_context_order_count' => 2, 'local_reserved_amount' => 700]);
        self::assertTrue($first['allowed']);
        self::assertSame(910.0, $first['required_total']);
        self::assertTrue($first['existing_open_orders_included']);
        self::assertFalse($first['local_reservations_subtracted']);
        self::assertSame($first, $second);
    }

    public function test_double_evaluation_is_deterministic_and_does_not_mutate_evidence(): void
    {
        $evidence = $this->evidence(['pending_order_count' => 2, 'margin_context_order_count' => 2]);
        $original = $evidence;
        $policy = new KiteOrderMarginEvidencePolicy();
        $now = CarbonImmutable::parse('2026-11-23T10:00:05+05:30');
        $first = $policy->assess($evidence, 'KITE123', $this->order(), $this->quote(), $now, 15, 1100, 2);
        $second = $policy->assess($evidence, 'KITE123', $this->order(), $this->quote(), $now, 15, 1100, 2);
        self::assertSame($first, $second);
        self::assertSame($original, $evidence);
    }

    public function test_market_orders_and_unbounded_or_overexposed_limits_fail_closed(): void
    {
        self::assertSame('unbounded_order_type', $this->assess([], ['order_type' => 'MARKET'])['reason']);
        self::assertSame('price_or_exposure_bound_exceeded', $this->assess([], ['limit_price' => 103])['reason']);
        self::assertSame('price_or_exposure_bound_exceeded', $this->assess([], ['quantity' => 12])['reason']);
        self::assertSame('quote_timestamp_unverified', $this->assess([], [], ['provider_timestamp_verified' => false])['reason']);
    }

    public function test_cash_and_collateral_remain_separate_and_short_cash_blocks(): void
    {
        $allowed = $this->assess();
        self::assertTrue($allowed['allowed']);
        self::assertSame(1000.0, $allowed['available_cash']);
        self::assertSame(500.0, $allowed['available_collateral']);
        self::assertFalse($this->assess(['available_cash' => 900])['allowed']);
        self::assertSame('insufficient_broker_cash', $this->assess(['available_cash' => 900])['reason']);
    }

    public function test_exact_candidate_binding_and_required_order_context_fail_closed(): void
    {
        self::assertSame('order_margin_request_mismatch', $this->assess(
            ['candidate_order_hash' => str_repeat('0', 64)],
        )['reason']);
        self::assertSame('order_context_invalid', $this->assess([], ['symbol' => ''])['reason']);
        self::assertSame('order_context_invalid', $this->assess([], ['exchange' => null])['reason']);
    }

    public function test_numeric_overflow_and_invalid_policy_bounds_fail_closed(): void
    {
        self::assertSame('price_or_exposure_bound_exceeded', $this->assess(
            [], ['quantity' => 1.0e308, 'limit_price' => 1.0e308],
        )['reason']);
        self::assertSame('broker_margin_values_invalid', $this->assess(
            ['required_cash' => PHP_FLOAT_MAX, 'charges' => PHP_FLOAT_MAX],
        )['reason']);
        self::assertSame('price_bound_unavailable', $this->policy()->assess(
            $this->evidence(), 'KITE123', $this->order(), $this->quote(),
            CarbonImmutable::parse('2026-11-23T10:00:05+05:30'), 15, 1100, INF,
        )['reason']);
    }

    public function test_future_provider_or_quote_timestamps_fail_closed(): void
    {
        self::assertSame('broker_evidence_stale', $this->assess([
            'provider_timestamp' => '2026-11-23T10:00:06+05:30',
        ])['reason']);
        self::assertSame('quote_stale', $this->assess([], [], [
            'provider_timestamp' => '2026-11-23T10:00:06+05:30',
        ])['reason']);
    }

    public function test_local_capture_times_are_separate_and_ordered(): void
    {
        self::assertSame('evidence_timestamp_invalid', $this->assess([
            'request_started_at' => null,
        ])['reason']);
        self::assertSame('broker_evidence_stale', $this->assess([
            'request_started_at' => '2026-11-23T10:00:06+05:30',
        ])['reason']);
    }
}
