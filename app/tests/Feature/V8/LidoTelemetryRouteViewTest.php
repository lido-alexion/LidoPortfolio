<?php

namespace Tests\Feature\V8;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LidoTelemetryRouteViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_record_route_view_durations(): void
    {
        Http::fake();
        config([
            'lido_telemetry.enabled' => true,
            'lido_telemetry.otlp_traces_endpoint' => 'http://collector.test/v1/traces',
        ]);

        $user = User::factory()->create(['is_admin' => false]);
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user)
            ->postJson('/api/telemetry/route-view', [
                'route' => '/holdings',
                'wall_duration_ms' => 12000,
                'active_duration_ms' => 9000,
            ])
            ->assertOk()
            ->assertJsonPath('data.recorded', true);

        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            return str_contains((string) $body, 'stox.ui.route_view')
                && str_contains((string) $body, 'wall_duration_ms');
        });
    }
}
