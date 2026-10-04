<?php

namespace Tests\Feature\V8;

use App\Models\User;
use App\Services\Broker\BrokerConnectionService;
use App\Services\Microstructure\MicrostructureCollectorControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MicrostructureKiteConnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_collector_status_is_private_to_the_configured_user(): void
    {
        $collector = User::factory()->create();
        $otherUser = User::factory()->create();
        config(['microstructure_collector.kite_user_id' => $collector->id]);
        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/microstructure-kite/status')
            ->assertForbidden();

        $connections = $this->createMock(BrokerConnectionService::class);
        $connections->expects($this->once())->method('status')->with($this->callback(fn ($user) => $user->id === $collector->id))
            ->willReturn(['configured' => true, 'usable' => true]);
        $this->app->instance(BrokerConnectionService::class, $connections);
        $control = $this->createMock(MicrostructureCollectorControlService::class);
        $control->expects($this->once())->method('operationalStatus')->willReturn([
            'enabled' => true, 'websocket_connected' => true, 'last_packet_at' => now()->toIso8601String(), 'collector_state' => 'collecting',
        ]);
        $this->app->instance(MicrostructureCollectorControlService::class, $control);

        $this->actingAs($collector, 'sanctum')->getJson('/api/microstructure-kite/status')
            ->assertOk()->assertJsonPath('data.display_state', 'receiving_live_ticks');
    }

    public function test_bookmarkable_page_requires_the_configured_stox_session(): void
    {
        $collector = User::factory()->create();
        $otherUser = User::factory()->create();
        config(['microstructure_collector.kite_user_id' => $collector->id]);

        $this->get('/kite-connect')->assertRedirect('/login');
        $this->actingAs($otherUser)->get('/kite-connect')->assertForbidden();
        $this->actingAs($collector)->get('/kite-connect')->assertOk()->assertSee('Daily Kite connection');
    }

    public function test_login_url_requests_the_kite_connect_callback_destination(): void
    {
        $collector = User::factory()->create();
        config(['microstructure_collector.kite_user_id' => $collector->id]);
        $connections = $this->createMock(BrokerConnectionService::class);
        $connections->expects($this->once())->method('loginUrl')->with($collector, 'kite-connect')
            ->willReturn('https://kite.zerodha.com/connect/login?state=opaque');
        $this->app->instance(BrokerConnectionService::class, $connections);

        $this->actingAs($collector, 'sanctum')
            ->getJson('/api/microstructure-kite/login-url')
            ->assertOk()
            ->assertJsonPath('data.url', 'https://kite.zerodha.com/connect/login?state=opaque');
    }
}
