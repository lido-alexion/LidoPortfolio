<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $response->assertJsonPath('data.revision', 1);
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

    public function test_dashboard_definition_updates_require_the_current_revision(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $definition = ['schema' => 'stox.dashboard.layout', 'version' => 1, 'desktop' => [], 'mobile' => []];
        $created = $this->actingAs($user)->postJson('/api/dashboard-layouts', ['name' => 'Shared', 'definition' => $definition])->assertCreated();
        $id = $created->json('data.id');
        $this->putJson("/api/dashboard-layouts/{$id}", ['definition' => $definition, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->putJson("/api/dashboard-layouts/{$id}", ['definition' => $definition, 'expected_revision' => 1])
            ->assertConflict();
        $this->assertSame(2, \App\Models\DashboardLayout::findOrFail($id)->revision);
    }

    public function test_legacy_normalization_is_revisioned_once_before_stale_saves_are_checked(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create(['is_admin' => false]);
        $legacyDefinition = ['schema' => 'stox.dashboard.layout', 'version' => 1, 'desktop' => [], 'mobile' => []];
        $legacyId = DB::table('portfolio_dashboard_layouts')->insertGetId([
            'user_id' => $user->id,
            'name' => 'Legacy',
            'definition' => json_encode($legacyDefinition, JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherLegacyId = DB::table('portfolio_dashboard_layouts')->insertGetId([
            'user_id' => $other->id,
            'name' => 'Other account legacy',
            'definition' => json_encode($legacyDefinition, JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $legacy = \App\Models\DashboardLayout::findOrFail($legacyId);
        $otherLegacy = \App\Models\DashboardLayout::findOrFail($otherLegacyId);
        $this->assertSame(1, $legacy->revision, 'the forward migration default must initialize existing/newly inserted rows at revision 1');

        $firstRead = $this->actingAs($user)->getJson('/api/dashboard-layouts')->assertOk();
        $firstRead->assertJsonPath('data.0.id', $legacy->id)
            ->assertJsonPath('data.0.revision', 2)
            ->assertJsonPath('data.0.definition.desktop.cards.0.id', 'portfolio-summary');
        $this->assertSame(2, $legacy->fresh()->revision);
        $this->assertSame(1, $otherLegacy->fresh()->revision, 'normalization must remain account-scoped');

        $reorderObjectKeys = function (mixed $value) use (&$reorderObjectKeys): mixed {
            if (!is_array($value)) return $value;
            if (array_is_list($value)) return array_map($reorderObjectKeys, $value);
            krsort($value, SORT_STRING);
            foreach ($value as $key => $item) $value[$key] = $reorderObjectKeys($item);
            return $value;
        };
        DB::table('portfolio_dashboard_layouts')->where('id', $legacy->id)->update([
            'definition' => json_encode($reorderObjectKeys($firstRead->json('data.0.definition')), JSON_THROW_ON_ERROR),
        ]);
        $this->getJson('/api/dashboard-layouts')->assertOk()->assertJsonPath('data.0.revision', 2);
        $this->putJson("/api/dashboard-layouts/{$legacy->id}", ['definition' => $legacyDefinition, 'expected_revision' => 1])->assertConflict();
        $this->assertSame(2, $legacy->fresh()->revision, 'a stale save must not overwrite normalized state or reuse its revision');

        $this->putJson("/api/dashboard-layouts/{$legacy->id}", ['definition' => $legacyDefinition, 'expected_revision' => 2])
            ->assertOk()
            ->assertJsonPath('data.revision', 3);
        $this->getJson('/api/dashboard-layouts')->assertOk()->assertJsonPath('data.0.revision', 3);
    }
}
