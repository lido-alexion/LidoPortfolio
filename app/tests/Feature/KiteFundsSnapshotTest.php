<?php

namespace Tests\Feature;

use App\Models\BrokerConnection;
use App\Models\KiteFundsReconciliation;
use App\Models\KiteFundsSnapshot;
use App\Models\PortfolioProfile;
use App\Models\User;
use App\Services\Broker\KiteFundsSnapshotService;
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
            'trading_date' => '2026-11-20', 'captured_at' => '2026-11-20 18:25:00',
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
}
