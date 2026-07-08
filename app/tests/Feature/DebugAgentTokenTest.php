<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebugAgentTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_debug_token_grants_admin_access_to_protected_api(): void
    {
        config([
            'portfolio.debug_agent.enabled' => true,
            'portfolio.debug_agent.token' => 'test-debug-token',
        ]);

        User::factory()->create(['is_admin' => true, 'email' => 'admin@example.com']);

        $this->getJson('/api/universe-price-sync/status', [
            'X-Lido-Debug-Token' => 'test-debug-token',
        ])->assertOk();
    }

    public function test_wrong_debug_token_stays_unauthorized(): void
    {
        config([
            'portfolio.debug_agent.enabled' => true,
            'portfolio.debug_agent.token' => 'test-debug-token',
        ]);

        User::factory()->create(['is_admin' => true]);

        $response = $this->getJson('/api/universe-price-sync/status', [
            'X-Lido-Debug-Token' => 'wrong',
        ]);

        $response->assertJsonPath('message', 'Unauthenticated.');
        $this->assertNotEquals(200, $response->status());
    }

    public function test_debug_disabled_requires_normal_auth(): void
    {
        config([
            'portfolio.debug_agent.enabled' => false,
            'portfolio.debug_agent.token' => 'test-debug-token',
        ]);

        User::factory()->create(['is_admin' => true]);

        $response = $this->getJson('/api/universe-price-sync/status', [
            'X-Lido-Debug-Token' => 'test-debug-token',
        ]);

        $response->assertJsonPath('message', 'Unauthenticated.');
        $this->assertNotEquals(200, $response->status());
    }
}
