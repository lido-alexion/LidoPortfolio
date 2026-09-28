<?php

namespace Tests\Feature\V8;

use App\Models\TradingStrategy;
use App\Models\User;
use App\Engines\Strategy\MinerviniTrendTemplateScreener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FEAT-064 — strategy registry JSON import creates account-owned runtime Strategies.
 */
class StrategyRegistryImportRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_import_persists_runtime_strategy(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->getJson('/api/v1/strategy-registry')->assertOk();

        $payload = [
            'schema_version' => '1.0',
            'artifact_type' => 'strategy',
            'slug' => 'v8_strategy_import',
            'name' => 'V8 Strategy Import',
            'artifact_version' => 1,
            'metadata' => [
                'scope' => 'portfolio',
                'status' => 'draft',
                'origin' => 'user',
                'description' => 'import test',
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

        $this->postJson('/api/v1/strategy-registry/import', $payload)
            ->assertCreated()
            ->assertJsonPath('data.metadata.origin', 'imported')
            ->assertJsonMissingPath('data.library_path');

        $strategy = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('slug', 'v8_strategy_import')
            ->first();
        $this->assertNotNull($strategy);
        $this->assertNull($strategy->reusable_artifact_id);
        $this->assertSame(TradingStrategy::STATUS_DRAFT, $strategy->status);
    }
}
