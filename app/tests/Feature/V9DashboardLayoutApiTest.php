<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V9DashboardLayoutApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_layouts_are_account_scoped_and_support_defaulting(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create(['is_admin' => false]);
        $definition = ['schema' => 'stox.dashboard.layout', 'version' => 1, 'desktop' => [], 'mobile' => []];

        $response = $this->actingAs($user)->postJson('/api/dashboard-layouts', ['name' => 'Investor', 'definition' => $definition, 'is_default' => true]);
        $response->assertCreated()->assertJsonPath('data.name', 'Investor');
        $id = $response->json('data.id');

        $this->actingAs($other)->getJson('/api/dashboard-layouts')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson('/api/dashboard-layouts')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAs($user)->postJson("/api/dashboard-layouts/{$id}/duplicate", ['name' => 'Investor copy'])->assertCreated()->assertJsonPath('data.name', 'Investor copy');
        $this->actingAs($other)->putJson("/api/dashboard-layouts/{$id}", ['name' => 'Hijack'])->assertNotFound();
    }
}
