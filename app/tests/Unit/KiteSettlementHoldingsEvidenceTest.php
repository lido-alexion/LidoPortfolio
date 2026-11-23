<?php

namespace Tests\Unit;

use App\Services\Reconciliation\KiteSettlementHoldingsEvidence;
use PHPUnit\Framework\TestCase;

final class KiteSettlementHoldingsEvidenceTest extends TestCase
{
    private KiteSettlementHoldingsEvidence $service;

    protected function setUp(): void
    {
        $this->service = new KiteSettlementHoldingsEvidence;
    }

    public function test_same_day_split_fills_and_duplicate_broker_appearance_are_not_double_counted(): void
    {
        $result = $this->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]],
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 2], ['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 2]], [
                $this->trade(10, 't1', 'AAA', 'buy', 1, '2026-11-23', '2026-11-24'),
                $this->trade(10, 't2', 'AAA', 'buy', 2, '2026-11-23', '2026-11-24'),
            ]);
        self::assertSame('pending_settlement_exact_evidence', $result['holdings'][0]['state']);
        self::assertSame(3.0, $result['holdings'][0]['active_trade_net']);
        self::assertSame(2.0, $result['holdings'][0]['kite_reported_quantity']);
    }

    public function test_prior_day_import_survives_weekend_and_reconnect_catch_up(): void
    {
        $result = $this->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 8]],
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]], [
                $this->trade(10, 'fri-fill', 'AAA', 'buy', 3, '2026-11-20', '2026-11-23'),
            ]);
        self::assertSame('pending_settlement_exact_evidence', $result['holdings'][0]['state']);
        self::assertSame('2026-11-23', $result['holdings'][0]['settles_by']);
    }

    public function test_special_saturday_and_holiday_settlement_boundaries_are_explicit_inputs(): void
    {
        // The adapter must calculate these boundaries with TradingCalendar; Saturday sessions
        // and exchange holidays cannot be inferred from a weekday-only/calendar-day rule here.
        $result = $this->compare(10, '2026-11-22', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 4]],
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 3]], [
                $this->trade(10, 'sat-fill', 'AAA', 'buy', 1, '2026-11-21', '2026-11-24'),
            ]);
        self::assertSame('pending_settlement_exact_evidence', $result['holdings'][0]['state']);
        self::assertSame('2026-11-24', $result['holdings'][0]['settles_by']);
    }

    public function test_partial_fill_reflection_and_unknown_trade_evidence_remain_unresolved(): void
    {
        $partial = $this->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]],
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 2]], [$this->trade(10, 'b1', 'AAA', 'buy', 5, '2026-11-23', '2026-11-24')]);
        self::assertSame('unresolved', $partial['holdings'][0]['state']);
        self::assertSame('trade_evidence_does_not_fully_explain_quantity_gap', $partial['unresolved'][0]['reason']);

        $unknown = $this->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]],
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 2]], [$this->trade(10, 'b2', 'AAA', 'buy', 3, '2026-11-23', '2026-11-24')], false);
        self::assertSame('trade_evidence_partial_or_unknown', $unknown['unresolved'][0]['reason']);
    }

    public function test_sell_and_offsetting_same_day_activity_preserve_quantity_evidence_without_cash_claim(): void
    {
        $offset = $this->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 10]],
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 8]], [
                $this->trade(10, 'sell', 'AAA', 'sell', 4, '2026-11-23', '2026-11-24'),
                $this->trade(10, 'buy', 'AAA', 'buy', 6, '2026-11-23', '2026-11-24'),
            ]);
        self::assertSame('unresolved', $offset['holdings'][0]['state']);
        self::assertSame(2.0, $offset['holdings'][0]['active_trade_net']);
        self::assertSame('mixed_cnc_trade_reflection_unclassified', $offset['holdings'][0]['reason']);
        self::assertSame('unknown_non_comparable_broker_funds', $offset['cash_status']);
    }

    public function test_positions_are_overlap_evidence_and_never_added_to_holdings(): void
    {
        $result = $this->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]],
            ['holdings' => [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 2]],
                'positions' => ['complete' => true, 'net' => [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 3]]]],
            [$this->trade(10, 'p1', 'AAA', 'buy', 3, '2026-11-23', '2026-11-24')]);
        self::assertSame(2.0, $result['holdings'][0]['kite_reported_quantity']);
        self::assertSame('broker_positions_overlap_unclassified_trade_reflection', $result['unresolved'][0]['reason']);
    }

    public function test_t1_and_used_quantities_are_normalized_without_losing_raw_evidence(): void
    {
        $result = $this->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 10]],
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 10, 't1_quantity' => 3, 'used_quantity' => 3]], []);
        self::assertSame('matched', $result['holdings'][0]['state']);
        self::assertSame(10.0, $result['holdings'][0]['kite_reported_quantity']);
        self::assertSame(10.0, $result['holdings'][0]['kite_comparison_quantity']);
    }

    public function test_conflicting_duplicate_holdings_and_trade_ids_are_unresolved(): void
    {
        $result = $this->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]],
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 2], ['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 3]], [
                $this->trade(10, 'dup', 'AAA', 'buy', 3, '2026-11-23', '2026-11-24'),
                $this->trade(10, 'dup', 'AAA', 'buy', 4, '2026-11-23', '2026-11-24'),
            ]);
        self::assertSame('conflicting_duplicate_broker_holdings', $result['unresolved'][0]['reason']);

        $tradeConflict = $this->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]],
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 2]], [
                $this->trade(10, 'same-id', 'AAA', 'buy', 3, '2026-11-23', '2026-11-24'),
                $this->trade(10, 'same-id', 'AAA', 'buy', 4, '2026-11-23', '2026-11-24'),
            ]);
        self::assertSame('conflicting_duplicate_trade_id', $tradeConflict['unresolved'][0]['reason']);
    }

    public function test_identical_duplicate_trade_ids_count_once_and_expired_or_non_cnc_trades_do_not_explain_gaps(): void
    {
        $trade = $this->trade(10, 'same', 'AAA', 'buy', 3, '2026-11-22', '2026-11-24');
        $duplicate = $this->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]],
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 2]], [$trade, $trade]);
        self::assertSame('pending_settlement_exact_evidence', $duplicate['holdings'][0]['state']);
        self::assertSame(['same'], $duplicate['holdings'][0]['active_trade_ids']);

        $expired = $this->compare(10, '2026-11-25', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]],
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 2]], [$trade]);
        self::assertSame('quantity_gap_without_active_settlement_evidence', $expired['holdings'][0]['reason']);

        $otherProduct = [...$trade, 'broker_trade_id' => 'mis', 'product' => 'MIS'];
        $nonCnc = $this->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]],
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 2]], [$otherProduct]);
        self::assertSame('quantity_gap_without_active_settlement_evidence', $nonCnc['holdings'][0]['reason']);
    }

    public function test_account_isolation_and_stox_lot_aggregation(): void
    {
        $result = $this->compare(10, '2026-11-23', [
            ['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 2], ['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 3],
        ], [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]], [
            $this->trade(11, 'other-account', 'AAA', 'buy', 99, '2026-11-23', '2026-11-24'),
        ]);
        self::assertSame('matched', $result['holdings'][0]['state']);
        self::assertSame([], $result['holdings'][0]['active_trade_ids']);
        self::assertSame(10, $result['account_id']);
    }

    public function test_partial_broker_holdings_and_unknown_positions_cannot_prove_settlement(): void
    {
        $snapshot = ['holdings' => [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 2]],
            'holdings_complete' => false, 'positions' => ['complete' => false], 'account_id' => 10, 'captured_at' => '2026-11-23 12:00:00'];
        $result = $this->service->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]],
            $snapshot, [$this->trade(10, 'partial', 'AAA', 'buy', 3, '2026-11-23', '2026-11-24')], true);
        self::assertContains('broker_holdings_partial_or_unknown', array_column($result['unresolved'], 'reason'));

        $snapshot['holdings_complete'] = true;
        $result = $this->service->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]],
            $snapshot, [$this->trade(10, 'partial-position', 'AAA', 'buy', 3, '2026-11-23', '2026-11-24')], true);
        self::assertSame('broker_positions_partial_or_unknown', $result['unresolved'][0]['reason']);
    }

    public function test_malformed_broker_rows_and_positions_fail_closed(): void
    {
        $snapshot = ['holdings' => [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 2], null],
            'holdings_complete' => true, 'positions' => ['complete' => true, 'net' => 'truncated']];
        $result = $this->service->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 5]],
            $snapshot, [$this->trade(10, 'b1', 'AAA', 'buy', 3, '2026-11-23', '2026-11-24')], true);
        self::assertSame('broker_holdings_partial_or_unknown', $result['holdings'][0]['reason']);
    }

    public function test_snapshot_timestamp_account_scope_and_holdings_presence_are_required(): void
    {
        $base = ['holdings' => [], 'holdings_complete' => true, 'positions' => ['complete' => true, 'net' => [], 'day' => []]];
        self::assertSame('evidence_consistent', $this->compare(10, '2026-11-23', [], $base, [])['status']);
        foreach ([
            ['holdings_complete' => true, 'positions' => ['complete' => true]],
            ['holdings' => [], 'holdings_complete' => true, 'positions' => ['complete' => true], 'captured_at' => '2026-11-22 10:00:00', 'account_id' => 10],
            ['holdings' => [], 'holdings_complete' => true, 'positions' => ['complete' => true], 'captured_at' => '2026-11-23 10:00:00', 'account_id' => 11],
        ] as $snapshot) {
            self::assertSame('review_required', $this->compare(10, '2026-11-23', [], $snapshot, [])['status']);
        }
    }

    public function test_trade_only_instrument_is_visible_and_never_produces_global_match(): void
    {
        $result = $this->compare(10, '2026-11-23', [], [], [
            $this->trade(10, 'buy-unsettled', 'AAA', 'buy', 1, '2026-11-23', '2026-11-24'),
        ]);

        self::assertSame('review_required', $result['status']);
        self::assertCount(1, $result['holdings']);
        self::assertSame('symbol:NSE:AAA', $result['holdings'][0]['identity']);
        self::assertSame('active_trade_reflection_unproven', $result['holdings'][0]['reason']);
        self::assertSame(['buy-unsettled'], $result['holdings'][0]['active_trade_ids']);
    }

    public function test_zero_gap_active_trade_and_cross_identity_duplicate_conflicts_remain_unresolved(): void
    {
        $zeroGap = $this->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 1]],
            [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 1]], [
                $this->trade(10, 'reflected-or-not', 'AAA', 'buy', 1, '2026-11-23', '2026-11-24'),
            ]);
        self::assertSame('review_required', $zeroGap['status']);
        self::assertSame('active_trade_reflection_unproven', $zeroGap['holdings'][0]['reason']);

        $first = $this->trade(10, 'cross-identity', 'AAA', 'buy', 1, '2026-11-23', '2026-11-24');
        $second = [...$first, 'isin' => 'US0000000001', 'symbol' => 'BBB'];
        $conflict = $this->compare(10, '2026-11-23', [], [], [$first, $second]);
        self::assertSame('review_required', $conflict['status']);
        self::assertSame(['isin:US0000000001', 'symbol:NSE:AAA'], array_column($conflict['holdings'], 'identity'));
        self::assertSame(['conflicting_duplicate_trade_id', 'conflicting_duplicate_trade_id'], array_column($conflict['holdings'], 'reason'));
    }

    public function test_explicit_empty_snapshot_without_trades_is_consistent(): void
    {
        $snapshot = ['holdings' => [], 'holdings_complete' => true,
            'positions' => ['complete' => true, 'net' => [], 'day' => []],
            'account_id' => 10, 'captured_at' => '2026-11-23 12:00:00'];

        $result = $this->service->compare(10, '2026-11-23', [], $snapshot, [], true);

        self::assertSame('evidence_consistent', $result['status']);
        self::assertSame([], $result['holdings']);
        self::assertSame([], $result['unresolved']);
    }

    public function test_position_rows_without_quantity_are_incomplete(): void
    {
        $snapshot = ['holdings' => [], 'holdings_complete' => true,
            'positions' => ['complete' => true, 'net' => [['symbol' => 'AAA', 'exchange' => 'NSE']], 'day' => []],
            'account_id' => 10, 'captured_at' => '2026-11-23 12:00:00'];

        $result = $this->service->compare(10, '2026-11-23', [], $snapshot, [], true);

        self::assertSame('review_required', $result['status']);
        self::assertContains('broker_positions_partial_or_unknown', array_column($result['unresolved'], 'reason'));
    }

    public function test_invalid_or_negative_holding_position_and_fill_quantities_fail_closed(): void
    {
        foreach ([NAN, INF, -1, 'bad'] as $quantity) {
            $snapshot = ['holdings' => [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => $quantity]]];
            self::assertSame('review_required', $this->compare(10, '2026-11-23', [], $snapshot, [])['status']);
            $snapshot = ['holdings' => [], 'positions' => ['net' => [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => $quantity]]]];
            self::assertSame('review_required', $this->compare(10, '2026-11-23', [], $snapshot, [])['status']);
            $trade = $this->trade(10, 'bad-qty', 'AAA', 'buy', 1, '2026-11-23', '2026-11-24');
            $trade['quantity'] = $quantity;
            self::assertSame('review_required', $this->compare(10, '2026-11-23', [], ['holdings' => []], [$trade])['status']);
            foreach (['t1_quantity', 'used_quantity'] as $field) {
                self::assertSame('review_required', $this->compare(10, '2026-11-23', [], ['holdings' => [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 0, $field => $quantity]]], [])['status']);
            }
        }
        self::assertSame('review_required', $this->compare(10, '2026-11-23', [['symbol' => 'AAA', 'exchange' => 'NSE', 'quantity' => 'bad']], ['holdings' => []], [])['status']);
    }

    private function compare(int $account, string $asOf, array $stox, array $brokerHoldings, array $trades, bool $complete = true): array
    {
        if (array_is_list($brokerHoldings)) {
            $brokerHoldings = ['holdings' => $brokerHoldings];
        }
        $brokerHoldings['holdings_complete'] = true;
        $brokerHoldings['positions'] ??= ['complete' => true];
        $brokerHoldings['positions']['net'] ??= [];
        $brokerHoldings['positions']['day'] ??= [];
        $brokerHoldings['account_id'] ??= $account;
        $brokerHoldings['captured_at'] ??= $asOf.' 12:00:00';

        return $this->service->compare($account, $asOf, $stox, $brokerHoldings, $trades, $complete);
    }

    private function trade(int $account, string $id, string $symbol, string $side, float $qty, string $date, string $settlesBy): array
    {
        return ['profile_id' => $account, 'broker_trade_id' => $id, 'product' => 'CNC', 'symbol' => $symbol,
            'exchange' => 'NSE', 'side' => $side, 'quantity' => $qty, 'executed_at' => $date, 'settles_by' => $settlesBy];
    }
}
