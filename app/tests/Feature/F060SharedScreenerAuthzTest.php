<?php

namespace Tests\Feature;

use App\Engines\Discovery\DiscoveryEngine;
use App\Models\Screener;
use App\Models\User;
use App\Services\StrategyEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * F060 same-user shared screener AuthZ matrix.
 */
class F060SharedScreenerAuthzTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{root: array<string, mixed>}
     */
    private function simpleDefinition(): array
    {
        return [
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
    }

    /**
     * @return array{owner: User, ownerProfile: \App\Models\PortfolioProfile, otherProfile: \App\Models\PortfolioProfile, sharedId: int}
     */
    private function seedSameUserShared(string $name = 'Momentum Screener'): array
    {
        $owner = User::factory()->create();
        $ownerProfile = $this->defaultPortfolioFor($owner);
        $otherProfile = $this->createPortfolioProfile($owner, 'Secondary', false);

        $create = $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->postJson('/api/screeners', [
                'name' => $name,
                'scope' => 'holdings',
                'is_shared' => true,
                'definition_json' => $this->simpleDefinition(),
            ]);
        $create->assertCreated();

        return [
            'owner' => $owner,
            'ownerProfile' => $ownerProfile,
            'otherProfile' => $otherProfile,
            'sharedId' => (int) $create->json('data.id'),
        ];
    }

    public function test_same_user_shared_list_get_import_allowed(): void
    {
        $ctx = $this->seedSameUserShared();
        $owner = $ctx['owner'];
        $other = $ctx['otherProfile'];
        $sharedId = $ctx['sharedId'];

        $list = $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $other->id)
            ->getJson('/api/screeners/shared')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $list);
        $this->assertSame($sharedId, (int) $list[0]['id']);
        $this->assertSame(['id', 'name', 'definition_json'], array_keys($list[0]));

        $import = $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $other->id)
            ->postJson("/api/screeners/shared/{$sharedId}/import")
            ->assertCreated()
            ->json('data');

        $this->assertSame($other->id, (int) $import['profile_id']);
        $this->assertFalse((bool) $import['is_shared']);
        $this->assertNotSame($sharedId, (int) $import['id']);
    }

    public function test_cross_user_shared_list_get_import_denied(): void
    {
        $ctx = $this->seedSameUserShared();
        $stranger = User::factory()->create();
        $strangerProfile = $this->defaultPortfolioFor($stranger);
        $sharedId = $ctx['sharedId'];

        $this->actingAs($stranger)
            ->withHeader('X-Profile-Id', (string) $strangerProfile->id)
            ->getJson('/api/screeners/shared')
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('data', []);

        $this->actingAs($stranger)
            ->withHeader('X-Profile-Id', (string) $strangerProfile->id)
            ->postJson("/api/screeners/shared/{$sharedId}/import")
            ->assertNotFound();

        $this->actingAs($stranger)
            ->withHeader('X-Profile-Id', (string) $strangerProfile->id)
            ->getJson('/api/v1/screener-registry?ownership=shared')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($stranger)
            ->withHeader('X-Profile-Id', (string) $strangerProfile->id)
            ->getJson('/api/v1/screener-registry/'.$sharedId)
            ->assertNotFound();

        $this->actingAs($stranger)
            ->withHeader('X-Profile-Id', (string) $strangerProfile->id)
            ->postJson('/api/v1/screener-registry/shared/'.$sharedId.'/import')
            ->assertNotFound();
    }

    public function test_private_foreign_profile_access_denied(): void
    {
        $owner = User::factory()->create();
        $ownerProfile = $this->defaultPortfolioFor($owner);
        $otherProfile = $this->createPortfolioProfile($owner, 'Secondary', false);

        $create = $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->postJson('/api/screeners', [
                'name' => 'Private Screen',
                'scope' => 'holdings',
                'is_shared' => false,
                'definition_json' => $this->simpleDefinition(),
            ]);
        $create->assertCreated();
        $id = (int) $create->json('data.id');

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->getJson('/api/screeners/shared')
            ->assertOk()
            ->assertJsonPath('count', 0);

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->postJson("/api/screeners/shared/{$id}/import")
            ->assertNotFound();

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->getJson("/api/screeners/{$id}")
            ->assertNotFound();

        $stranger = User::factory()->create();
        $this->defaultPortfolioFor($stranger);
        $this->actingAs($stranger)
            ->getJson("/api/screeners/{$id}")
            ->assertNotFound();
    }

    public function test_owner_write_delete_preserved_non_owner_denied(): void
    {
        $ctx = $this->seedSameUserShared();
        $owner = $ctx['owner'];
        $ownerProfile = $ctx['ownerProfile'];
        $other = $ctx['otherProfile'];
        $sharedId = $ctx['sharedId'];

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->putJson("/api/screeners/{$sharedId}", [
                'name' => 'Momentum Screener Renamed',
                'scope' => 'holdings',
                'is_shared' => true,
                'definition_json' => $this->simpleDefinition(),
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Momentum Screener Renamed');

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $other->id)
            ->putJson("/api/screeners/{$sharedId}", [
                'name' => 'Hacked',
                'scope' => 'holdings',
                'definition_json' => $this->simpleDefinition(),
            ])
            ->assertNotFound();

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $other->id)
            ->deleteJson("/api/screeners/{$sharedId}")
            ->assertNotFound();

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->deleteJson("/api/screeners/{$sharedId}")
            ->assertOk();

        $this->assertFalse(Screener::query()->where('id', $sharedId)->exists());
    }

    public function test_admin_does_not_bypass_same_user_boundary(): void
    {
        $ctx = $this->seedSameUserShared();
        $admin = User::factory()->create(['is_admin' => true]);
        $adminProfile = $this->defaultPortfolioFor($admin);
        $sharedId = $ctx['sharedId'];

        $this->actingAs($admin)
            ->withHeader('X-Profile-Id', (string) $adminProfile->id)
            ->getJson('/api/screeners/shared')
            ->assertOk()
            ->assertJsonPath('count', 0);

        $this->actingAs($admin)
            ->withHeader('X-Profile-Id', (string) $adminProfile->id)
            ->postJson("/api/screeners/shared/{$sharedId}/import")
            ->assertNotFound();

        $this->actingAs($admin)
            ->withHeader('X-Profile-Id', (string) $adminProfile->id)
            ->getJson('/api/v1/screener-registry/'.$sharedId)
            ->assertNotFound();
    }

    public function test_registry_classic_parity_same_user(): void
    {
        $ctx = $this->seedSameUserShared();
        $owner = $ctx['owner'];
        $other = $ctx['otherProfile'];
        $sharedId = $ctx['sharedId'];

        $classic = $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $other->id)
            ->getJson('/api/screeners/shared')
            ->assertOk()
            ->json('data');

        $registry = $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $other->id)
            ->getJson('/api/v1/screener-registry?ownership=shared')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $classic);
        $this->assertCount(1, $registry);
        $this->assertSame($sharedId, (int) $classic[0]['id']);
        $this->assertSame($sharedId, (int) $registry[0]['artifact_id']);
    }

    public function test_eligibility_same_user_allowed_cross_user_denied(): void
    {
        $ctx = $this->seedSameUserShared();
        $owner = $ctx['owner'];
        $other = $ctx['otherProfile'];
        $sharedId = $ctx['sharedId'];
        $eligibility = app(StrategyEligibilityService::class);

        $config = [
            'eligibility_sources' => [
                [
                    'enabled' => true,
                    'screener_id' => $sharedId,
                    'screener_name' => 'Momentum Screener',
                    'priority' => 1,
                ],
            ],
        ];

        $sameUser = $eligibility->resolve($other, $config);
        $this->assertSame('NO_RUN', $sameUser['screeners'][0]['status'] ?? null);

        $stranger = User::factory()->create();
        $strangerProfile = $this->defaultPortfolioFor($stranger);
        $cross = $eligibility->resolve($strangerProfile, $config);
        $this->assertSame('MISSING', $cross['screeners'][0]['status'] ?? null);

        $this->assertTrue(
            Screener::query()->ownedOrSameUserShared($other)->where('id', $sharedId)->exists()
        );
        $this->assertFalse(
            Screener::query()->ownedOrSameUserShared($strangerProfile)->where('id', $sharedId)->exists()
        );

        unset($owner);
    }

    public function test_discovery_same_user_allowed_cross_user_denied(): void
    {
        $ctx = $this->seedSameUserShared();
        $other = $ctx['otherProfile'];
        $sharedId = $ctx['sharedId'];
        $strangerProfile = $this->defaultPortfolioFor(User::factory()->create());

        $sameIds = Screener::query()->ownedOrSameUserShared($other)->pluck('id')->all();
        $crossIds = Screener::query()->ownedOrSameUserShared($strangerProfile)->pluck('id')->all();

        $this->assertContains($sharedId, array_map('intval', $sameIds));
        $this->assertNotContains($sharedId, array_map('intval', $crossIds));

        // DiscoveryEngine::collectScreenerCandidates uses the same ownedOrSameUserShared scope.
        $engine = app(DiscoveryEngine::class);
        $method = new ReflectionMethod(DiscoveryEngine::class, 'collectScreenerCandidates');
        $method->setAccessible(true);
        $sameBucket = [];
        $method->invokeArgs($engine, [$other, &$sameBucket, 72]);
        $crossBucket = [];
        $method->invokeArgs($engine, [$strangerProfile, &$crossBucket, 72]);
        $this->assertIsArray($sameBucket);
        $this->assertIsArray($crossBucket);
    }

    public function test_import_independent_copy_rename_and_source_lifecycle(): void
    {
        $ctx = $this->seedSameUserShared('Source Screen');
        $owner = $ctx['owner'];
        $ownerProfile = $ctx['ownerProfile'];
        $other = $ctx['otherProfile'];
        $sharedId = $ctx['sharedId'];

        $imported = $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $other->id)
            ->postJson("/api/screeners/shared/{$sharedId}/import")
            ->assertCreated()
            ->json('data');
        $copyId = (int) $imported['id'];

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->putJson("/api/screeners/{$sharedId}", [
                'name' => 'Source Changed',
                'scope' => 'holdings',
                'is_shared' => true,
                'definition_json' => $this->simpleDefinition(),
            ])
            ->assertOk();

        $copy = Screener::query()->findOrFail($copyId);
        $this->assertSame('Source Screen', $copy->name);
        $this->assertSame($other->id, (int) $copy->profile_id);

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $other->id)
            ->putJson("/api/screeners/{$copyId}", [
                'name' => 'Copy Renamed',
                'scope' => 'holdings',
                'is_shared' => false,
                'definition_json' => $this->simpleDefinition(),
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Copy Renamed');

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->deleteJson("/api/screeners/{$sharedId}")
            ->assertOk();

        $this->assertFalse(Screener::query()->where('id', $sharedId)->exists());
        $this->assertTrue(Screener::query()->where('id', $copyId)->exists());
        $this->assertSame('Copy Renamed', Screener::query()->findOrFail($copyId)->name);
    }

    public function test_name_collision_uses_one_then_two(): void
    {
        $ctx = $this->seedSameUserShared('Momentum Screener');
        $owner = $ctx['owner'];
        $other = $ctx['otherProfile'];
        $sharedId = $ctx['sharedId'];

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $other->id)
            ->postJson('/api/screeners', [
                'name' => 'Momentum Screener',
                'scope' => 'holdings',
                'definition_json' => $this->simpleDefinition(),
            ])
            ->assertCreated();

        $first = $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $other->id)
            ->postJson("/api/screeners/shared/{$sharedId}/import")
            ->assertCreated()
            ->json('data.name');
        $this->assertSame('Momentum Screener (1)', $first);

        $second = $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $other->id)
            ->postJson("/api/screeners/shared/{$sharedId}/import")
            ->assertCreated()
            ->json('data.name');
        $this->assertSame('Momentum Screener (2)', $second);
    }

    public function test_same_profile_own_list_unaffected(): void
    {
        $ctx = $this->seedSameUserShared();
        $owner = $ctx['owner'];
        $ownerProfile = $ctx['ownerProfile'];

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->getJson('/api/screeners')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.is_shared', true);

        $this->actingAs($owner)
            ->withHeader('X-Profile-Id', (string) $ownerProfile->id)
            ->getJson('/api/screeners/shared')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }
}
