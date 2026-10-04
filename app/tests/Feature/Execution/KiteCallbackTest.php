<?php

namespace Tests\Feature\Execution;

use App\Models\BrokerConnection;
use App\Models\User;
use App\Services\Broker\BrokerConnectionService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KiteCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://www.lidoalexion.com/portfolio',
            'broker.kite.api_key' => 'test-api-key',
            'broker.kite.api_secret' => 'test-api-secret',
            'broker.kite.api_base' => 'https://api.kite.trade',
            'broker.kite.login_url' => 'https://kite.zerodha.com/connect/login',
        ]);
        Http::preventStrayRequests();
    }

    public function test_guest_callback_uses_encrypted_login_state_to_connect_initiating_user(): void
    {
        $user = User::factory()->create();
        $loginUrl = app(BrokerConnectionService::class)->loginUrl($user);
        parse_str((string) parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);
        parse_str($loginQuery['redirect_params'], $redirectParams);

        Http::fake([
            'https://api.kite.trade/session/token' => Http::response([
                'status' => 'success',
                'data' => [
                    'access_token' => 'kite-access-token',
                    'user_id' => 'AB1234',
                ],
            ]),
        ]);

        $this->get('/api/v1/broker/kite/callback?'.http_build_query([
            'status' => 'success',
            'request_token' => 'one-time-request-token',
            'state' => $redirectParams['state'],
        ]))->assertRedirect('https://www.lidoalexion.com/portfolio/settings/account?kite=connected');

        $connection = BrokerConnection::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('AB1234', $connection->broker_user_id);
        $this->assertSame('kite-access-token', $connection->access_token);
        Http::assertSentCount(1);
    }

    public function test_callback_rejects_invalid_login_state_without_contacting_kite(): void
    {
        Http::fake();

        $this->get('/api/v1/broker/kite/callback?'.http_build_query([
            'status' => 'success',
            'request_token' => 'one-time-request-token',
            'state' => 'invalid',
        ]))->assertRedirect('https://www.lidoalexion.com/portfolio/settings/account?kite=failed');

        Http::assertNothingSent();
        $this->assertDatabaseCount('portfolio_broker_connections', 0);
    }

    public function test_dashboard_login_state_returns_to_dashboard_after_connect(): void
    {
        $user = User::factory()->create();
        $loginUrl = app(BrokerConnectionService::class)->loginUrl($user, 'dashboard');
        parse_str((string) parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);
        parse_str($loginQuery['redirect_params'], $redirectParams);

        Http::fake([
            'https://api.kite.trade/session/token' => Http::response([
                'status' => 'success',
                'data' => ['access_token' => 'dashboard-token', 'user_id' => 'AB1234'],
            ]),
        ]);

        $this->get('/api/v1/broker/kite/callback?'.http_build_query([
            'status' => 'success',
            'request_token' => 'one-time-request-token',
            'state' => $redirectParams['state'],
        ]))->assertRedirect('https://www.lidoalexion.com/portfolio/?kite=connected');
    }

    public function test_kite_connect_login_state_returns_to_dedicated_page_after_callback(): void
    {
        $user = User::factory()->create();
        $loginUrl = app(BrokerConnectionService::class)->loginUrl($user, 'kite-connect');
        parse_str((string) parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);
        parse_str($loginQuery['redirect_params'], $redirectParams);

        Http::fake([
            'https://api.kite.trade/session/token' => Http::response([
                'status' => 'success',
                'data' => ['access_token' => 'connect-page-token', 'user_id' => 'AB1234'],
            ]),
        ]);

        $this->get('/api/v1/broker/kite/callback?'.http_build_query([
            'status' => 'success',
            'request_token' => 'one-time-request-token',
            'state' => $redirectParams['state'],
        ]))->assertRedirect('https://www.lidoalexion.com/portfolio/kite-connect?kite=connected');
    }

    public function test_login_state_rejects_unlisted_return_destinations(): void
    {
        $user = User::factory()->create();
        $loginUrl = app(BrokerConnectionService::class)->loginUrl($user, 'https://attacker.example');
        parse_str((string) parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);
        parse_str($loginQuery['redirect_params'], $redirectParams);

        $this->assertSame('account', app(BrokerConnectionService::class)->returnToFromLoginState($redirectParams['state']));
    }

    public function test_expired_or_tampered_callback_state_cannot_attach_a_connection(): void
    {
        $user = User::factory()->create();
        $loginUrl = app(BrokerConnectionService::class)->loginUrl($user);
        parse_str((string) parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);
        parse_str($loginQuery['redirect_params'], $redirectParams);

        Carbon::setTestNow(now()->addMinutes(11));
        Http::fake();

        $this->get('/api/v1/broker/kite/callback?'.http_build_query([
            'status' => 'success',
            'request_token' => 'expired-request-token',
            'state' => $redirectParams['state'],
        ]))->assertRedirect('https://www.lidoalexion.com/portfolio/settings/account?kite=failed');

        Carbon::setTestNow();
        $this->get('/api/v1/broker/kite/callback?'.http_build_query([
            'status' => 'success',
            'request_token' => 'tampered-request-token',
            'state' => $redirectParams['state'].'tampered',
        ]))->assertRedirect('https://www.lidoalexion.com/portfolio/settings/account?kite=failed');

        Http::assertNothingSent();
        $this->assertDatabaseCount('portfolio_broker_connections', 0);
    }

    public function test_callback_binds_to_the_initiating_user_even_when_another_user_has_a_browser_session(): void
    {
        $initiator = User::factory()->create();
        $otherUser = User::factory()->create();
        $loginUrl = app(BrokerConnectionService::class)->loginUrl($initiator);
        parse_str((string) parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);
        parse_str($loginQuery['redirect_params'], $redirectParams);

        Http::fake([
            'https://api.kite.trade/session/token' => Http::response([
                'status' => 'success',
                'data' => ['access_token' => 'initiator-token', 'user_id' => 'AB1234'],
            ]),
        ]);

        $this->actingAs($otherUser)
            ->get('/api/v1/broker/kite/callback?'.http_build_query([
                'status' => 'success',
                'request_token' => 'initiator-request-token',
                'state' => $redirectParams['state'],
            ]))->assertRedirect('https://www.lidoalexion.com/portfolio/settings/account?kite=connected');

        $this->assertDatabaseHas('portfolio_broker_connections', ['user_id' => $initiator->id]);
        $this->assertDatabaseMissing('portfolio_broker_connections', ['user_id' => $otherUser->id]);
    }

    public function test_next_kite_expiry_is_a_utc_instant_at_six_ist_without_dst_assumptions(): void
    {
        $service = app(BrokerConnectionService::class);
        $beforeSix = $service->nextKiteExpiry(Carbon::parse('2026-07-07 00:00:00', 'UTC'));
        $afterSix = $service->nextKiteExpiry(Carbon::parse('2026-07-07 00:30:00', 'UTC'));
        $winter = $service->nextKiteExpiry(Carbon::parse('2026-12-07 00:00:00', 'UTC'));

        $this->assertSame('2026-07-07T00:30:00+00:00', $beforeSix->toIso8601String());
        $this->assertSame('2026-07-08T00:30:00+00:00', $afterSix->toIso8601String());
        $this->assertSame('2026-12-07T00:30:00+00:00', $winter->toIso8601String());
        $this->assertSame('Asia/Kolkata', $beforeSix->copy()->timezone('Asia/Kolkata')->getTimezone()->getName());
    }

    public function test_reading_legacy_connection_does_not_rewrite_its_expiry_or_token(): void
    {
        $user = User::factory()->create();
        $connection = BrokerConnection::query()->create([
            'user_id' => $user->id,
            'provider' => BrokerConnection::PROVIDER_KITE,
            'connected_at' => Carbon::parse('2026-01-01 00:00:00', 'UTC'),
            'expires_at' => Carbon::parse('2026-01-02 00:30:00', 'UTC'),
        ]);
        $connection->forceFill(['access_token' => 'legacy-test-token'])->save();
        $storedExpiry = $connection->fresh()->expires_at->toIso8601String();

        app(BrokerConnectionService::class)->status($user);

        $connection->refresh();
        $this->assertSame($storedExpiry, $connection->expires_at->toIso8601String());
        $this->assertSame('legacy-test-token', $connection->access_token);
    }
}
