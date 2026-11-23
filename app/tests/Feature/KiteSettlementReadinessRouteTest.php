<?php

namespace Tests\Feature;

use App\Models\BrokerConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KiteSettlementReadinessRouteTest extends TestCase
{
    use RefreshDatabase;

    private function connect(User $user, string $account): void
    {
        $connection = new BrokerConnection(['user_id' => $user->id, 'provider' => 'kite', 'broker_user_id' => $account,
            'connected_at' => now(), 'expires_at' => now()->addHour()]);
        $connection->access_token = 'synthetic-read-only-test-token';
        $connection->save();
    }

    public function test_authenticated_profile_receives_incomplete_read_only_report_for_its_connected_account(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->connect($user, 'ACCOUNT-A');

        $before = [
            'trades' => \DB::table('kite_cnc_trade_imports')->count(),
            'runs' => \DB::table('kite_cnc_trade_sync_runs')->count(),
            'funds' => \DB::table('portfolio_kite_funds_snapshots')->count(),
            'transactions' => \DB::table('portfolio_transactions')->count(),
            'cash' => \DB::table('portfolio_cash_ledger_entries')->count(),
        ];
        $response = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/kite/settlement-readiness?date='.now('Asia/Kolkata')->toDateString().'&account_id=ACCOUNT-B');

        $response->assertOk()
            ->assertJsonPath('data.profile_id', $profile->id)
            ->assertJsonPath('data.broker_account_id', 'ACCOUNT-A')
            ->assertJsonPath('data.account_binding', 'unverified')
            ->assertJsonPath('data.status', 'diagnostic_only_review_required')
            ->assertJsonPath('data.evidence.broker_holdings_capture_supplied', false)
            ->assertJsonPath('data.evidence.independent_settlement_data_available', false)
            ->assertJsonPath('data.holdings_diagnostic', null)
            ->assertJsonFragment(['persisted_broker_holdings_positions_capture_unavailable'])
            ->assertJsonFragment(['true_profile_holdings_source_missing'])
            ->assertJsonPath('data.policy', 'read_only_no_persistence_no_broker_calls_no_adjustments_no_execution_gate');
        $this->assertSame($before, [
            'trades' => \DB::table('kite_cnc_trade_imports')->count(),
            'runs' => \DB::table('kite_cnc_trade_sync_runs')->count(),
            'funds' => \DB::table('portfolio_kite_funds_snapshots')->count(),
            'transactions' => \DB::table('portfolio_transactions')->count(),
            'cash' => \DB::table('portfolio_cash_ledger_entries')->count(),
        ]);
    }

    public function test_another_users_profile_is_not_readable(): void
    {
        $owner = User::factory()->create();
        $ownerProfile = $this->defaultPortfolioFor($owner);
        $this->connect($owner, 'OWNER-ACCOUNT');
        $other = User::factory()->create();
        $otherProfile = $this->defaultPortfolioFor($other);
        $this->connect($other, 'OTHER-ACCOUNT');

        $this->actingAs($owner)->withProfileHeader($owner, $otherProfile)
            ->getJson('/api/kite/settlement-readiness')
            ->assertNotFound();
        $this->actingAs($owner)->withProfileHeader($owner, $ownerProfile)
            ->getJson('/api/kite/settlement-readiness?broker_account_id=OTHER-ACCOUNT')
            ->assertOk()->assertJsonPath('data.broker_account_id', 'OWNER-ACCOUNT');
    }

    public function test_authentication_and_unambiguous_live_account_binding_are_required(): void
    {
        $this->getJson('/api/kite/settlement-readiness')->assertUnauthorized();

        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        // The schema permits one provider connection per user. A missing
        // connection is therefore the valid representation of no account.
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/kite/settlement-readiness')->assertStatus(409);

        $this->connect($user, 'ACCOUNT-A');
        $request = fn () => $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/kite/settlement-readiness');

        \DB::table('portfolio_broker_connections')->where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);
        $request()->assertStatus(409); // expired

        \DB::table('portfolio_broker_connections')->where('user_id', $user->id)->update([
            'expires_at' => now()->addHour(), 'connected_at' => null,
        ]);
        $request()->assertStatus(409); // stale/incomplete connection metadata

        \DB::table('portfolio_broker_connections')->where('user_id', $user->id)->update([
            'connected_at' => now(), 'broker_user_id' => 'invalid/account',
        ]);
        $request()->assertStatus(409); // malformed account identity

        \DB::table('portfolio_broker_connections')->where('user_id', $user->id)->update([
            'broker_user_id' => 'ACCOUNT-A', 'access_token' => null,
        ]);
        $request()->assertStatus(409); // missing session credential
    }

    public function test_snapshot_scope_uses_stored_profile_and_cnc_day_uses_ist_boundaries(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $otherProfile = \App\Models\PortfolioProfile::query()->create([
            'user_id' => $user->id, 'name' => 'Other', 'is_default' => false,
        ]);
        $this->connect($user, 'ACCOUNT-A');
        $day = '2026-11-23';
        $runId = \DB::table('kite_cnc_trade_sync_runs')->insertGetId([
            'profile_id' => $profile->id, 'user_id' => $user->id, 'broker_account_id' => 'ACCOUNT-A',
            'trading_date' => $day, 'trigger' => 'manual', 'status' => 'completed', 'started_at' => '2026-11-22 11:00:00',
            'completed_at' => '2026-11-22 11:01:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$profile, $otherProfile] as $snapshotProfile) {
            \DB::table('portfolio_portfolio_snapshots')->insert([
                'profile_id' => $snapshotProfile->id, 'snapshot_date' => $day,
                'portfolio_value' => 100, 'invested_value' => 90, 'created_at' => now(),
            ]);
        }
        \DB::table('kite_cnc_trade_imports')->insert([
            'profile_id' => $profile->id, 'sync_run_id' => $runId, 'broker_trade_id' => 'TRADE-IST-LATE',
            'symbol' => 'ABC', 'exchange' => 'NSE', 'side' => 'buy', 'quantity' => 1, 'price' => 10,
            // 20:00 UTC on the previous date is 01:30 IST on the requested day.
            'executed_at' => '2026-11-22 20:00:00', 'product' => 'CNC', 'status' => 'imported',
            'broker_account_id' => 'ACCOUNT-A', 'evidence_kind' => 'execution', 'evidence_key' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('kite_cnc_trade_imports')->insert([
            'profile_id' => $profile->id, 'sync_run_id' => $runId, 'broker_trade_id' => 'LEGACY-UNBOUND',
            'symbol' => 'ABC', 'exchange' => 'NSE', 'side' => 'buy', 'quantity' => 1, 'price' => 10,
            'executed_at' => '2026-11-22 20:05:00', 'product' => 'CNC', 'status' => 'unresolved_candidate',
            'broker_account_id' => null, 'evidence_kind' => 'legacy_unbound', 'evidence_key' => str_repeat('b', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/kite/settlement-readiness?date='.$day)
            ->assertOk()
            ->assertJsonPath('data.account_binding', 'verified_explicit_mapping')
            ->assertJsonPath('data.evidence.portfolio_snapshots.count', 1)
            ->assertJsonPath('data.evidence.cnc_imports.count', 1)
            ->assertJsonPath('data.evidence.cnc_imports.legacy_unbound_count', 1)
            ->assertJsonFragment(['legacy_or_unbound_cnc_imports_not_account_evidence'])
            ->assertJsonPath('data.status', 'diagnostic_only_review_required');
    }

    public function test_invalid_date_and_expired_session_fail_closed(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->connect($user, 'ACCOUNT-A');
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/kite/settlement-readiness?date=not-a-date')->assertUnprocessable();

        \DB::table('portfolio_broker_connections')->where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/kite/settlement-readiness')->assertStatus(409);
    }
}
