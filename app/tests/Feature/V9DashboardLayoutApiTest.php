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

    public function test_dashboard_definition_is_validated_normalized_and_capped_per_account(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $definition = ['schema' => 'stox.dashboard.layout', 'version' => 1, 'locked' => false,
            'desktop' => ['cards' => [['id' => 'retired-card', 'visible' => true], ['id' => 'portfolio-summary', 'visible' => false, 'size' => 'freeform', 'order' => 'invalid']], 'summaryFields' => [['id' => 'portfolio_value', 'visible' => false]]],
            'mobile' => ['cards' => [], 'summaryFields' => []]];

        $response = $this->actingAs($user)->postJson('/api/dashboard-layouts', ['name' => 'Normalized', 'definition' => $definition]);
        $response->assertCreated();
        $saved = $response->json('data.definition');
        $this->assertTrue(collect($saved['desktop']['cards'])->contains(fn ($card) => $card['id'] === 'portfolio-summary' && $card['visible']));
        $this->assertTrue(collect($saved['desktop']['summaryFields'])->firstWhere('id', 'portfolio_value')['visible']);
        $this->assertFalse(collect($saved['desktop']['cards'])->contains(fn ($card) => $card['id'] === 'retired-card'));
        $this->assertSame('large', collect($saved['desktop']['cards'])->firstWhere('id', 'portfolio-summary')['size']);
        $this->assertSame(0, collect($saved['desktop']['cards'])->firstWhere('id', 'portfolio-summary')['order']);
        $this->assertTrue(collect($saved['mobile']['cards'])->firstWhere('id', 'portfolio-summary')['visible']);
        $this->assertCount(5, $saved['desktop']['summaryFields']);

        $this->postJson('/api/dashboard-layouts', ['name' => 'Bad schema', 'definition' => ['version' => 1]])->assertUnprocessable();
    }

    public function test_named_dashboard_count_is_limited_to_twenty(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $definition = ['schema' => 'stox.dashboard.layout', 'version' => 1, 'desktop' => [], 'mobile' => []];
        for ($index = 0; $index < 20; $index++) {
            $this->actingAs($user)->postJson('/api/dashboard-layouts', ['name' => "Dashboard {$index}", 'definition' => $definition])->assertCreated();
        }
        $this->postJson('/api/dashboard-layouts', ['name' => 'Too many', 'definition' => $definition])->assertUnprocessable();
        $first = \App\Models\DashboardLayout::query()->where('user_id', $user->id)->firstOrFail();
        $this->postJson("/api/dashboard-layouts/{$first->id}/duplicate", ['name' => 'Also too many'])->assertUnprocessable();
    }
}
