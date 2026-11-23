<?php

namespace Tests\Feature;

use App\Models\KiteCncTradeImport;
use App\Models\KiteCncTradeSyncRun;
use App\Models\CalendarEvent;
use App\Models\BrokerConnection;
use App\Models\PortfolioProfile;
use App\Models\PortfolioReconciliationRun;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Broker\FakeBrokerGateway;
use App\Services\Reconciliation\KiteCncTradeSyncService;
use App\Services\Reconciliation\PortfolioReconciliationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KiteCncTradeSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-20 16:10:00', 'Asia/Kolkata'));
        \App\Support\TradingCalendar::clearHolidayCache();
        app(FakeBrokerGateway::class)->reset();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        \App\Support\TradingCalendar::clearHolidayCache();
        parent::tearDown();
    }

    public function test_imports_only_cnc_fills_with_ids_and_engine_fees_idempotently(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [
            $this->fill(98701, 'B-17', 'BUY', 2, 101.25),
            $this->fill(98702, 'B-17', 'BUY', 3, 101.50),
            [...$this->fill(98703, 'M-1', 'BUY', 100, 1), 'product' => 'MIS'],
            [...$this->fill(98704, 'F-1', 'BUY', 1, 100), 'exchange' => 'NFO'],
            [...$this->fill(98705, 'M-2', 'SELL', 1, 100), 'product' => 'MTF'],
        ];

        $first = app(KiteCncTradeSyncService::class)->sync($profile);
        $this->assertSame(2, $first['imported']);
        $this->assertSame(0, $first['review']);
        $this->assertSame(2, $profile->transactions()->count());
        $this->assertSame(5.0, (float) $profile->transactions()->sum('quantity'));
        $this->assertGreaterThan(0, (float) $profile->transactions()->first()->fees);
        $this->assertDatabaseHas('kite_cnc_trade_imports', ['broker_trade_id' => '98701', 'broker_order_id' => 'B-17', 'status' => 'imported']);

        $fake->tradeRows = [$this->fill(98701, 'B-17', 'BUY', 99, 1), $this->fill(98702, 'B-17', 'BUY', 99, 1)];
        $second = app(KiteCncTradeSyncService::class)->sync($profile);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(2, $profile->transactions()->count());
        $this->assertSame(2, KiteCncTradeImport::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(2.0, (float) KiteCncTradeImport::query()->where('broker_trade_id', '98701')->value('quantity'));
        $this->assertSame(101.25, (float) KiteCncTradeImport::query()->where('broker_trade_id', '98701')->value('price'));
        $this->assertArrayNotHasKey('access_token', KiteCncTradeImport::query()->where('broker_trade_id', '98701')->firstOrFail()->broker_evidence);
    }

    public function test_new_execution_never_reuses_an_auto_imported_transaction_as_a_match(): void
    {
        [$profile] = $this->portfolioAndStock();
        $first = $this->fill(98790, 'ORD-90', 'BUY', 2, 100);
        app(FakeBrokerGateway::class)->tradeRows = [$first];
        app(KiteCncTradeSyncService::class)->sync($profile);

        app(FakeBrokerGateway::class)->tradeRows = [$first, $this->fill(98791, 'ORD-91', 'BUY', 2, 100)];
        $result = app(KiteCncTradeSyncService::class)->sync($profile);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['matched']);
        $this->assertSame(2, $profile->transactions()->count());
        $this->assertSame(0, KiteCncTradeImport::query()->where('status', 'matched_existing')->count());
    }

    public function test_late_aggregate_manual_transaction_keeps_new_fills_in_review(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $first = $this->fill(98792, 'ORD-92', 'BUY', 2, 100);
        app(FakeBrokerGateway::class)->tradeRows = [$first];
        app(KiteCncTradeSyncService::class)->sync($profile);
        Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 4, 'price' => 100, 'fees' => 1, 'transaction_date' => '2026-11-20', 'source' => Transaction::SOURCE_MANUAL]);

        app(FakeBrokerGateway::class)->tradeRows = [$first, $this->fill(98793, 'ORD-93', 'BUY', 2, 100)];
        $result = app(KiteCncTradeSyncService::class)->sync($profile);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['review']);
        $this->assertSame(2, $profile->transactions()->count());
        $this->assertDatabaseHas('kite_cnc_trade_imports', ['broker_trade_id' => '98793', 'status' => 'manual_match_review']);
    }

    public function test_malformed_cnc_candidates_are_persisted_sanitized_and_replay_idempotently(): void
    {
        [$profile] = $this->portfolioAndStock();
        $candidate = [...$this->fill(99801, 'ORD-1', 'BUY', 2, 100), 'exchange' => null, 'exchange_timestamp' => null,
            'access_token' => 'must-not-persist', 'api_key' => 'also-secret'];
        app(FakeBrokerGateway::class)->tradeRows = [$candidate];

        $first = app(KiteCncTradeSyncService::class)->sync($profile);
        $second = app(KiteCncTradeSyncService::class)->sync($profile);

        $this->assertSame(1, $first['review']);
        $this->assertSame(1, $second['review']);
        $this->assertSame(1, KiteCncTradeImport::query()->where('profile_id', $profile->id)->count());
        $row = KiteCncTradeImport::query()->where('profile_id', $profile->id)->firstOrFail();
        $this->assertSame('unresolved_candidate', $row->status);
        $this->assertSame('malformed_candidate', $row->evidence_kind);
        $this->assertArrayNotHasKey('access_token', $row->broker_evidence);
        $this->assertArrayNotHasKey('api_key', $row->broker_evidence);
        $this->assertSame(0, $profile->transactions()->count());
    }

    public function test_duplicate_broker_trade_ids_are_persisted_as_one_ambiguous_review_item(): void
    {
        [$profile] = $this->portfolioAndStock();
        app(FakeBrokerGateway::class)->tradeRows = [
            $this->fill(99811, 'ORD-11', 'BUY', 2, 100),
            $this->fill(99811, 'ORD-12', 'BUY', 3, 101),
        ];
        $result = app(KiteCncTradeSyncService::class)->sync($profile);
        $item = KiteCncTradeImport::query()->where('profile_id', $profile->id)->firstOrFail();
        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['review']);
        $this->assertSame('unresolved_candidate', $item->status);
        $this->assertSame(2, $item->broker_evidence['ambiguity_count']);
        $this->assertCount(2, $item->broker_evidence['candidate_variants']);
        $this->assertSame(0, $profile->transactions()->count());
    }

    public function test_ambiguous_duplicate_evidence_cannot_be_manually_matched(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $transaction = Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 2, 'price' => 100, 'fees' => 1, 'transaction_date' => '2026-11-20', 'source' => Transaction::SOURCE_MANUAL]);
        app(FakeBrokerGateway::class)->tradeRows = [
            $this->fill(99814, 'ORD-14', 'BUY', 2, 100),
            $this->fill(99814, 'ORD-15', 'BUY', 2, 100),
        ];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $item = KiteCncTradeImport::query()->where('profile_id', $profile->id)->firstOrFail();
        $item->forceFill(['evidence_kind' => 'execution', 'status' => 'manual_match_review', 'stock_id' => $stock->id,
            'executed_at' => '2026-11-20 15:20:00'])->save();
        $user = User::query()->findOrFail($profile->user_id);

        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson("/api/reconciliation/trades/review/{$item->id}")->assertOk()->assertJsonCount(0, 'data.exact_match_candidates');
        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson("/api/reconciliation/trades/review/{$item->id}/resolve", [
                'action' => 'match_existing', 'transaction_id' => $transaction->id,
                'idempotency_key' => 'ambiguous-match-key-99814', 'evidence_key' => $item->fresh()->review_version,
            ])->assertStatus(422);
        $this->assertDatabaseCount('kite_cnc_trade_review_audits', 0);
    }

    public function test_identical_candidates_without_trade_ids_keep_duplicate_count_and_variants(): void
    {
        [$profile] = $this->portfolioAndStock();
        $candidate = [...$this->fill(99813, 'ORD-13', 'BUY', 2, 100), 'trade_id' => null, 'exchange_timestamp' => null];
        app(FakeBrokerGateway::class)->tradeRows = [$candidate, $candidate];

        $result = app(KiteCncTradeSyncService::class)->sync($profile);
        $item = KiteCncTradeImport::query()->where('profile_id', $profile->id)->firstOrFail();

        $this->assertSame(1, $result['review']);
        $this->assertSame(2, $item->broker_evidence['ambiguity_count']);
        $this->assertCount(2, $item->broker_evidence['candidate_variants']);
        $this->assertSame(0, $profile->transactions()->count());
    }

    public function test_unidentified_equity_product_is_reviewed_without_import_and_account_switch_is_blocked(): void
    {
        [$profile] = $this->portfolioAndStock();
        app(FakeBrokerGateway::class)->tradeRows = [[...$this->fill(99812, 'ORD-12', 'BUY', 2, 100), 'product' => null]];
        $result = app(KiteCncTradeSyncService::class)->sync($profile);
        $item = KiteCncTradeImport::query()->where('profile_id', $profile->id)->firstOrFail();
        $this->assertSame(0, $result['imported']);
        $this->assertSame('unresolved_candidate', $item->status);
        $this->assertSame(0, $profile->transactions()->count());

        BrokerConnection::query()->where('user_id', $profile->user_id)->update(['broker_user_id' => 'DIFFERENT']);
        try {
            app(KiteCncTradeSyncService::class)->sync($profile);
            $this->fail('A changed Kite account must not reuse the portfolio import history.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('different Kite account', $exception->getMessage());
        }
    }

    public function test_legacy_unknown_account_rows_stay_unresolved_without_blocking_unrelated_sync(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $legacyRun = KiteCncTradeSyncRun::query()->create(['profile_id' => $profile->id, 'user_id' => $profile->user_id,
            'trading_date' => '2026-11-19', 'trigger' => 'scheduled', 'status' => 'needs_review',
            'started_at' => '2026-11-19 16:00:00']);
        $legacy = KiteCncTradeImport::query()->create(['profile_id' => $profile->id, 'sync_run_id' => $legacyRun->id,
            'stock_id' => null, 'broker_trade_id' => '98799', 'symbol' => 'UNKNOWN', 'exchange' => 'UNKNOWN',
            'side' => 'unknown', 'quantity' => 0, 'price' => 0, 'product' => 'UNKNOWN',
            'status' => 'unresolved_candidate', 'evidence_kind' => 'legacy_unbound',
            'evidence_key' => hash('sha256', 'trade:98799'), 'broker_evidence' => []]);
        app(FakeBrokerGateway::class)->tradeRows = [
            $this->fill(98799, 'ORD-99', 'BUY', 2, 100),
            $this->fill(98800, 'ORD-100', 'BUY', 1, 50),
        ];

        $first = app(KiteCncTradeSyncService::class)->sync($profile);
        $second = app(KiteCncTradeSyncService::class)->sync($profile);

        $this->assertSame(1, $first['imported']);
        $this->assertSame(1, $first['review']);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['review']);
        $this->assertDatabaseHas('kite_cnc_trade_imports', ['id' => $legacy->id, 'broker_account_id' => null, 'status' => 'unresolved_candidate']);
        $this->assertDatabaseHas('kite_cnc_trade_imports', ['broker_account_id' => BrokerConnection::query()->where('user_id', $profile->user_id)->value('broker_user_id'), 'evidence_kind' => 'account_conflict', 'status' => 'unresolved_candidate']);
        $this->assertSame(1, $profile->transactions()->count());
        $this->assertSame(3, KiteCncTradeImport::query()->where('profile_id', $profile->id)->count());
    }

    public function test_review_acknowledgment_is_idempotent_audited_and_keeps_fill_unresolved(): void
    {
        [$profile] = $this->portfolioAndStock();
        app(FakeBrokerGateway::class)->tradeRows = [[...$this->fill(99802, 'ORD-2', 'BUY', 2, 100), 'exchange_timestamp' => null]];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $item = KiteCncTradeImport::query()->where('profile_id', $profile->id)->firstOrFail();
        $user = User::query()->findOrFail($profile->user_id);
        $payload = ['action' => 'acknowledge_unresolved', 'idempotency_key' => 'operator-review-key-99802',
            'evidence_key' => $item->review_version];

        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson("/api/reconciliation/trades/review/{$item->id}/resolve", $payload)->assertOk()
            ->assertJsonPath('data.status', 'acknowledged_unresolved');

        // A MySQL JSON column can return object keys in a different order
        // from the provider field order used to build the replayed evidence.
        // This content-preserving reorder must not reopen the acknowledgement.
        $persistedEvidence = $item->fresh()->broker_evidence;
        ksort($persistedEvidence, SORT_STRING);
        $item->forceFill(['broker_evidence' => $persistedEvidence])->save();
        app(KiteCncTradeSyncService::class)->sync($profile);
        $this->assertDatabaseHas('kite_cnc_trade_imports', ['id' => $item->id, 'status' => 'acknowledged_unresolved']);
        $this->assertDatabaseHas('kite_cnc_trade_imports', ['id' => $item->id, 'transaction_id' => null, 'matched_transaction_id' => null]);
        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson("/api/reconciliation/trades/review/{$item->id}/resolve", $payload)->assertOk()
            ->assertJsonPath('data.status', 'acknowledged_unresolved');
        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson("/api/reconciliation/trades/review/{$item->id}/resolve", [...$payload, 'idempotency_key' => 'second-review-key-99802'])
            ->assertStatus(409);
        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson("/api/reconciliation/trades/review/{$item->id}/resolve", $payload)->assertOk();

        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson("/api/reconciliation/trades/review/{$item->id}/resolve", [
                ...$payload, 'evidence_key' => hash('sha256', 'stale-idempotency-evidence'),
            ])->assertStatus(409);

        $this->assertDatabaseCount('kite_cnc_trade_review_audits', 1);
        $this->assertDatabaseHas('kite_cnc_trade_review_audits', [
            'trade_import_id' => $item->id,
            'evidence_fingerprint' => $payload['evidence_key'],
        ]);
        $this->assertSame(0, $profile->transactions()->count());
        $this->assertDatabaseHas('kite_cnc_trade_imports', ['id' => $item->id, 'status' => 'acknowledged_unresolved']);
    }

    public function test_review_api_returns_sanitized_evidence_and_only_links_exact_existing_transactions(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $first = Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 2, 'price' => 100, 'fees' => 1, 'transaction_date' => '2026-11-20', 'source' => Transaction::SOURCE_MANUAL]);
        Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 2, 'price' => 100, 'fees' => 1, 'transaction_date' => '2026-11-20', 'source' => Transaction::SOURCE_MANUAL]);
        $fill = [...$this->fill(99809, 'ORD-9', 'BUY', 2, 100), 'access_token' => 'must-not-leak', 'user_email' => 'private@example.test'];
        app(FakeBrokerGateway::class)->tradeRows = [$fill];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $item = KiteCncTradeImport::query()->where('profile_id', $profile->id)->firstOrFail();
        $user = User::query()->findOrFail($profile->user_id);

        $response = $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson("/api/reconciliation/trades/review/{$item->id}")->assertOk();
        $response->assertJsonCount(2, 'data.exact_match_candidates')
            ->assertJsonMissingPath('data.broker_evidence.access_token')
            ->assertJsonMissingPath('data.evidence.access_token')
            ->assertJsonMissingPath('data.evidence.user_email');
        $evidenceVersion = $response->json('data.review_version');
        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson("/api/reconciliation/trades/review/{$item->id}/resolve", [
                'action' => 'match_existing', 'transaction_id' => $first->id,
                'idempotency_key' => 'exact-match-review-99809', 'evidence_key' => $evidenceVersion,
            ])->assertOk()->assertJsonPath('data.matched_transaction_id', $first->id);
        $this->assertSame(2, $profile->transactions()->count());
        $this->assertDatabaseCount('kite_cnc_trade_review_audits', 1);
    }

    public function test_provider_source_limit_fails_closed_with_safe_summary(): void
    {
        [$profile] = $this->portfolioAndStock();
        app(FakeBrokerGateway::class)->tradeRows = array_fill(0, 5001, [
            'trade_id' => '99899', 'product' => 'CNC', 'exchange' => 'NSE',
            'api_key' => 'private-secret-must-not-be-stored',
        ]);

        try {
            app(KiteCncTradeSyncService::class)->sync($profile);
            $this->fail('An oversized provider response must fail closed.');
        } catch (\RuntimeException $error) {
            $this->assertStringNotContainsString('private-secret', $error->getMessage());
        }

        $this->assertSame(0, $profile->transactions()->count());
        $run = KiteCncTradeSyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame('sync_failed', $run->status);
        $this->assertStringNotContainsString('private-secret', (string) $run->failure);
    }

    public function test_review_migration_preserves_historical_transaction_notes_and_legacy_evidence(): void
    {
        [$profile] = $this->portfolioAndStock();
        app(FakeBrokerGateway::class)->tradeRows = [$this->fill(99898, 'ORD-98', 'BUY', 2, 100)];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $row = KiteCncTradeImport::query()->where('broker_trade_id', '99898')->firstOrFail();
        $transaction = Transaction::query()->findOrFail($row->transaction_id);
        $historicalNote = 'Kite CNC execution 99898 (order ORD-98)';
        $legacyEvidence = ['trade_id' => '99898', 'access_token' => 'legacy-sensitive-value'];
        $transaction->forceFill(['notes' => $historicalNote])->save();
        DB::table('kite_cnc_trade_imports')->where('id', $row->id)->update([
            'broker_evidence' => json_encode($legacyEvidence),
        ]);

        $migration = require database_path('migrations/2026_11_23_000001_add_kite_cnc_manual_review.php');
        $migration->down();
        $migration->up();

        $this->assertSame($historicalNote, $transaction->fresh()->notes);
        $this->assertSame($legacyEvidence, $row->fresh()->broker_evidence);
        $this->assertSame('legacy_unbound', $row->fresh()->evidence_kind);
        $this->assertSame('unresolved_candidate', $row->fresh()->status);
    }

    public function test_review_payload_caps_recursive_legacy_candidate_variants(): void
    {
        [$profile] = $this->portfolioAndStock();
        app(FakeBrokerGateway::class)->tradeRows = [[...$this->fill(99897, 'ORD-97', 'BUY', 2, 100), 'exchange_timestamp' => null]];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $item = KiteCncTradeImport::query()->where('broker_trade_id', '99897')->firstOrFail();
        $nested = ['trade_id' => '99897'];
        for ($i = 0; $i < 20; $i++) $nested = ['candidate_variants' => [$nested]];
        $item->forceFill(['broker_evidence' => $nested])->save();
        $user = User::query()->findOrFail($profile->user_id);

        $response = $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson("/api/reconciliation/trades/review/{$item->id}")->assertOk();
        $evidence = $response->json('data.evidence');
        for ($depth = 0; $depth < 3; $depth++) {
            $this->assertArrayHasKey('candidate_variants', $evidence);
            $evidence = $evidence['candidate_variants'][0];
        }
        $this->assertSame([], $evidence);
    }

    public function test_review_rejects_stale_evidence_and_other_profiles_cannot_read_row(): void
    {
        [$profile] = $this->portfolioAndStock();
        app(FakeBrokerGateway::class)->tradeRows = [[...$this->fill(99810, 'ORD-10', 'BUY', 2, 100), 'exchange_timestamp' => null]];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $item = KiteCncTradeImport::query()->where('profile_id', $profile->id)->firstOrFail();
        $user = User::query()->findOrFail($profile->user_id);
        $other = PortfolioProfile::query()->create(['user_id' => $user->id, 'name' => 'Other live', 'portfolio_type' => 'live']);
        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $other->id)
            ->getJson("/api/reconciliation/trades/review/{$item->id}")->assertNotFound();
        BrokerConnection::query()->where('user_id', $profile->user_id)->update(['broker_user_id' => 'DIFFERENT']);
        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson("/api/reconciliation/trades/review/{$item->id}")->assertNotFound();
        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson("/api/reconciliation/trades/review/{$item->id}/resolve", [
                'action' => 'acknowledge_unresolved', 'idempotency_key' => 'switched-account-review-99810',
                'evidence_key' => $item->review_version,
            ])->assertNotFound();
        BrokerConnection::query()->where('user_id', $profile->user_id)->update(['broker_user_id' => $item->broker_account_id]);
        $oldVersion = $item->review_version;
        $item->forceFill(['broker_evidence' => [...$item->broker_evidence, 'exchange_timestamp' => '2026-11-20 15:21:00']])->save();
        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson("/api/reconciliation/trades/review/{$item->id}/resolve", [
                'action' => 'acknowledge_unresolved', 'idempotency_key' => 'stale-review-key-99810', 'evidence_key' => $oldVersion,
            ])->assertStatus(409);
        $this->assertDatabaseCount('kite_cnc_trade_review_audits', 0);
    }

    public function test_matches_aggregate_manual_transaction_and_flags_ambiguous_manual_rows(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $manual = Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 5, 'price' => 101.4, 'fees' => 2, 'transaction_date' => '2026-11-20', 'source' => Transaction::SOURCE_MANUAL]);
        app(FakeBrokerGateway::class)->tradeRows = [
            $this->fill(98711, 'B-19', 'BUY', 2, 101.25),
            $this->fill(98712, 'B-19', 'BUY', 3, 101.50),
        ];
        $result = app(KiteCncTradeSyncService::class)->sync($profile);
        $this->assertSame(0, $result['imported']);
        $this->assertSame(2, $result['matched']);
        $this->assertSame(1, $profile->transactions()->count());
        $this->assertSame(2, KiteCncTradeImport::query()->where('status', 'matched_manual')->count());
        $this->assertSame($manual->id, (int) KiteCncTradeImport::query()->first()->matched_transaction_id);
    }

    public function test_manual_matching_and_review_resolution_require_execution_exchange(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'execution_exchange' => 'BSE',
            'type' => 'buy', 'quantity' => 2, 'price' => 100, 'fees' => 1, 'transaction_date' => '2026-11-20',
            'source' => Transaction::SOURCE_MANUAL]);
        foreach ([1, 2] as $_) {
            Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'execution_exchange' => 'NSE',
                'type' => 'buy', 'quantity' => 2, 'price' => 100, 'fees' => 1, 'transaction_date' => '2026-11-20',
                'source' => Transaction::SOURCE_MANUAL]);
        }
        app(FakeBrokerGateway::class)->tradeRows = [$this->fill(99815, 'ORD-15', 'BUY', 2, 100)];
        $result = app(KiteCncTradeSyncService::class)->sync($profile);
        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['review']);

        $review = KiteCncTradeImport::query()->where('broker_trade_id', '99815')->firstOrFail();
        $user = User::query()->findOrFail($profile->user_id);
        $payload = $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson("/api/reconciliation/trades/review/{$review->id}")->assertOk();
        $this->assertCount(2, $payload->json('data.exact_match_candidates'));
        $bseTransaction = Transaction::query()->where('execution_exchange', 'BSE')->firstOrFail();
        $this->actingAs($user)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson("/api/reconciliation/trades/review/{$review->id}/resolve", [
                'action' => 'match_existing', 'transaction_id' => $bseTransaction->id,
                'idempotency_key' => 'wrong-venue-review-99815', 'evidence_key' => $review->review_version,
            ])->assertStatus(409);
        $this->assertDatabaseCount('kite_cnc_trade_review_audits', 0);
    }

    public function test_locked_manual_match_rejects_candidate_whose_execution_exchange_changed(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $manual = Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id,
            'execution_exchange' => 'NSE', 'type' => 'buy', 'quantity' => 2, 'price' => 100, 'fees' => 1,
            'transaction_date' => '2026-11-20', 'source' => Transaction::SOURCE_MANUAL]);
        app(FakeBrokerGateway::class)->tradeRows = [$this->fill(99816, 'ORD-16', 'BUY', 2, 100)];

        $mutated = false;
        DB::listen(function (QueryExecuted $query) use ($manual, &$mutated): void {
            $sql = strtolower(ltrim($query->sql));
            if (! $mutated && str_starts_with($sql, 'select') && str_contains($sql, 'execution_exchange')) {
                $mutated = true;
                Transaction::query()->whereKey($manual->id)->update(['execution_exchange' => 'BSE']);
            }
        });

        $result = app(KiteCncTradeSyncService::class)->sync($profile);
        $import = KiteCncTradeImport::query()->where('broker_trade_id', '99816')->firstOrFail();

        $this->assertTrue($mutated, 'The test must mutate the selected candidate between lookup and locked recheck.');
        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['review']);
        $this->assertSame('BSE', $manual->fresh()->execution_exchange);
        $this->assertSame('manual_match_review', $import->status);
        $this->assertNull($import->matched_transaction_id);
        $this->assertSame(1, $profile->transactions()->count());
    }

    public function test_leading_zero_trade_ids_are_preserved_and_decimal_ids_remain_review_only(): void
    {
        [$profile] = $this->portfolioAndStock();
        app(FakeBrokerGateway::class)->tradeRows = [
            [...$this->fill(99817, 'ORD-17', 'BUY', 2, 100), 'trade_id' => '00099817'],
            [...$this->fill(99818, 'ORD-18', 'BUY', 3, 100), 'trade_id' => '99818.0'],
        ];

        $first = app(KiteCncTradeSyncService::class)->sync($profile);
        $second = app(KiteCncTradeSyncService::class)->sync($profile);

        $this->assertSame(1, $first['imported']);
        $this->assertSame(1, $first['review']);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['review']);
        $this->assertDatabaseHas('kite_cnc_trade_imports', ['broker_trade_id' => '00099817', 'status' => 'imported']);
        $this->assertSame(1, KiteCncTradeImport::query()->where('broker_trade_id', '00099817')->count());
        $decimal = KiteCncTradeImport::query()->where('status', 'unresolved_candidate')->firstOrFail();
        $this->assertArrayNotHasKey('trade_id', $decimal->broker_evidence);
        $this->assertSame(1, $profile->transactions()->count());
    }

    public function test_duplicate_manual_transactions_are_reviewed_without_double_importing(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        foreach ([1, 2] as $_) {
            Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
                'quantity' => 5, 'price' => 100, 'fees' => 2, 'transaction_date' => '2026-11-20',
                'source' => Transaction::SOURCE_MANUAL]);
        }
        app(FakeBrokerGateway::class)->tradeRows = [$this->fill(98713, 'B-19', 'BUY', 5, 100)];

        $result = app(KiteCncTradeSyncService::class)->sync($profile);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['review']);
        $this->assertSame(2, $profile->transactions()->count());
        $this->assertDatabaseHas('kite_cnc_trade_imports', [
            'broker_trade_id' => '98713', 'status' => 'manual_match_review', 'matched_transaction_id' => null,
        ]);
    }

    public function test_matching_partial_manual_rows_links_each_fill_without_importing_again(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $first = Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 2, 'price' => 101.25, 'fees' => 1, 'transaction_date' => '2026-11-20', 'source' => Transaction::SOURCE_MANUAL]);
        $second = Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 3, 'price' => 101.50, 'fees' => 1, 'transaction_date' => '2026-11-20', 'source' => Transaction::SOURCE_MANUAL]);
        app(FakeBrokerGateway::class)->tradeRows = [
            $this->fill(98728, 'B-26', 'BUY', 2, 101.25), $this->fill(98729, 'B-26', 'BUY', 3, 101.50),
        ];

        $result = app(KiteCncTradeSyncService::class)->sync($profile);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(2, $result['matched']);
        $this->assertSame(0, $result['review']);
        $this->assertSame($first->id, (int) KiteCncTradeImport::query()->where('broker_trade_id', '98728')->value('matched_transaction_id'));
        $this->assertSame($second->id, (int) KiteCncTradeImport::query()->where('broker_trade_id', '98729')->value('matched_transaction_id'));
        $this->assertSame(2, $profile->transactions()->count());
    }

    public function test_settlement_reconciliation_adds_only_execution_proven_t1_delta_and_uses_trading_days(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [$this->fill(98721, 'B-20', 'BUY', 5, 100)];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $localHolding = $profile->holdings()->where('stock_id', $stock->id)->firstOrFail();
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 0, 't1_quantity' => 0,
            'used_quantity' => 0, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];
        $run = app(PortfolioReconciliationService::class)->run($profile, 'manual');
        $adjustments = $run->broker_snapshot['settlement_adjustments'];
        $this->assertSame(1, count($adjustments));
        $this->assertSame(5.0, (float) $adjustments[0]['quantity_delta']);
        $this->assertSame('2026-11-23', $adjustments[0]['settles_by']);
        $this->assertSame((float) $localHolding->quantity, (float) $run->broker_snapshot['comparison_holdings'][0]['quantity']);
        $this->assertSame('reconciled', $run->holdings_status);
    }

    public function test_partial_broker_reflection_is_left_as_a_holdings_discrepancy(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [$this->fill(98722, 'B-21', 'BUY', 5, 100)];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 2, 't1_quantity' => 0,
            'used_quantity' => 0, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];

        $run = app(PortfolioReconciliationService::class)->run($profile, 'manual');

        $this->assertSame([], $run->broker_snapshot['settlement_adjustments']);
        $this->assertSame('mismatch', $run->holdings_status);
        $this->assertSame(5.0, (float) $run->discrepancies['holdings'][0]['stoxQty']);
        $this->assertSame(2.0, (float) $run->discrepancies['holdings'][0]['brokerQty']);
    }

    public function test_unsettled_sale_is_adjusted_only_when_it_exactly_explains_broker_gap(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        app(\App\Services\TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy', 'quantity' => 10, 'price' => 100, 'transaction_date' => '2026-11-19',
            'source' => Transaction::SOURCE_MANUAL,
        ]);
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [$this->fill(98723, 'S-22', 'SELL', 4, 110)];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 10, 't1_quantity' => 0,
            'used_quantity' => 0, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];

        $run = app(PortfolioReconciliationService::class)->run($profile, 'manual');

        $this->assertSame(-4.0, (float) $run->broker_snapshot['settlement_adjustments'][0]['quantity_delta']);
        $this->assertSame(6.0, (float) $run->broker_snapshot['comparison_holdings'][0]['quantity']);
        $this->assertSame('reconciled', $run->holdings_status);
    }

    public function test_unsettled_sale_does_not_hide_a_different_broker_gap(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        app(\App\Services\TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy', 'quantity' => 10, 'price' => 100, 'transaction_date' => '2026-11-19',
            'source' => Transaction::SOURCE_MANUAL,
        ]);
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [$this->fill(98734, 'S-24', 'SELL', 4, 110)];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 9, 't1_quantity' => 0,
            'used_quantity' => 0, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];

        $run = app(PortfolioReconciliationService::class)->run($profile, 'manual');

        $this->assertSame([], $run->broker_snapshot['settlement_adjustments']);
        $this->assertSame(9.0, (float) $run->broker_snapshot['comparison_holdings'][0]['quantity']);
        $this->assertSame('mismatch', $run->holdings_status);
        $this->assertSame(6.0, (float) $run->discrepancies['holdings'][0]['stoxQty']);
        $this->assertSame(9.0, (float) $run->discrepancies['holdings'][0]['brokerQty']);
    }

    public function test_offsetting_same_day_buy_sell_uses_t1_and_used_quantity_without_adjustment(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        app(\App\Services\TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy', 'quantity' => 10, 'price' => 100, 'transaction_date' => '2026-11-19',
            'source' => Transaction::SOURCE_MANUAL,
        ]);
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [
            $this->fill(98724, 'S-23', 'SELL', 5, 110),
            $this->fill(98725, 'B-24', 'BUY', 5, 105),
        ];
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 10, 't1_quantity' => 5,
            'used_quantity' => 5, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];

        $run = app(PortfolioReconciliationService::class)->run($profile, 'manual');

        $this->assertSame([], $run->broker_snapshot['settlement_adjustments']);
        $this->assertSame(10.0, (float) $run->broker_snapshot['comparison_holdings'][0]['quantity']);
        $this->assertSame('reconciled', $run->holdings_status);
    }

    public function test_pending_imports_across_a_trading_holiday_are_netted_by_execution_evidence(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        CalendarEvent::query()->create(['profile_id' => null, 'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Test holiday', 'anchor_date' => '2026-11-23', 'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'is_active' => true]);
        \App\Support\TradingCalendar::clearHolidayCache();
        Carbon::setTestNow(Carbon::parse('2026-11-24 16:10:00', 'Asia/Kolkata'));
        app(\App\Services\TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy', 'quantity' => 2, 'price' => 100, 'transaction_date' => '2026-11-20', 'source' => Transaction::SOURCE_OTHER,
        ]);
        $brokerAccountId = (string) BrokerConnection::query()->where('user_id', $profile->user_id)->value('broker_user_id');
        $pastRun = KiteCncTradeSyncRun::query()->create(['profile_id' => $profile->id, 'user_id' => $profile->user_id,
            'broker_account_id' => $brokerAccountId,
            'trading_date' => '2026-11-20', 'trigger' => 'scheduled', 'status' => 'completed',
            'started_at' => '2026-11-20 16:00:00', 'completed_at' => '2026-11-20 16:01:00']);
        KiteCncTradeImport::query()->create(['profile_id' => $profile->id, 'broker_account_id' => $brokerAccountId, 'sync_run_id' => $pastRun->id,
            'stock_id' => $stock->id, 'broker_trade_id' => '98726',
            'symbol' => 'AAA', 'exchange' => 'NSE', 'side' => 'buy', 'quantity' => 2, 'price' => 100,
            'executed_at' => '2026-11-20 15:20:00', 'product' => 'CNC', 'status' => 'imported', 'broker_evidence' => ['trade_id' => '98726']]);
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [[...$this->fill(98727, 'B-25', 'BUY', 3, 100), 'exchange_timestamp' => '2026-11-24 15:20:00']];
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 20, 't1_quantity' => 0,
            'used_quantity' => 0, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];
        // The established base position is 20; Friday and Tuesday fills add five
        // while the Monday holiday delays Friday's T+1 settlement until Tuesday.
        app(\App\Services\TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy', 'quantity' => 20, 'price' => 100, 'transaction_date' => '2026-11-19', 'source' => Transaction::SOURCE_MANUAL,
        ]);
        app(KiteCncTradeSyncService::class)->sync($profile);

        $run = app(PortfolioReconciliationService::class)->run($profile, 'manual');

        $this->assertSame(5.0, (float) $run->broker_snapshot['settlement_adjustments'][0]['quantity_delta']);
        $this->assertSame('2026-11-25', $run->broker_snapshot['settlement_adjustments'][0]['settles_by']);
        $this->assertSame(25.0, (float) $run->broker_snapshot['comparison_holdings'][0]['quantity']);
        $this->assertSame('reconciled', $run->holdings_status);
    }

    public function test_reconnect_records_each_unavailable_session_once_after_tradebook_gap(): void
    {
        [$profile] = $this->portfolioAndStock();
        KiteCncTradeSyncRun::query()->create(['profile_id' => $profile->id, 'user_id' => $profile->user_id,
            'broker_account_id' => (string) BrokerConnection::query()->where('user_id', $profile->user_id)->value('broker_user_id'),
            'trading_date' => '2026-11-24', 'trigger' => 'scheduled', 'status' => 'completed',
            'started_at' => '2026-11-24 16:00:00', 'completed_at' => '2026-11-24 16:01:00']);
        Carbon::setTestNow(Carbon::parse('2026-11-27 16:10:00', 'Asia/Kolkata'));
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [];

        app(KiteCncTradeSyncService::class)->sync($profile, 'reconnect');
        app(KiteCncTradeSyncService::class)->sync($profile, 'reconnect');

        $gaps = KiteCncTradeSyncRun::query()->where('profile_id', $profile->id)->where('status', 'gap_unavailable')->orderBy('trading_date')->get();
        $this->assertSame(['2026-11-25', '2026-11-26'], $gaps->map(fn ($run) => $run->trading_date->toDateString())->all());
    }

    public function test_missing_tradebook_is_recorded_as_failed_and_surfaces_error(): void
    {
        [$profile] = $this->portfolioAndStock();
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRowsUnavailable = true;
        try {
            app(KiteCncTradeSyncService::class)->sync($profile);
            $this->fail('Expected incomplete Kite trade sync.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Kite CNC sync did not complete. Check the connection and retry.', $error->getMessage());
        }
        $this->assertDatabaseHas('kite_cnc_trade_sync_runs', ['profile_id' => $profile->id, 'status' => 'sync_failed']);
    }

    public function test_order_timestamp_alone_is_not_execution_evidence(): void
    {
        [$profile] = $this->portfolioAndStock();
        app(FakeBrokerGateway::class)->tradeRows = [[
            ...$this->fill(98730, 'B-30', 'BUY', 2, 100),
            'fill_timestamp' => null,
            'exchange_timestamp' => null,
            'order_timestamp' => '2026-11-20 15:20:00',
        ]];

        $result = app(KiteCncTradeSyncService::class)->sync($profile);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['review']);
        $this->assertSame(0, $profile->transactions()->count());
        $this->assertDatabaseHas('kite_cnc_trade_sync_runs', [
            'profile_id' => $profile->id,
            'eligible_count' => 0,
            'review_count' => 1,
            'status' => 'needs_review',
        ]);
    }

    public function test_zero_and_negative_cnc_fills_are_reviewed_without_ledger_or_cash_writes(): void
    {
        [$profile] = $this->portfolioAndStock();
        app(FakeBrokerGateway::class)->tradeRows = [
            $this->fill(98731, 'B-31', 'BUY', 0, 100),
            $this->fill(98732, 'B-32', 'SELL', -2, 100),
        ];
        $cashBefore = app(\App\Services\CashManagementService::class)->balance($profile);

        $result = app(KiteCncTradeSyncService::class)->sync($profile);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(2, $result['review']);
        $this->assertSame(0, $profile->transactions()->count());
        $this->assertEquals($cashBefore, app(\App\Services\CashManagementService::class)->balance($profile));
        $this->assertSame(2, KiteCncTradeImport::query()->where('status', 'unresolved_candidate')->count());
    }

    public function test_sync_isolated_by_profile_and_paused_when_one_kite_user_has_multiple_live_profiles(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $otherUser = User::factory()->create();
        $otherProfile = $this->defaultPortfolioFor($otherUser);
        $otherProfile->forceFill(['portfolio_type' => PortfolioProfile::TYPE_LIVE,
            'execution_mode' => PortfolioProfile::EXECUTION_MODE_AUTOMATIC])->save();
        BrokerConnection::query()->create([
            'user_id' => $otherUser->id, 'provider' => BrokerConnection::PROVIDER_KITE,
            'access_token' => 'other-test-token', 'connected_at' => now(), 'expires_at' => now()->addDay(),
            'broker_user_id' => 'OTHER1',
        ]);
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [$this->fill(98733, 'B-33', 'BUY', 2, 100)];

        app(KiteCncTradeSyncService::class)->sync($profile);
        $this->assertSame(1, $profile->transactions()->count());
        $this->assertSame(0, $otherProfile->transactions()->count());
        $this->assertSame(1, KiteCncTradeImport::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(0, KiteCncTradeImport::query()->where('profile_id', $otherProfile->id)->count());

        $secondProfile = PortfolioProfile::query()->create([
            'user_id' => $profile->user_id, 'name' => 'Second Live Portfolio', 'is_default' => false,
            'portfolio_type' => PortfolioProfile::TYPE_LIVE,
        ]);
        // execution_mode is intentionally not mass assignable on this model.
        $secondProfile->forceFill(['execution_mode' => PortfolioProfile::EXECUTION_MODE_AUTOMATIC])->save();
        $this->assertSame(2, PortfolioProfile::query()->where('user_id', $profile->user_id)
            ->where('portfolio_type', PortfolioProfile::TYPE_LIVE)
            ->whereIn('execution_mode', [PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC, PortfolioProfile::EXECUTION_MODE_AUTOMATIC])
            ->count());
        try {
            app(KiteCncTradeSyncService::class)->sync($profile);
            $this->fail('A shared Kite account with ambiguous profile ownership must be paused.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('multiple live portfolios', $error->getMessage());
        }
        $this->assertSame(0, $secondProfile->transactions()->count());
    }

    public function test_expired_fill_is_not_reintroduced_when_a_later_fill_is_pending(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        Carbon::setTestNow(Carbon::parse('2026-11-24 16:10:00', 'Asia/Kolkata'));
        foreach ([['2026-11-19', 10], ['2026-11-20', 2], ['2026-11-24', 3]] as [$date, $quantity]) {
            app(\App\Services\TransactionWriteService::class)->create($profile, $stock, [
                'type' => 'buy', 'quantity' => $quantity, 'price' => 100, 'transaction_date' => $date,
                'source' => Transaction::SOURCE_OTHER,
            ]);
        }
        $brokerAccountId = (string) BrokerConnection::query()->where('user_id', $profile->user_id)->value('broker_user_id');
        $run = KiteCncTradeSyncRun::query()->create(['profile_id' => $profile->id, 'user_id' => $profile->user_id,
            'broker_account_id' => $brokerAccountId,
            'trading_date' => '2026-11-24', 'trigger' => 'scheduled', 'status' => 'completed',
            'started_at' => now(), 'completed_at' => now()]);
        foreach ([['old', '2026-11-20 15:00:00', 2], ['new', '2026-11-24 15:00:00', 3]] as [$id, $executedAt, $quantity]) {
            KiteCncTradeImport::query()->create(['profile_id' => $profile->id, 'broker_account_id' => $brokerAccountId, 'sync_run_id' => $run->id,
                'stock_id' => $stock->id, 'broker_trade_id' => $id, 'symbol' => 'AAA', 'exchange' => 'NSE',
                'side' => 'buy', 'quantity' => $quantity, 'price' => 100, 'executed_at' => $executedAt,
                'product' => 'CNC', 'status' => 'imported', 'broker_evidence' => ['trade_id' => $id]]);
        }
        $fake = app(FakeBrokerGateway::class);
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 12, 't1_quantity' => 0,
            'used_quantity' => 0, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];

        $reconciliation = app(PortfolioReconciliationService::class)->run($profile, 'manual');

        $this->assertCount(1, $reconciliation->broker_snapshot['settlement_adjustments']);
        $this->assertSame(['2026-11-24'], $reconciliation->broker_snapshot['settlement_adjustments'][0]['trade_dates']);
        $this->assertSame(3.0, (float) $reconciliation->broker_snapshot['settlement_adjustments'][0]['quantity_delta']);
        $this->assertSame('reconciled', $reconciliation->holdings_status);
    }

    private function portfolioAndStock(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $profile->forceFill(['portfolio_type' => PortfolioProfile::TYPE_LIVE,
            'execution_mode' => PortfolioProfile::EXECUTION_MODE_AUTOMATIC])->save();
        BrokerConnection::query()->create([
            'user_id' => $user->id, 'provider' => BrokerConnection::PROVIDER_KITE,
            'access_token' => 'test-access-token', 'connected_at' => now(), 'expires_at' => now()->addDay(),
            'broker_user_id' => 'AB1234',
        ]);
        app(\App\Services\CashManagementService::class)->deposit($profile, 100000, 'Test opening cash', $user);
        $stock = Stock::query()->create(['symbol' => 'AAA', 'exchange' => 'NSE', 'name' => 'AAA', 'is_active' => true]);
        return [$profile, $stock];
    }

    private function fill(int $id, string $orderId, string $side, int $quantity, float $price): array
    {
        return ['trade_id' => $id, 'order_id' => $orderId, 'tradingsymbol' => 'AAA', 'exchange' => 'NSE',
            'transaction_type' => $side, 'product' => 'CNC', 'quantity' => $quantity, 'price' => $price,
            'exchange_timestamp' => '2026-11-20 15:20:00'];
    }
}
