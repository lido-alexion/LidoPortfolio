<?php

namespace Tests\Feature\V8;

use App\Models\Screener;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FEAT-064 WP-05 — shared screener import creates an independent account-owned runtime row.
 */
class ScreenerDefinitionCopySharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_import_creates_runtime_screener_not_library_draft(): void
    {
        $owner = User::factory()->create();
        $ownerProfile = $this->defaultPortfolioFor($owner);
        $otherProfile = $this->createPortfolioProfile($owner, 'Secondary', false);

        $this->actingAs($owner)->withHeader('X-Profile-Id', (string) $ownerProfile->id);
        $source = $this->postJson('/api/screeners', [
            'name' => 'Shared momentum',
            'scope' => 'holdings',
            'is_shared' => true,
            'definition_json' => $this->definition(),
        ])->assertCreated()->json('data');
        $sourceId = (int) $source['id'];

        $this->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->postJson("/api/screeners/shared/{$sourceId}/import")
            ->assertCreated()
            ->assertJsonPath('data.compatibility_read_only', false)
            ->assertJsonMissingPath('data.library_path');

        $copy = Screener::query()->where('profile_id', $otherProfile->id)->sole();
        $this->assertNull($copy->reusable_artifact_id);
        $this->assertFalse($copy->is_shared);
        $this->assertSame($source['definition_json']['root']['type'] ?? null, json_decode(json_encode($copy->definition_json), true)['root']['type'] ?? null);
    }

    /** @return array<string,mixed> */
    private function definition(): array
    {
        return ['root' => [
            'type' => 'condition',
            'left' => ['indicator' => 'close'],
            'operator' => 'gt',
            'right' => ['type' => 'constant', 'value' => 0],
        ]];
    }
}
