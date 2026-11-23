<?php

namespace Tests\Unit;

use App\Services\Reconciliation\KiteSettlementDiagnosticReport;
use Tests\TestCase;

final class KiteSettlementDiagnosticReportTest extends TestCase
{
    public function test_report_scopes_by_profile_account_and_day_without_claiming_settlement_match(): void
    {
        $report = (new KiteSettlementDiagnosticReport)->build(
            7, 'kite-a', '2026-11-23', ['profile_id' => 7, 'broker_account_id' => 'kite-a', 'verified' => true],
            portfolioSnapshots: [
                ['profile_id' => 7, 'snapshot_date' => '2026-11-23'],
                ['profile_id' => 8, 'snapshot_date' => '2026-11-23'],
                ['profile_id' => 7, 'snapshot_date' => '2026-11-22'],
            ],
            tradeImports: [
                ['profile_id' => 7, 'broker_account_id' => 'kite-a', 'executed_at' => '2026-11-23 10:00:00'],
                ['profile_id' => 8, 'executed_at' => '2026-11-23 10:00:00'],
                ['profile_id' => 7, 'executed_at' => '2026-11-23 10:00:00'],
                ['profile_id' => 7, 'broker_account_id' => 'kite-b', 'executed_at' => '2026-11-23 10:00:00'],
            ],
            syncRuns: [['profile_id' => 7, 'trading_date' => '2026-11-23', 'status' => 'completed', 'review_count' => 0]],
            fundsSnapshots: [
                ['profile_id' => 7, 'broker_account_id' => 'kite-a', 'trading_date' => '2026-11-23', 'freshness' => 'fresh', 'payout_semantics_verified' => false],
                ['profile_id' => 7, 'broker_account_id' => 'kite-b', 'trading_date' => '2026-11-23', 'freshness' => 'fresh'],
            ],
        );

        self::assertSame(1, $report['evidence']['portfolio_snapshots']['count']);
        self::assertSame(1, $report['evidence']['cnc_imports']['count']);
        self::assertSame(1, $report['evidence']['cnc_imports']['legacy_unbound_count']);
        self::assertSame(1, $report['evidence']['cnc_imports']['foreign_account_rows_excluded']);
        self::assertSame(1, $report['evidence']['funds_snapshots']['count']);
        self::assertSame(1, $report['evidence']['foreign_account_funds_excluded']);
        self::assertFalse($report['evidence']['independent_settlement_data_available']);
        self::assertNull($report['holdings_diagnostic']);
        self::assertStringContainsString('No actual brokerage settlement match', $report['claim']);
        self::assertContains('broker_payout_semantics_unverified', $report['diagnostics']);
    }

    public function test_unverified_account_binding_never_runs_capture_receipt_mapper(): void
    {
        $snapshot = ['provider' => 'kite', 'broker_user_id' => 'kite-a', 'captured_at' => '2026-11-23 15:00:00',
            'source_proof' => ['provider' => 'kite', 'broker_user_id' => 'kite-a', 'capture_id' => 'capture-1',
                'captured_at' => '2026-11-23 15:00:00', 'requested' => ['holdings', 'positions'], 'succeeded' => ['holdings', 'positions']],
            'holdings' => [], 'positions' => ['net' => [], 'day' => []]];

        $report = (new KiteSettlementDiagnosticReport)->build(7, 'kite-a', '2026-11-23', null, brokerSnapshot: $snapshot);

        self::assertSame('unverified', $report['account_binding']);
        self::assertNull($report['prepared_evidence']);
        self::assertContains('capture_receipt_not_used_without_verified_profile_account_binding', $report['diagnostics']);
        self::assertContains('no_independent_broker_statement_or_settlement_confirmation', $report['diagnostics']);
    }

    public function test_verified_binding_still_runs_existing_fail_closed_components_without_claiming_match(): void
    {
        $capturedAt = '2026-11-23 15:00:00';
        $snapshot = ['provider' => 'kite', 'broker_user_id' => 'kite-a', 'captured_at' => $capturedAt,
            'source_proof' => ['provider' => 'kite', 'broker_user_id' => 'kite-a', 'capture_id' => 'capture-1',
                'captured_at' => $capturedAt, 'requested' => ['holdings', 'positions'], 'succeeded' => ['holdings', 'positions']],
            'holdings' => [], 'positions' => ['net' => [], 'day' => []]];

        $report = (new KiteSettlementDiagnosticReport)->build(7, 'kite-a', '2026-11-23',
            ['profile_id' => 7, 'broker_account_id' => 'kite-a', 'verified' => true],
            stoxHoldings: [], brokerSnapshot: $snapshot, stoxHoldingsProfileId: 7);

        self::assertNotNull($report['prepared_evidence']);
        self::assertSame('review_required', $report['holdings_diagnostic']['status']);
        self::assertFalse($report['evidence']['independent_settlement_data_available']);
        self::assertSame('diagnostic_only_review_required', $report['status']);
    }

    public function test_invalid_scope_and_unbounded_evidence_are_rejected(): void
    {
        $service = new KiteSettlementDiagnosticReport;
        try {
            $service->build(0, 'kite-a', '2026-11-23', null);
            self::fail('Invalid profile should be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(\InvalidArgumentException::class);
        $service->build(7, 'kite-a', '2026-11-23', null, tradeImports: array_fill(0, 5001, []));
    }

    public function test_profile_and_day_matching_are_exact_and_timestamp_day_is_ist(): void
    {
        $report = (new KiteSettlementDiagnosticReport)->build(7, 'kite-a', '2026-11-23', null,
            portfolioSnapshots: [
                ['profile_id' => '7garbage', 'snapshot_date' => '2026-11-23'],
                ['profile_id' => 7, 'snapshot_date' => '2026-11-23T00:00:00Z'],
            ],
            tradeImports: [
                ['profile_id' => 7, 'broker_account_id' => 'kite-a', 'executed_at' => '2026-11-22T20:00:00Z'],
                ['profile_id' => 7, 'executed_at' => '2026-11-23 25:61:61'],
                ['profile_id' => 7, 'broker_account_id' => 'kite-b', 'executed_at' => '2026-11-22T20:00:00Z'],
                ['profile_id' => 7, 'executed_at' => '2026-11-22T20:00:00Z'],
            ]);

        self::assertSame(0, $report['evidence']['portfolio_snapshots']['count']);
        self::assertSame(1, $report['evidence']['cnc_imports']['count']);
        self::assertSame(1, $report['evidence']['cnc_imports']['legacy_unbound_count']);
        self::assertSame(1, $report['evidence']['cnc_imports']['foreign_account_rows_excluded']);
        self::assertContains('profile_level_evidence_contains_malformed_or_unverified_rows', $report['diagnostics']);
    }

    public function test_latest_failed_sync_run_prevents_false_complete_from_older_run(): void
    {
        $report = (new KiteSettlementDiagnosticReport)->build(7, 'kite-a', '2026-11-23', null,
            syncRuns: [
                ['profile_id' => 7, 'trading_date' => '2026-11-23', 'status' => 'completed', 'review_count' => 0,
                    'started_at' => '2026-11-23 15:00:00', 'completed_at' => '2026-11-23 15:01:00'],
                ['profile_id' => 7, 'trading_date' => '2026-11-23', 'status' => 'sync_failed', 'review_count' => 0,
                    'started_at' => '2026-11-23 15:02:00', 'completed_at' => '2026-11-23 15:03:00'],
            ]);

        self::assertFalse($report['evidence']['sync_runs']['complete']);
        self::assertContains('cnc_sync_incomplete_or_needs_review', $report['diagnostics']);
    }

    public function test_unverified_stox_source_and_legacy_imports_do_not_enter_holdings_comparison(): void
    {
        $snapshot = ['provider' => 'kite', 'broker_user_id' => 'kite-a', 'captured_at' => '2026-11-23 15:00:00',
            'source_proof' => ['provider' => 'kite', 'broker_user_id' => 'kite-a', 'capture_id' => 'capture-1',
                'captured_at' => '2026-11-23 15:00:00', 'requested' => ['holdings', 'positions'], 'succeeded' => ['holdings', 'positions']],
            'holdings' => [], 'positions' => ['net' => [], 'day' => []], 'private_raw_receipt' => 'DO_NOT_RETURN'];
        $binding = ['profile_id' => 7, 'broker_account_id' => 'kite-a', 'verified' => true];
        $report = (new KiteSettlementDiagnosticReport)->build(7, 'kite-a', '2026-11-23', $binding,
            stoxHoldings: [], stoxHoldingsProfileId: null, brokerSnapshot: $snapshot,
            tradeImports: [['profile_id' => 7, 'broker_trade_id' => '1', 'executed_at' => '2026-11-23 10:00:00']]);

        self::assertNull($report['holdings_diagnostic']);
        self::assertContains('true_profile_holdings_source_missing', $report['diagnostics']);
        self::assertContains('legacy_or_unbound_cnc_imports_not_account_evidence', $report['diagnostics']);
        self::assertSame(0, $report['evidence']['cnc_imports']['count']);
        self::assertSame(1, $report['evidence']['cnc_imports']['legacy_unbound_count']);
        self::assertStringNotContainsString('DO_NOT_RETURN', json_encode($report));
        self::assertSame('diagnostic_only_review_required', $report['status']);
    }

    public function test_future_account_scoped_trade_imports_are_checked_against_requested_account(): void
    {
        $report = (new KiteSettlementDiagnosticReport)->build(7, 'kite-a', '2026-11-23', null,
            tradeImports: [['profile_id' => 7, 'broker_account_id' => 'kite-b', 'executed_at' => '2026-11-23 10:00:00']]);

        self::assertContains('other_broker_account_cnc_imports_excluded', $report['diagnostics']);
        self::assertSame(0, $report['evidence']['cnc_imports']['count']);
        self::assertSame(1, $report['evidence']['cnc_imports']['foreign_account_rows_excluded']);
    }
}
