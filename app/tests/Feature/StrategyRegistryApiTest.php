<?php

namespace Tests\Feature;

use App\Engines\Strategy\FactoryMomentumStrategy;
use App\Engines\Strategy\MinerviniTrendTemplateScreener;
use App\Models\ReusableArtifact;
use App\Models\TradingStrategy;
use App\Models\User;
use App\Services\Artifacts\ArtifactType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyRegistryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_strategy_registry(): void
    {
        $this->getJson('/api/v1/strategy-registry')->assertUnauthorized();
    }

    public function test_minervini_auto_migrates_and_export_is_portable(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);

        $list = $this->actingAs($user)
            ->getJson('/api/v1/strategy-registry')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($list);
        $active = collect($list)->first(fn ($row) => ($row['metadata']['status'] ?? '') === 'active');
        $this->assertNotNull($active);
        $this->assertSame('momentum_strategy', $active['slug']);
        $this->assertSame(FactoryMomentumStrategy::FACTORY_KEY, $active['metadata']['factory_key']);

        $export = $this->actingAs($user)
            ->postJson('/api/v1/strategy-registry/'.$active['artifact_id'].'/export')
            ->assertOk()
            ->json('data');

        $this->assertSame(ArtifactType::STRATEGY, $export['artifact_type']);
        $sources = $export['definition']['eligibility_sources'] ?? [];
        $this->assertNotEmpty($sources);
        foreach ($sources as $src) {
            $this->assertArrayNotHasKey('screener_id', $src);
            $this->assertTrue(
                isset($src['screener_slug']) || isset($src['screener_factory_key']),
                'Expected portable screener ref'
            );
        }

        $this->actingAs($user)
            ->getJson('/api/v1/strategy')
            ->assertOk()
            ->assertJsonPath('data.factory_key', FactoryMomentumStrategy::FACTORY_KEY);
    }

    public function test_validate_import_activate_and_selection(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        // Ensure Minervini exists (and its screener) so import can resolve refs
        $this->actingAs($user)->getJson('/api/v1/strategy-registry')->assertOk();

        $payload = [
            'schema_version' => '1.0',
            'artifact_type' => 'strategy',
            'slug' => 'swing_import_test',
            'name' => 'Swing Import Test',
            'artifact_version' => 1,
            'metadata' => [
                'scope' => 'portfolio',
                'status' => 'draft',
                'origin' => 'user',
                'description' => 'test import',
                'intent' => 'test',
                'summary' => 'test',
                'tags' => ['test'],
            ],
            'definition' => [
                'eligibility_sources' => [
                    [
                        'screener_slug' => MinerviniTrendTemplateScreener::FACTORY_KEY,
                        'screener_factory_key' => MinerviniTrendTemplateScreener::FACTORY_KEY,
                        'enabled' => true,
                        'priority' => 1,
                    ],
                ],
                'scoring_model' => [
                    ['key' => 'relative_strength', 'enabled' => true, 'weight' => 50, 'minimum' => 70, 'maximum' => null, 'parameters' => []],
                    ['key' => 'momentum_score', 'enabled' => true, 'weight' => 50, 'minimum' => 60, 'maximum' => null, 'parameters' => []],
                ],
            ],
            'dependencies' => [],
        ];

        $this->actingAs($user)
            ->postJson('/api/v1/strategy-registry/validate', $payload)
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $created = $this->actingAs($user)
            ->postJson('/api/v1/strategy-registry/import', $payload)
            ->assertCreated()
            ->json('data');

        $this->assertSame('draft', $created['metadata']['status']);
        $this->assertSame('imported', $created['metadata']['origin']);
        $this->assertNotEmpty($created['artifact_uuid']);

        // Original Minervini still active
        $this->assertSame(
            1,
            TradingStrategy::query()->where('profile_id', $profile->id)->where('status', TradingStrategy::STATUS_ACTIVE)->count()
        );

        $this->assertSame(
            1,
            TradingStrategy::query()->where('profile_id', $profile->id)->where('status', TradingStrategy::STATUS_ACTIVE)->count()
        );
        $this->assertDatabaseHas('portfolio_reusable_artifacts', [
            'artifact_uuid' => $created['artifact_uuid'],
            'owner_user_id' => $user->id,
            'artifact_type' => ArtifactType::STRATEGY,
        ]);
        $this->assertTrue(
            TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('factory_key', FactoryMomentumStrategy::FACTORY_KEY)
                ->where('status', TradingStrategy::STATUS_ACTIVE)
                ->exists()
        );

        $this->actingAs($user)
            ->getJson('/api/v1/strategy-registry/selection')
            ->assertOk()
            ->assertJsonPath('data.rule', 'multiple_enabled_per_portfolio')
            ->assertJsonPath('data.enabled_count', 1)
            ->assertJsonPath('data.selected.slug', 'momentum_strategy');

        $this->actingAs($user)
            ->getJson('/api/v1/strategy-registry/meta')
            ->assertOk()
            ->assertJsonPath('data.selection_rule', 'multiple_enabled_per_portfolio')
            ->assertJsonPath('data.enablement_rule', 'multiple_enabled_per_portfolio');
    }

    public function test_rejects_embedded_screener_tree(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);

        $payload = [
            'schema_version' => '1.0',
            'artifact_type' => 'strategy',
            'slug' => 'bad',
            'name' => 'Bad',
            'artifact_version' => 1,
            'metadata' => ['scope' => 'portfolio', 'status' => 'draft', 'origin' => 'user', 'description' => 'x'],
            'definition' => [
                'eligibility_sources' => [
                    [
                        'screener_slug' => 'x',
                        'definition' => ['root' => ['type' => 'group', 'op' => 'AND', 'children' => []]],
                    ],
                ],
                'scoring_model' => [
                    ['key' => 'relative_strength', 'enabled' => true, 'weight' => 100, 'minimum' => null, 'maximum' => null, 'parameters' => []],
                ],
            ],
        ];

        $this->actingAs($user)
            ->postJson('/api/v1/strategy-registry/import', $payload)
            ->assertStatus(422);
    }

    public function test_import_draft_does_not_change_existing_runtime_lifecycle(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)->getJson('/api/v1/strategy-registry')->assertOk();

        $payload = [
            'schema_version' => '1.0',
            'artifact_type' => 'strategy',
            'slug' => 'archive_peer_test',
            'name' => 'Archive Peer Test',
            'artifact_version' => 1,
            'metadata' => [
                'scope' => 'portfolio',
                'status' => 'draft',
                'origin' => 'user',
                'description' => 'archive test',
                'intent' => 'test',
                'summary' => 'test',
                'tags' => ['test'],
            ],
            'definition' => [
                'eligibility_sources' => [
                    [
                        'screener_slug' => MinerviniTrendTemplateScreener::FACTORY_KEY,
                        'screener_factory_key' => MinerviniTrendTemplateScreener::FACTORY_KEY,
                        'enabled' => true,
                        'priority' => 1,
                    ],
                ],
                'scoring_model' => [
                    ['key' => 'relative_strength', 'enabled' => true, 'weight' => 50, 'minimum' => 70, 'maximum' => null, 'parameters' => []],
                    ['key' => 'momentum_score', 'enabled' => true, 'weight' => 50, 'minimum' => 60, 'maximum' => null, 'parameters' => []],
                ],
            ],
            'dependencies' => [],
        ];

        $created = $this->actingAs($user)
            ->postJson('/api/v1/strategy-registry/import', $payload)
            ->assertCreated()
            ->json('data');

        $factory = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('factory_key', FactoryMomentumStrategy::FACTORY_KEY)
            ->firstOrFail();
        $factoryVersionId = $factory->active_version_id;

        $factory->refresh();
        $this->assertSame(TradingStrategy::STATUS_ACTIVE, $factory->status);
        $this->assertSame($factoryVersionId, $factory->active_version_id);
        $this->assertSame('draft', $created['status']);
        $this->assertSame(1, TradingStrategy::query()->where('profile_id', $profile->id)->count());
    }

    public function test_create_from_default_produces_distinct_draft_without_json(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)->getJson('/api/v1/strategy-registry')->assertOk();

        $factory = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('factory_key', FactoryMomentumStrategy::FACTORY_KEY)
            ->firstOrFail();

        $created = $this->actingAs($user)
            ->postJson('/api/v1/strategy-registry', [
                'name' => 'Strategy B',
                'description' => 'Second concurrent strategy',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotEmpty($created['artifact_uuid']);
        $this->assertSame('Strategy B', $created['name']);
        $this->assertSame('draft', $created['metadata']['status']);
        $this->assertSame('user', $created['metadata']['origin']);
        $row = ReusableArtifact::query()->where('artifact_uuid', $created['artifact_uuid'])->firstOrFail();
        $this->assertSame($user->id, $row->owner_user_id);
        $this->assertSame(ArtifactType::STRATEGY, $row->artifact_type);
        $this->assertSame('Second concurrent strategy', $row->versions()->sole()->content_json['metadata']['description']);
        $this->assertSame(1, TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('status', TradingStrategy::STATUS_ACTIVE)
            ->count());
    }

    public function test_two_created_strategies_are_independent_library_drafts(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $this->getJson('/api/v1/strategy-registry')->assertOk();

        $runtimeCount = TradingStrategy::query()->where('profile_id', $profile->id)->count();

        $b = $this->postJson('/api/v1/strategy-registry', ['name' => 'Strategy B'])
            ->assertCreated()
            ->json('data');
        $c = $this->postJson('/api/v1/strategy-registry', ['name' => 'Strategy C'])
            ->assertCreated()
            ->json('data');

        $this->assertNotSame($b['artifact_uuid'], $c['artifact_uuid']);
        $this->assertSame($runtimeCount, TradingStrategy::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(2, ReusableArtifact::query()
            ->where('owner_user_id', $user->id)
            ->whereIn('artifact_uuid', [$b['artifact_uuid'], $c['artifact_uuid']])
            ->count());
    }

    public function test_cannot_archive_the_last_enabled_strategy(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)->getJson('/api/v1/strategy-registry')->assertOk();

        $factory = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('factory_key', FactoryMomentumStrategy::FACTORY_KEY)
            ->firstOrFail();

        $this->actingAs($user)
            ->postJson('/api/v1/strategy-registry/'.$factory->id.'/archive')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'STRATEGY_ARCHIVE_FAILED');

        $factory->refresh();
        $this->assertSame(TradingStrategy::STATUS_ACTIVE, $factory->status);
    }
}
