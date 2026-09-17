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
}
