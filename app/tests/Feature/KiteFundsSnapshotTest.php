<?php

namespace Tests\Feature;

use App\Models\BrokerConnection;
use App\Models\KiteFundsReconciliation;
use App\Models\KiteFundsSnapshot;
use App\Models\PortfolioProfile;
use App\Models\User;
use App\Services\Broker\KiteFundsSnapshotService;
use App\Services\CashManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class KiteFundsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broker.kite.api_key' => 'test-key', 'broker.kite.api_base' => 'https://api.kite.trade']);
        Http::preventStrayRequests();
    }

    private function connectedUser(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $connection = new BrokerConnection(['user_id' => $user->id, 'provider' => 'kite', 'broker_user_id' => 'AB1234',
            'connected_at' => now(), 'expires_at' => now()->addHour()]);
        $connection->access_token = 'synthetic-secret-token';
        $connection->save();
        return [$user, $profile, $connection];
    }

    public function test_capture_keeps_broker_funds_components_separate_and_is_idempotent_per_minute(): void
    {
        [, , $connection] = $this->connectedUser();
        Date::setTestNow('2026-11-20 23:55:00 Asia/Kolkata');
        Http::fake(['https://api.kite.trade/user/margins/equity' => Http::response(['status' => 'success', 'data' => [
            'available' => ['live_balance' => 25000.25, 'opening_balance' => 19000, 'intraday_payin' => 10000],
            'utilised' => ['payout' => 3500],
        ]])]);

        $service = app(KiteFundsSnapshotService::class);
        $first = $service->capture($connection, 'connect');
        $again = $service->capture($connection, 'connect');

        $this->assertSame($first->id, $again->id);
        $this->assertSame('25000.2500', $first->available_balance);
        $this->assertSame('19000.0000', $first->opening_balance);
        $this->assertSame('10000.0000', $first->intraday_payin);
        $this->assertSame('3500.0000', $first->utilised_payout);
        $this->assertFalse($first->payout_semantics_verified);
        $this->assertDatabaseCount('portfolio_kite_funds_snapshots', 1);
        Date::setTestNow();
    }

    public function test_reconciliation_requires_final_snapshot_and_verified_payout_semantics_before_proposing_adjustment(): void
    {
        [$user, $profile, $connection] = $this->connectedUser();
        Date::setTestNow('2026-11-23 09:20:00 Asia/Kolkata');
        config(['broker.kite_funds.auto_adjustments_enabled' => true, 'broker.kite_funds.payout_semantics_verified' => false]);
        $snapshot = KiteFundsSnapshot::query()->create([
            'profile_id' => $profile->id, 'user_id' => $user->id, 'broker_account_id' => 'AB1234',
            'trading_date' => '2026-11-20', 'captured_at' => '2026-11-20 23:55:00',
            'available_balance' => 100, 'opening_balance' => 100, 'intraday_payin' => 5000, 'utilised_payout' => 1000,
            'payout_semantics_verified' => false, 'capture_source' => 'late_day', 'freshness' => 'complete',
            'idempotency_key' => str_repeat('a', 64), 'provenance' => ['test' => true],
        ]);

        app(KiteFundsSnapshotService::class)->reconcilePreviousSession();
        $this->assertSame('review_required', KiteFundsReconciliation::query()->firstOrFail()->status);
        $this->assertSame(0, \App\Models\CashLedgerEntry::query()->where('profile_id', $profile->id)->count());

        config(['broker.kite_funds.payout_semantics_verified' => true]);
        $snapshot->forceFill(['payout_semantics_verified' => true])->save();
        app(KiteFundsSnapshotService::class)->reconcilePreviousSession();
        $this->assertSame('adjustment_proposed', KiteFundsReconciliation::query()->firstOrFail()->status);
        $this->assertSame(0, \App\Models\CashLedgerEntry::query()->where('profile_id', $profile->id)->count());

        $response = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/cash/kite-funds/'.KiteFundsReconciliation::query()->firstOrFail()->id.'/confirm');
        $response->assertCreated()->assertJsonPath('data.status', 'adjustment_confirmed');
        $this->assertDatabaseHas('portfolio_cash_ledger_entries', ['entry_classification' => 'kite_net_adjustment', 'amount' => '4000.0000']);
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/cash/kite-funds/'.KiteFundsReconciliation::query()->firstOrFail()->id.'/confirm')->assertStatus(409);
        Date::setTestNow();
    }

    public function test_manual_match_reconciles_and_manual_mismatch_only_warns(): void
    {
        [$user, $profile] = $this->connectedUser();
        Date::setTestNow('2026-11-23 09:20:00 Asia/Kolkata');
        config(['broker.kite_funds.auto_adjustments_enabled' => true, 'broker.kite_funds.payout_semantics_verified' => true]);
        $snapshot = KiteFundsSnapshot::query()->create([
            'profile_id' => $profile->id, 'user_id' => $user->id, 'broker_account_id' => 'AB1234',
            'trading_date' => '2026-11-20', 'captured_at' => '2026-11-20 23:55:00',
            'available_balance' => 100, 'opening_balance' => 100, 'intraday_payin' => 5000, 'utilised_payout' => 1000,
            'payout_semantics_verified' => true, 'capture_source' => 'late_day', 'freshness' => 'complete',
            'idempotency_key' => str_repeat('b', 64), 'provenance' => ['test' => true],
        ]);
        app(CashManagementService::class)->deposit($profile, '4000.0000', 'Manual matching transfer', $user, '2026-11-20');
        app(KiteFundsSnapshotService::class)->reconcilePreviousSession();
        $row = KiteFundsReconciliation::query()->firstOrFail();
        $this->assertSame('reconciled', $row->status);
        $this->assertNull($row->cash_ledger_entry_id);

        \App\Models\CashLedgerEntry::query()->where('profile_id', $profile->id)->update(['amount' => '4500.0000']);
        app(KiteFundsSnapshotService::class)->reconcilePreviousSession();
        $this->assertSame('mismatch_warning', $row->fresh()->status);
        $this->assertSame(1, \App\Models\CashLedgerEntry::query()->where('profile_id', $profile->id)->count());
        $this->assertSame($snapshot->id, $row->fresh()->snapshot_id);
        Date::setTestNow();
    }

    public function test_other_account_snapshot_is_never_used_as_prior_day_evidence(): void
    {
        [$user, $profile] = $this->connectedUser();
        Date::setTestNow('2026-11-23 09:20:00 Asia/Kolkata');
        config(['broker.kite_funds.auto_adjustments_enabled' => true, 'broker.kite_funds.payout_semantics_verified' => true]);
        KiteFundsSnapshot::query()->create([
            'profile_id' => $profile->id, 'user_id' => $user->id, 'broker_account_id' => 'OTHER',
            'trading_date' => '2026-11-20', 'captured_at' => '2026-11-20 18:25:00',
            'available_balance' => 100, 'opening_balance' => 100, 'intraday_payin' => 5000, 'utilised_payout' => 1000,
            'payout_semantics_verified' => true, 'capture_source' => 'late_day', 'freshness' => 'complete',
            'idempotency_key' => str_repeat('c', 64), 'provenance' => ['test' => true],
        ]);
        app(KiteFundsSnapshotService::class)->reconcilePreviousSession();
        $row = KiteFundsReconciliation::query()->firstOrFail();
        $this->assertSame('missing_snapshot', $row->status);
        $this->assertNull($row->snapshot_id);
        $this->assertNull($row->snapshot_net_flow);
        Date::setTestNow();
    }

    public function test_zero_net_mixed_broker_activity_remains_review_only(): void
    {
        [$user, $profile] = $this->connectedUser();
        Date::setTestNow('2026-11-23 09:20:00 Asia/Kolkata');
        config(['broker.kite_funds.auto_adjustments_enabled' => true, 'broker.kite_funds.payout_semantics_verified' => true]);
        KiteFundsSnapshot::query()->create([
            'profile_id' => $profile->id, 'user_id' => $user->id, 'broker_account_id' => 'AB1234',
            'trading_date' => '2026-11-20', 'captured_at' => '2026-11-20 23:55:00',
            'available_balance' => 100, 'opening_balance' => 100, 'intraday_payin' => 500, 'utilised_payout' => 500,
            'payout_semantics_verified' => true, 'capture_source' => 'late_day', 'freshness' => 'complete',
            'idempotency_key' => str_repeat('d', 64), 'provenance' => ['test' => true],
        ]);
        app(CashManagementService::class)->deposit($profile, '500.0000', 'Manual pay-in', $user, '2026-11-20');
        app(CashManagementService::class)->withdraw($profile, '500.0000', 'Manual payout', $user, '2026-11-20');
        app(KiteFundsSnapshotService::class)->reconcilePreviousSession();
        $this->assertSame('zero_net_review', KiteFundsReconciliation::query()->firstOrFail()->status);
        $this->assertSame(2, \App\Models\CashLedgerEntry::query()->where('profile_id', $profile->id)->count());
        Date::setTestNow();
    }

    public function test_missed_late_day_snapshot_is_stale_and_never_proposed(): void
    {
        [$user, $profile] = $this->connectedUser();
        Date::setTestNow('2026-11-23 09:20:00 Asia/Kolkata');
        config(['broker.kite_funds.auto_adjustments_enabled' => true, 'broker.kite_funds.payout_semantics_verified' => true]);
        KiteFundsSnapshot::query()->create([
            'profile_id' => $profile->id, 'user_id' => $user->id, 'broker_account_id' => 'AB1234',
            'trading_date' => '2026-11-20', 'captured_at' => '2026-11-20 14:00:00',
            'available_balance' => 100, 'opening_balance' => 100, 'intraday_payin' => 500, 'utilised_payout' => 100,
            'payout_semantics_verified' => true, 'capture_source' => 'periodic', 'freshness' => 'complete',
            'idempotency_key' => str_repeat('e', 64), 'provenance' => ['test' => true],
        ]);
        app(KiteFundsSnapshotService::class)->reconcilePreviousSession();
        $this->assertSame('stale_snapshot', KiteFundsReconciliation::query()->firstOrFail()->status);
        $this->assertSame(0, \App\Models\CashLedgerEntry::query()->where('profile_id', $profile->id)->count());
        Date::setTestNow();
    }

    public function test_dismissal_is_a_tombstone_across_rechecks(): void
    {
        [$user, $profile] = $this->connectedUser();
        Date::setTestNow('2026-11-23 09:20:00 Asia/Kolkata');
        config(['broker.kite_funds.auto_adjustments_enabled' => true, 'broker.kite_funds.payout_semantics_verified' => true]);
        KiteFundsSnapshot::query()->create([
            'profile_id' => $profile->id, 'user_id' => $user->id, 'broker_account_id' => 'AB1234',
            'trading_date' => '2026-11-20', 'captured_at' => '2026-11-20 23:55:00',
            'available_balance' => 100, 'opening_balance' => 100, 'intraday_payin' => 500, 'utilised_payout' => 100,
            'payout_semantics_verified' => true, 'capture_source' => 'late_day', 'freshness' => 'complete',
            'idempotency_key' => str_repeat('f', 64), 'provenance' => ['test' => true],
        ]);
        $service = app(KiteFundsSnapshotService::class);
        $service->reconcilePreviousSession();
        $row = KiteFundsReconciliation::query()->firstOrFail();
        $this->assertSame('adjustment_proposed', $row->status);
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/cash/kite-funds/'.$row->id.'/dismiss')->assertOk()->assertJsonPath('data.status', 'adjustment_suppressed');
        $service->reconcilePreviousSession();
        $this->assertSame('adjustment_suppressed', $row->fresh()->status);
        $this->assertSame(1, KiteFundsReconciliation::query()->count());
        $this->assertSame(0, \App\Models\CashLedgerEntry::query()->where('profile_id', $profile->id)->count());
        Date::setTestNow();
    }
}
