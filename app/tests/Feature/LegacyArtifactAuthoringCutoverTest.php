<?php

namespace Tests\Feature;

use App\Models\ReusableArtifact;
use App\Models\ReusableArtifactVersion;
use App\Models\Screener;
use App\Models\TradingStrategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyArtifactAuthoringCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_screener_create_produces_only_a_library_draft(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $before = Screener::query()->where('profile_id', $profile->id)->count();

        $created = $this->actingAs($owner)->postJson('/api/screeners', [
            'name' => 'Draft-only Screener',
            'scope' => 'holdings',
            'definition_json' => $this->screenerDefinition(),
            'schedule_enabled' => true,
            'schedule_time' => '09:30',
            'schedule_days' => [1, 2, 3, 4, 5],
        ])->assertCreated()
            ->assertJsonPath('data.status', ReusableArtifactVersion::STATUS_DRAFT)
            ->assertJsonPath('data.metadata.compatibility_read_only', true)
            ->json('data');

        $this->assertSame($before, Screener::query()->where('profile_id', $profile->id)->count());
        $artifact = ReusableArtifact::query()->where('artifact_uuid', $created['artifact_uuid'])->firstOrFail();
        $draft = $artifact->versions()->sole();
        $this->assertSame('screener', $artifact->artifact_type);
        $this->assertSame('holdings', $draft->content_json['metadata']['suggested_binding_settings']['scope']);
        $this->assertTrue($draft->content_json['metadata']['suggested_binding_settings']['schedule_enabled']);
    }

    public function test_legacy_strategy_create_and_import_produce_library_drafts_without_runtime_rows(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $this->actingAs($owner)->getJson('/api/v1/strategy-registry')->assertOk();
        $before = TradingStrategy::query()->where('profile_id', $profile->id)->count();

        $created = $this->postJson('/api/v1/strategy-registry', [
            'name' => 'Draft-only Strategy',
            'description' => 'Created from the default',
        ])->assertCreated()
            ->assertJsonPath('data.status', ReusableArtifactVersion::STATUS_DRAFT)
            ->json('data');

        $this->assertSame($before, TradingStrategy::query()->where('profile_id', $profile->id)->count());
        $this->assertDatabaseHas('portfolio_reusable_artifacts', [
            'artifact_uuid' => $created['artifact_uuid'],
            'artifact_type' => 'strategy',
            'owner_user_id' => $owner->id,
        ]);

        $imported = $this->postJson('/api/v1/strategy-registry/import', $this->strategyEnvelope())
            ->assertCreated()
            ->assertJsonPath('data.metadata.origin', 'imported')
            ->json('data');
        $this->assertSame('/artifact-library/'.$imported['artifact_uuid'], $imported['library_path']);
        $this->assertSame($before, TradingStrategy::query()->where('profile_id', $profile->id)->count());
    }

    /** @return array<string,mixed> */
    private function screenerDefinition(): array
    {
        return ['root' => [
            'type' => 'condition',
            'left' => ['indicator' => 'close'],
            'operator' => 'gt',
            'right' => ['type' => 'constant', 'value' => 0],
        ]];
    }

    /** @return array<string,mixed> */
    private function strategyEnvelope(): array
    {
        return [
            'schema_version' => '1.0',
            'artifact_type' => 'strategy',
            'slug' => 'imported_draft_only',
            'name' => 'Imported Draft Only',
            'metadata' => ['scope' => 'portfolio', 'status' => 'draft', 'origin' => 'user'],
            'definition' => [
                'eligibility_sources' => [],
                'scoring_model' => [[
                    'key' => 'momentum_score',
                    'enabled' => true,
                    'weight' => 100,
                    'parameters' => ['rsi_period' => 14],
                ]],
            ],
        ];
    }
}
