<?php

namespace Tests\Feature;

use App\Engines\Strategy\FactoryMomentumStrategy;
use App\Engines\Strategy\MinerviniTrendTemplateScreener;
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
        $this->assertFalse((bool) ($created['metadata']['is_selected'] ?? false));

        // Original Minervini still active
        $this->assertSame(
            1,
            TradingStrategy::query()->where('profile_id', $profile->id)->where('status', TradingStrategy::STATUS_ACTIVE)->count()
        );

        $this->actingAs($user)
            ->postJson('/api/v1/strategy-registry/'.$created['artifact_id'].'/activate')
            ->assertOk()
            ->assertJsonPath('data.metadata.status', 'active')
            ->assertJsonPath('data.metadata.is_selected', true)
            ->assertJsonPath('data.metadata.is_enabled', true);

        $this->assertSame(
            2,
            TradingStrategy::query()->where('profile_id', $profile->id)->where('status', TradingStrategy::STATUS_ACTIVE)->count()
        );
        $this->assertTrue(
            TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('id', $created['artifact_id'])
                ->where('status', TradingStrategy::STATUS_ACTIVE)
                ->exists()
        );
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
            ->assertJsonPath('data.enabled_count', 2)
            ->assertJsonPath('data.selected.slug', 'swing_import_test');

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

    public function test_archive_sets_archived_and_activate_of_another_does_not_unarchive(): void
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

        $this->actingAs($user)
            ->postJson('/api/v1/strategy-registry/'.$created['artifact_id'].'/activate')
            ->assertOk()
            ->assertJsonPath('data.metadata.status', 'active');

        $factory = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('factory_key', FactoryMomentumStrategy::FACTORY_KEY)
            ->firstOrFail();
        $factoryVersionId = $factory->active_version_id;

        $this->actingAs($user)
            ->postJson('/api/v1/strategy-registry/'.$factory->id.'/archive')
            ->assertOk()
            ->assertJsonPath('data.metadata.status', 'archived')
            ->assertJsonPath('data.metadata.is_enabled', false);

        $factory->refresh();
        $this->assertSame(TradingStrategy::STATUS_ARCHIVED, $factory->status);
        $this->assertSame($factoryVersionId, $factory->active_version_id);

        $this->actingAs($user)
            ->postJson('/api/v1/strategy-registry/'.$created['artifact_id'].'/activate')
            ->assertOk()
            ->assertJsonPath('data.metadata.status', 'active');

        $factory->refresh();
        $this->assertSame(TradingStrategy::STATUS_ARCHIVED, $factory->status);
        $this->assertSame($factoryVersionId, $factory->active_version_id);

        $imported = TradingStrategy::query()->where('id', $created['artifact_id'])->firstOrFail();
        $this->assertSame(TradingStrategy::STATUS_ACTIVE, $imported->status);
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

        $this->assertNotEquals($factory->id, (int) $created['artifact_id']);
        $this->assertSame('Strategy B', $created['name']);
        $this->assertSame('draft', $created['metadata']['status']);
        $this->assertSame('user', $created['metadata']['origin']);
        $this->assertNull($created['metadata']['factory_key']);
        $this->assertFalse((bool) ($created['metadata']['is_enabled'] ?? false));
        $this->assertSame('Second concurrent strategy', $created['metadata']['description']);

        $row = TradingStrategy::query()->whereKey($created['artifact_id'])->firstOrFail();
        $this->assertSame($profile->id, $row->profile_id);
        $this->assertSame(TradingStrategy::STATUS_DRAFT, $row->status);
        $this->assertFalse((bool) $row->is_factory);
        $this->assertNull($row->factory_key);
        $this->assertSame(1, TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('status', TradingStrategy::STATUS_ACTIVE)
            ->count());
    }

    public function test_two_created_strategies_can_be_enabled_and_editing_one_does_not_modify_the_other(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $this->getJson('/api/v1/strategy-registry')->assertOk();

        $factory = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('factory_key', FactoryMomentumStrategy::FACTORY_KEY)
            ->firstOrFail();
        $factoryName = $factory->name;

        $b = $this->postJson('/api/v1/strategy-registry', ['name' => 'Strategy B'])
            ->assertCreated()
            ->json('data');
        $c = $this->postJson('/api/v1/strategy-registry', ['name' => 'Strategy C'])
            ->assertCreated()
            ->json('data');

        $this->assertNotEquals($b['artifact_id'], $c['artifact_id']);
        $this->assertSame(3, TradingStrategy::query()->where('profile_id', $profile->id)->count());

        $this->postJson('/api/v1/strategy-registry/'.$b['artifact_id'].'/activate')
            ->assertOk()
            ->assertJsonPath('data.metadata.status', 'active');
        $this->postJson('/api/v1/strategy-registry/'.$c['artifact_id'].'/activate')
            ->assertOk()
            ->assertJsonPath('data.metadata.status', 'active');

        $this->assertSame(
            3,
            TradingStrategy::query()->where('profile_id', $profile->id)->where('status', TradingStrategy::STATUS_ACTIVE)->count()
        );
        $this->getJson('/api/v1/strategy-registry/selection')
            ->assertOk()
            ->assertJsonPath('data.rule', 'multiple_enabled_per_portfolio')
            ->assertJsonPath('data.enabled_count', 3);

        $payloadB = $this->getJson('/api/v1/strategy?strategy_id='.$b['artifact_id'])
            ->assertOk()
            ->json('data');
        $configB = $payloadB['config'];
        $configB['thresholds']['open_position'] = 77.0;

        $this->putJson('/api/v1/strategy', [
            'strategy_id' => (int) $b['artifact_id'],
            'name' => 'Strategy B edited',
            'description' => $payloadB['description'] ?? '',
            'config' => $configB,
        ])->assertOk();

        $savedB = $this->getJson('/api/v1/strategy?strategy_id='.$b['artifact_id'])->assertOk()->json('data');
        $savedA = $this->getJson('/api/v1/strategy?strategy_id='.$factory->id)->assertOk()->json('data');
        $savedC = $this->getJson('/api/v1/strategy?strategy_id='.$c['artifact_id'])->assertOk()->json('data');

        $this->assertSame('Strategy B edited', $savedB['name']);
        $this->assertEqualsWithDelta(77.0, (float) $savedB['thresholds']['open_position'], 0.0001);
        $this->assertSame($factoryName, $savedA['name']);
        $this->assertNotEquals(77.0, (float) $savedA['thresholds']['open_position']);
        $this->assertSame('Strategy C', $savedC['name']);

        $this->postJson('/api/v1/strategy-registry/'.$b['artifact_id'].'/archive')
            ->assertOk()
            ->assertJsonPath('data.metadata.status', 'archived');

        $factory->refresh();
        $this->assertSame(TradingStrategy::STATUS_ACTIVE, $factory->status);
        $this->assertSame(
            TradingStrategy::STATUS_ACTIVE,
            TradingStrategy::query()->whereKey($c['artifact_id'])->value('status')
        );
        $this->assertSame(
            TradingStrategy::STATUS_ARCHIVED,
            TradingStrategy::query()->whereKey($b['artifact_id'])->value('status')
        );
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
