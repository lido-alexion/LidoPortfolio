<?php

namespace Tests\Feature;

use App\Models\Screener;
use App\Models\ScreenerVersion;
use App\Models\User;
use App\Services\Artifacts\ArtifactType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenerRegistryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_screener_registry(): void
    {
        $this->getJson('/api/v1/screener-registry')->assertUnauthorized();
    }

    public function test_list_meta_export_validate_import_and_version_bump(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $definition = [
            'root' => [
                'type' => 'group',
                'op' => 'AND',
                'children' => [
                    [
                        'type' => 'condition',
                        'left' => ['indicator' => 'close', 'params' => []],
                        'operator' => 'gt',
                        'weight_factor' => 1,
                        'right' => ['type' => 'constant', 'value' => 10],
                    ],
                ],
            ],
        ];

        $create = $this->actingAs($user)->postJson('/api/screeners', [
            'name' => 'Registry Test Screen',
            'scope' => 'all_equities',
            'definition_json' => $definition,
        ]);
        $create->assertCreated();
        $id = $create->json('data.id') ?? $create->json('id');
        $this->assertNotNull($id);

        $screener = Screener::query()->findOrFail($id);
        $this->assertNotEmpty($screener->slug);
        $this->assertSame(1, (int) $screener->artifact_version);
        $this->assertSame(1, ScreenerVersion::query()->where('screener_id', $id)->count());

        $this->actingAs($user)
            ->getJson('/api/v1/screener-registry/meta')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.counts.own', 1);

        $this->actingAs($user)
            ->getJson('/api/v1/screener-registry')
            ->assertOk()
            ->assertJsonPath('data.0.artifact_type', ArtifactType::SCREENER);

        $export = $this->actingAs($user)
            ->postJson('/api/v1/screener-registry/'.$id.'/export')
            ->assertOk()
            ->json('data');

        $this->assertSame('screener', $export['artifact_type']);
        $this->assertArrayHasKey('root', $export['definition']);

        $this->actingAs($user)
            ->postJson('/api/v1/screener-registry/validate', $export)
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $importPayload = $export;
        $importPayload['slug'] = 'imported_copy';
        $importPayload['name'] = 'Imported Copy';

        $this->actingAs($user)
            ->postJson('/api/v1/screener-registry/import', $importPayload)
            ->assertCreated()
            ->assertJsonPath('data.slug', 'imported_copy');

        $this->assertSame(2, Screener::query()->where('profile_id', $profile->id)->count());

        $definition['root']['children'][0]['right']['value'] = 20;
        $this->actingAs($user)
            ->putJson('/api/screeners/'.$id, [
                'name' => 'Registry Test Screen',
                'scope' => 'all_equities',
                'definition_json' => $definition,
            ])
            ->assertOk();

        $screener->refresh();
        $this->assertSame(2, (int) $screener->artifact_version);
        $this->assertSame(2, ScreenerVersion::query()->where('screener_id', $id)->count());

        $this->actingAs($user)
            ->getJson('/api/v1/screener-registry/'.$id.'/versions')
            ->assertOk()
            ->assertJsonPath('meta.count', 2);
    }

    public function test_shared_screener_appears_in_registry_same_user_only(): void
    {
        $owner = User::factory()->create();
        $ownerProfile = $this->defaultPortfolioFor($owner);
        $otherProfile = $this->createPortfolioProfile($owner, 'Secondary', false);
        $stranger = User::factory()->create();
        $this->defaultPortfolioFor($stranger);

        $definition = [
            'root' => [
                'type' => 'group',
                'op' => 'AND',
                'children' => [
                    [
                        'type' => 'condition',
                        'left' => ['indicator' => 'close', 'params' => []],
                        'operator' => 'gt',
                        'weight_factor' => 1,
                        'right' => ['type' => 'constant', 'value' => 1],
                    ],
                ],
            ],
        ];

        $create = $this->actingAs($owner)->withHeader('X-Profile-Id', (string) $ownerProfile->id)->postJson('/api/screeners', [
            'name' => 'Shared Screen',
            'scope' => 'all_equities',
            'definition_json' => $definition,
            'is_shared' => true,
        ]);
        $create->assertCreated();
        $sourceId = (int) $create->json('data.id');

        $list = $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->getJson('/api/v1/screener-registry?ownership=shared')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($list);
        $this->assertSame('shared', $list[0]['metadata']['ownership'] ?? null);
        $this->assertArrayNotHasKey('source_profile', $list[0]['metadata'] ?? []);
        $this->assertSame($sourceId, (int) ($list[0]['artifact_id'] ?? 0));

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->getJson('/api/v1/screener-registry/'.$sourceId)
            ->assertOk()
            ->assertJsonPath('data.metadata.ownership', 'shared')
            ->assertJsonPath('data.metadata.read_only', true);

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->postJson('/api/v1/screener-registry/shared/'.$sourceId.'/import')
            ->assertCreated();

        $this->assertTrue(
            Screener::query()->where('profile_id', $otherProfile->id)->where('name', 'like', 'Shared Screen%')->exists()
        );
        $this->assertTrue(
            Screener::query()->where('profile_id', $ownerProfile->id)->where('is_shared', true)->exists()
        );

        $this->actingAs($stranger)
            ->withHeader('X-Profile-Id', (string) $this->defaultPortfolioFor($stranger)->id)
            ->getJson('/api/v1/screener-registry?ownership=shared')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($stranger)
            ->withHeader('X-Profile-Id', (string) $this->defaultPortfolioFor($stranger)->id)
            ->getJson('/api/v1/screener-registry/'.$sourceId)
            ->assertNotFound();

        $this->actingAs($stranger)
            ->withHeader('X-Profile-Id', (string) $this->defaultPortfolioFor($stranger)->id)
            ->postJson('/api/v1/screener-registry/shared/'.$sourceId.'/import')
            ->assertNotFound();
    }
}
