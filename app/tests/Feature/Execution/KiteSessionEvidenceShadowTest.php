<?php

namespace Tests\Feature\Execution;

use App\Models\BrokerConnection;
use App\Models\CashAccount;
use App\Models\KiteSessionEvidence;
use App\Models\PortfolioProfile;
use App\Models\User;
use App\Services\Broker\BrokerGateway;
use App\Services\CashManagementService;
use App\Services\Execution\KiteSessionEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class KiteSessionEvidenceShadowTest extends TestCase
{
    use RefreshDatabase;

    private function userWithLiveProfile(string $accountId = 'kite-account-a'): array
    {
        $user = User::factory()->create();
        $profile = PortfolioProfile::query()->create(['user_id' => $user->id, 'name' => 'Live', 'is_default' => true, 'portfolio_type' => 'live']);
        CashAccount::query()->create(['profile_id' => $profile->id, 'balance' => 1000]);
        $connection = new BrokerConnection(['user_id' => $user->id, 'provider' => 'kite', 'broker_user_id' => $accountId, 'connected_at' => now(), 'expires_at' => now()->addHour()]);
        $connection->access_token = 'secret-token';
        $connection->save();
        return [$user, $profile];
    }

    private function service(array $snapshot): KiteSessionEvidenceService
    {
        $gateway = Mockery::mock(BrokerGateway::class);
        $gateway->shouldReceive('portfolioSnapshot')->andReturnUsing(function (int $userId) use ($snapshot) {
            return $snapshot + ['broker_account_id' => (string) BrokerConnection::query()
                ->where('user_id', $userId)->where('provider', 'kite')->value('broker_user_id')];
        });
        return new KiteSessionEvidenceService($gateway, app(CashManagementService::class));
    }

    public function test_snapshot_from_another_account_is_not_bound_to_the_active_account(): void
    {
        [$user] = $this->userWithLiveProfile('kite-account-a');
        $result = $this->service([
            'broker_account_id' => 'kite-account-old', 'captured_at' => now()->toISOString(),
            'current_cash' => 2500, 'holdings' => [], 'positions' => [],
        ])->capture($user);

        $row = KiteSessionEvidence::query()->firstOrFail();
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('kite-account-a', $row->broker_account_id);
        $this->assertSame('unknown', $row->session_status);
        $this->assertNull($row->evidence['kite_buying_power']['equity_available']);
        $this->assertContains('broker_snapshot_account_mismatch', $row->blocking_reasons);
    }

    public function test_session_replaced_after_snapshot_is_not_persisted_as_new_account_evidence(): void
    {
        [$user] = $this->userWithLiveProfile('kite-account-a');
        $gateway = Mockery::mock(BrokerGateway::class);
        $gateway->shouldReceive('portfolioSnapshot')->once()->andReturnUsing(function (int $userId) {
            $snapshot = ['broker_account_id' => 'kite-account-a', 'captured_at' => now()->toISOString(),
                'current_cash' => 2500, 'holdings' => [], 'positions' => []];
            BrokerConnection::query()->where('user_id', $userId)->where('provider', 'kite')
                ->update(['broker_user_id' => 'kite-account-b', 'updated_at' => now()->addSecond()]);
            return $snapshot;
        });

        $result = (new KiteSessionEvidenceService($gateway, app(CashManagementService::class)))->capture($user);

        $row = KiteSessionEvidence::query()->firstOrFail();
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('kite-account-a', $row->broker_account_id);
        $this->assertSame('unknown', $row->session_status);
        $this->assertNull($row->evidence['kite_buying_power']['equity_available']);
        $this->assertContains('broker_session_changed_during_capture', $row->blocking_reasons);
    }

    public function test_cash_and_buying_power_are_separate_and_unprovable_sources_remain_incomplete(): void
    {
        [$user, $profile] = $this->userWithLiveProfile();
        $service = $this->service(['captured_at' => now()->toISOString(), 'current_cash' => 2500, 'holdings' => [], 'positions' => []]);
        $service->capture($user);
        $row = KiteSessionEvidence::query()->firstOrFail();
        $this->assertSame(1000.0, (float) $row->evidence['stox_ledger']['cash_balance']);
        $this->assertSame(2500.0, (float) $row->evidence['kite_buying_power']['equity_available']);
        $this->assertSame('incomplete', $row->session_status);
        $this->assertSame('unknown', $row->trades_status);
        $this->assertContains('settlement_comparability_unproven', $row->blocking_reasons);
        $this->assertSame($profile->id, $row->profile_id);
    }

    public function test_stale_snapshot_is_unknown_and_account_switch_keeps_separate_provenance(): void
    {
        [$user] = $this->userWithLiveProfile();
        $stale = $this->service(['captured_at' => now()->subMinutes(8)->toISOString(), 'current_cash' => 1000, 'holdings' => [], 'positions' => []]);
        $stale->capture($user);
        $this->assertSame('unknown', KiteSessionEvidence::query()->firstOrFail()->session_status);

        $connection = BrokerConnection::query()->where('user_id', $user->id)->firstOrFail();
        $connection->broker_user_id = 'kite-account-b';
        $connection->save();
        $fresh = $this->service(['captured_at' => now()->toISOString(), 'current_cash' => 1000, 'holdings' => [], 'positions' => []]);
        $fresh->capture($user);
        $this->assertSame(2, KiteSessionEvidence::query()->count());
        $this->assertEqualsCanonicalizing(['kite-account-a', 'kite-account-b'], KiteSessionEvidence::query()->pluck('broker_account_id')->all());
    }

    public function test_future_or_untimestamped_snapshot_is_unknown_and_does_not_expose_financial_values(): void
    {
        [$user] = $this->userWithLiveProfile();
        foreach ([
            ['captured_at' => now()->addMinutes(2)->toISOString(), 'current_cash' => 9000, 'holdings' => [['symbol' => 'ABC']], 'positions' => []],
            ['current_cash' => 8000, 'holdings' => [['symbol' => 'XYZ']], 'positions' => []],
        ] as $snapshot) {
            $this->service($snapshot)->capture($user);
            $row = KiteSessionEvidence::query()->firstOrFail();
            $this->assertSame('unknown', $row->session_status);
            $this->assertNull($row->evidence['kite_buying_power']['equity_available']);
            $this->assertNull($row->evidence['kite_holdings']);
            $row->delete();
        }
    }

    public function test_latest_evidence_is_hidden_after_disconnect_and_account_switch(): void
    {
        [$user] = $this->userWithLiveProfile('kite-account-a');
        $service = $this->service(['captured_at' => now()->toISOString(), 'current_cash' => 1000, 'holdings' => [], 'positions' => []]);
        $service->capture($user);
        $this->assertCount(1, $service->latest($user));

        $connection = BrokerConnection::query()->where('user_id', $user->id)->firstOrFail();
        $connection->broker_user_id = 'kite-account-b';
        $connection->save();
        $this->assertSame([], $service->latest($user));

        $connection->delete();
        $this->assertSame([], $service->latest($user));
    }
}
