<?php

namespace Tests\Unit;

use App\Services\Reconciliation\KiteSettlementEvidencePreparation;
use App\Services\Reconciliation\KiteSettlementHoldingsEvidence;
use App\Support\TradingCalendar;
use Tests\TestCase;

final class KiteSettlementEvidencePreparationTest extends TestCase
{
    private KiteSettlementEvidencePreparation $preparer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparer = new KiteSettlementEvidencePreparation;
        TradingCalendar::clearHolidayCache();
    }

    protected function tearDown(): void
    {
        TradingCalendar::clearHolidayCache();
        parent::tearDown();
    }

    public function test_maps_only_source_receipt_checked_kite_snapshot_and_historically_covered_fills(): void
    {
        $r = $this->prepare([$this->fill()], $this->coverage());
        self::assertTrue($r['broker_snapshot']['holdings_complete']);
        self::assertSame('kite-abc', $r['broker_snapshot']['broker_user_id']);
        self::assertTrue($r['trade_evidence_complete']);
        self::assertSame('2026-11-24', $r['persisted_trades'][0]['settles_by']);
    }

    public function test_profile_id_never_substitutes_for_switched_or_missing_broker_account(): void
    {
        $old = $this->fill(['broker_user_id' => 'kite-old']);
        $r = $this->prepare([$old], $this->coverage());
        self::assertSame([], $r['persisted_trades']);
        self::assertFalse($r['trade_evidence_complete']);
        $s = $this->snapshot();
        $s['broker_user_id'] = 'kite-old';
        $s['source_proof']['broker_user_id'] = 'kite-old';
        self::assertFalse($this->prepare([], $this->coverage(), $s)['broker_snapshot']['holdings_complete']);
        $legacy = $this->fill();
        unset($legacy['broker_user_id']);
        self::assertFalse($this->prepare([$legacy], $this->coverage())['trade_evidence_complete']);
    }

    public function test_wrong_provider_missing_proof_and_current_day_api_are_never_complete(): void
    {
        $s = $this->snapshot();
        $s['provider'] = 'other';
        self::assertFalse($this->prepare([], $this->coverage(), $s)['broker_snapshot']['holdings_complete']);
        $s = $this->snapshot();
        unset($s['source_proof']);
        self::assertFalse($this->prepare([], $this->coverage(), $s)['broker_snapshot']['holdings_complete']);
        self::assertFalse($this->prepare([$this->fill()], ['source' => 'kite_trades_api', 'complete' => true, 'from' => '2026-11-20', 'through' => '2026-11-23', 'active_account_broker_user_id' => 'kite-abc'])['trade_evidence_complete']);
    }

    public function test_non_cnc_imports_do_not_become_settlement_evidence(): void
    {
        $otherProduct = $this->fill(['product' => 'MIS']);
        unset($otherProduct['broker_user_id'], $otherProduct['profile_id'], $otherProduct['status']);

        $result = $this->prepare([$otherProduct], $this->coverage());

        self::assertTrue($result['trade_evidence_complete']);
        self::assertSame([], $result['persisted_trades']);
    }

    public function test_malformed_huge_and_duplicate_inputs_fail_closed(): void
    {
        $r = $this->prepare([$this->fill(['quantity' => 'NaN']), $this->fill(['quantity' => '0.5']), $this->fill(['broker_trade_id' => 'x']), $this->fill(['broker_trade_id' => 'x'])], $this->coverage());
        self::assertFalse($r['trade_evidence_complete']);
        self::assertCount(1, $r['persisted_trades']);
        $s = $this->snapshot();
        $s['holdings'][] = ['symbol' => str_repeat('X', 1000), 'exchange' => 'NSE', 'quantity' => INF];
        self::assertFalse($this->prepare([], $this->coverage(), $s)['broker_snapshot']['holdings_complete']);
        self::assertFalse($this->prepare(array_fill(0, 5001, $this->fill()), $this->coverage())['trade_evidence_complete']);
    }

    public function test_snapshot_utc_boundary_maps_to_ist_date(): void
    {
        $s = $this->snapshot('2026-11-22T18:29:59Z');
        $s['source_proof']['captured_at'] = $s['captured_at'];
        self::assertFalse($this->prepare([], $this->coverage(), $s)['broker_snapshot']['holdings_complete']);
        $s = $this->snapshot('2026-11-22T18:30:00Z');
        $s['source_proof']['captured_at'] = $s['captured_at'];
        self::assertTrue($this->prepare([], $this->coverage(), $s)['broker_snapshot']['holdings_complete']);
    }

    public function test_fill_utc_execution_boundary_maps_to_ist_session_date(): void
    {
        $before = $this->prepare([$this->fill(['executed_at' => '2026-11-22T18:29:59Z'])], $this->coverage());
        $at = $this->prepare([$this->fill(['executed_at' => '2026-11-22T18:30:00Z'])], $this->coverage());
        self::assertSame([], $before['persisted_trades']);
        self::assertFalse($before['trade_evidence_complete']);
        self::assertSame('2026-11-24', $at['persisted_trades'][0]['settles_by']);
    }

    public function test_weekend_settlement_uses_calendar_and_non_session_execution_stays_unresolved(): void
    {
        $r = $this->prepare([$this->fill(['executed_at' => '2026-11-20 15:00:00'])], $this->coverage());
        self::assertSame('2026-11-23', $r['persisted_trades'][0]['settles_by']);
        $r = $this->prepare([$this->fill(['executed_at' => '2026-11-21 12:00:00'])], $this->coverage());
        self::assertFalse($r['trade_evidence_complete']);
        self::assertSame([], $r['persisted_trades']);
    }

    public function test_missing_historical_coverage_and_incomplete_day_bucket_fail_closed(): void
    {
        self::assertFalse($this->prepare([$this->fill()], [])['trade_evidence_complete']);
        $s = $this->snapshot();
        unset($s['positions']['day']);
        self::assertFalse($this->prepare([], $this->coverage(), $s)['broker_snapshot']['holdings_complete']);
    }

    public function test_output_matches_classifier_api_and_does_not_claim_signed_broker_validation(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['holdings'][0]['quantity'] = 2;
        $snapshot['holdings'][0]['t1_quantity'] = 0;
        $r = $this->preparer->prepare(7, 'kite-abc', '2026-11-23',
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => 'ABCD12345678', 'quantity' => 4]],
            $snapshot, [$this->fill()], $this->coverage());
        self::assertSame(7, $r['broker_snapshot']['account_id']);
        self::assertSame('kite-abc', $r['broker_snapshot']['broker_user_id']);
        self::assertArrayNotHasKey('broker_validated', $r['broker_snapshot']);
        self::assertArrayNotHasKey('account_signature', $r['broker_snapshot']);

        $comparison = (new KiteSettlementHoldingsEvidence)->compare(
            7,
            '2026-11-23',
            $r['stox_holdings'],
            $r['broker_snapshot'],
            $r['persisted_trades'],
            $r['trade_evidence_complete'],
        );
        self::assertSame('pending_settlement_exact_evidence', $comparison['holdings'][0]['state']);
        self::assertSame('unknown_non_comparable_broker_funds', $comparison['cash_status']);
    }

    public function test_stox_rows_are_allowlisted_and_bad_account_arguments_are_rejected(): void
    {
        $r = $this->preparer->prepare(7, 'kite-abc', '2026-11-23', [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 4, 'untrusted_payload' => str_repeat('x', 10000),
        ]], $this->snapshot(), [], $this->coverage());
        self::assertSame([['symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 4]], $r['stox_holdings']);

        foreach ([[0, 'kite-abc'], [7, ''], [7, str_repeat('x', 129)]] as [$profileId, $brokerUserId]) {
            try {
                $this->preparer->prepare($profileId, $brokerUserId, '2026-11-23', [], null, [], []);
                self::fail('Invalid account identity should be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    private function prepare(array $imports, array $coverage, ?array $snapshot = null): array
    {
        return $this->preparer->prepare(7, 'kite-abc', '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 4]], $snapshot ?? $this->snapshot(), $imports, $coverage);
    }

    private function snapshot(string $at = '2026-11-23 16:00:00'): array
    {
        return ['provider' => 'kite', 'broker_user_id' => 'kite-abc', 'captured_at' => $at, 'source_proof' => ['provider' => 'kite', 'broker_user_id' => 'kite-abc', 'capture_id' => 'cap-1', 'captured_at' => $at, 'requested' => ['holdings', 'positions'], 'succeeded' => ['holdings', 'positions']],
            'holdings' => [['symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => 'ABCD12345678', 'quantity' => 3, 't1_quantity' => 1, 'used_quantity' => 0]], 'positions' => ['net' => [], 'day' => []]];
    }

    private function fill(array $o = []): array
    {
        return array_merge(['profile_id' => 7, 'broker_user_id' => 'kite-abc', 'broker_trade_id' => 'fill-1', 'symbol' => 'AAA', 'exchange' => 'NSE', 'stock_isin' => 'ABCD12345678', 'side' => 'buy', 'quantity' => '2.0000', 'executed_at' => '2026-11-23 10:15:00', 'product' => 'CNC', 'status' => 'matched_manual', 'transaction_id' => null, 'matched_transaction_id' => 4], $o);
    }

    private function coverage(): array
    {
        return ['source' => 'persisted_historical_imports', 'complete' => true, 'from' => '2026-11-20', 'through' => '2026-11-23', 'active_account_broker_user_id' => 'kite-abc'];
    }
}
