<?php

namespace Tests\Feature\V8;

use App\Models\Screener;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FEAT-064 — registry JSON import creates account-owned runtime Screeners (not Library Drafts).
 */
class ScreenerRegistryImportRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_import_persists_runtime_screener(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $envelope = [
            'schema_version' => '1.0',
            'artifact_type' => 'screener',
            'slug' => 'v8_import_runtime',
            'name' => 'V8 Import Runtime',
            'metadata' => [
                'scope' => 'portfolio',
                'status' => 'active',
                'origin' => 'imported',
                'universe' => 'all_equities',
            ],
            'definition' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'close'],
                    'operator' => 'gt',
                    'right' => ['type' => 'constant', 'value' => 0],
                ],
            ],
        ];

        $this->postJson('/api/v1/screener-registry/validate', $envelope)
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $this->postJson('/api/v1/screener-registry/import', $envelope)
            ->assertCreated()
            ->assertJsonPath('data.metadata.origin', 'imported')
            ->assertJsonMissingPath('data.library_path');

        $screener = Screener::query()->where('profile_id', $profile->id)->where('slug', 'v8_import_runtime')->first();
        $this->assertNotNull($screener);
        $this->assertNull($screener->reusable_artifact_id);
    }
}
