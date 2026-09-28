<?php

namespace Tests\Feature\V8;

use App\Models\BrokerConnection;
use App\Models\BrokerInstrument;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MicrostructureCollectorInternalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'microstructure_collector.enabled' => true,
            'microstructure_collector.internal_token' => 'collector-test-token',
            'microstructure_collector.kite_user_id' => null,
            'broker.kite.api_key' => 'testkey',
        ]);
    }

    public function test_bootstrap_requires_token(): void
    {
        $this->getJson('/api/internal/microstructure-collector/bootstrap')
            ->assertUnauthorized();
    }

    public function test_bootstrap_returns_universe_when_mapped(): void
    {
        $user = User::query()->create([
            'name' => 'Ops',
            'email' => 'ops-'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
        ]);
        config(['microstructure_collector.kite_user_id' => $user->id]);

        $connection = BrokerConnection::query()->create([
            'user_id' => $user->id,
            'provider' => BrokerConnection::PROVIDER_KITE,
            'connected_at' => now(),
            'expires_at' => now()->addHours(6),
        ]);
        $connection->forceFill(['access_token' => 'secret-token'])->save();

        $stock = Stock::query()->create([
            'symbol' => 'RELIANCE',
            'name' => 'Reliance',
            'exchange' => 'NSE',
            'is_active' => true,
        ]);

        \App\Models\Setting::setValue('nifty500_constituents_json', json_encode(['RELIANCE']));
        \App\Models\Setting::setValue('nifty500_constituents_cached_at', now()->toIso8601String());

        BrokerInstrument::query()->create([
            'provider' => BrokerConnection::PROVIDER_KITE,
            'stock_id' => $stock->id,
            'exchange' => 'NSE',
            'trading_symbol' => 'RELIANCE',
            'instrument_token' => '738561',
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer collector-test-token')
            ->getJson('/api/internal/microstructure-collector/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.kite.api_key', 'testkey')
            ->assertJsonPath('data.universe.0.symbol', 'RELIANCE')
            ->assertJsonPath('data.universe.0.source_instrument_token', 738561);
    }
}
