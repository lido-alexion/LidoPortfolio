<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndicatorRegistryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_indicators(): void
    {
        $this->getJson('/api/v1/indicators')->assertUnauthorized();
    }

    public function test_non_admin_forbidden(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)
            ->getJson('/api/v1/indicators')
            ->assertForbidden();
    }

    public function test_admin_lists_and_filters_indicators(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->getJson('/api/v1/indicators')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data', 'meta' => ['count']]);

        $this->actingAs($admin)
            ->getJson('/api/v1/indicators?type=composite&category=liquidity')
            ->assertOk()
            ->assertJsonFragment(['id' => 'liquidity_score']);

        $this->actingAs($admin)
            ->getJson('/api/v1/indicators?q=tradability')
            ->assertOk()
            ->assertJsonFragment(['id' => 'tradability_score']);
    }

    public function test_admin_meta_and_detail_include_dependency_tree(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->getJson('/api/v1/indicators/meta')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['types', 'categories', 'statuses', 'consumers', 'counts']]);

        $this->actingAs($admin)
            ->getJson('/api/v1/indicators/momentum_score')
            ->assertOk()
            ->assertJsonPath('data.indicator.id', 'momentum_score')
            ->assertJsonPath('data.dependency_tree.id', 'momentum_score')
            ->assertJsonPath('data.dependency_tree.depends_on.0.id', 'rsi');

        $this->actingAs($admin)
            ->getJson('/api/v1/indicators/tradability_score')
            ->assertOk()
            ->assertJsonPath('data.indicator.status', 'active')
            ->assertJsonFragment(['id' => 'circuit_risk']);

        $this->actingAs($admin)
            ->getJson('/api/v1/indicators/does_not_exist')
            ->assertNotFound();
    }
}
