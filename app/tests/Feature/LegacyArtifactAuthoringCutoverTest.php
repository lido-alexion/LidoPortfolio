<?php

namespace Tests\Feature;

use App\Models\Screener;
use App\Models\TradingStrategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyArtifactAuthoringCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_screener_create_persists_account_owned_runtime_row(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $before = Screener::query()->where('profile_id', $profile->id)->count();

        $this->actingAs($owner)->postJson('/api/screeners', [
            'name' => 'Investor Screener',
            'scope' => 'holdings',
            'definition_json' => $this->screenerDefinition(),
            'schedule_enabled' => true,
            'schedule_time' => '09:30',
            'schedule_days' => [1, 2, 3, 4, 5],
        ])->assertCreated()
            ->assertJsonPath('data.compatibility_read_only', false)
            ->assertJsonPath('data.scope', 'holdings')
            ->assertJsonMissingPath('data.library_path');

        $this->assertSame($before + 1, Screener::query()->where('profile_id', $profile->id)->count());
        $this->assertNull(Screener::query()->where('profile_id', $profile->id)->where('name', 'Investor Screener')->value('reusable_artifact_id'));
    }

    public function test_strategy_create_persists_account_owned_runtime_row(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $before = TradingStrategy::query()->where('profile_id', $profile->id)->count();

        $this->actingAs($owner)->postJson('/api/v1/strategies', [
            'name' => 'Investor Strategy',
            'description' => 'Swing',
        ])->assertCreated()
            ->assertJsonPath('data.compatibility_read_only', false)
            ->assertJsonPath('data.status', TradingStrategy::STATUS_DRAFT)
            ->assertJsonPath('data.name', 'Investor Strategy')
            ->assertJsonMissingPath('data.library_path');

        $this->assertGreaterThan($before, TradingStrategy::query()->where('profile_id', $profile->id)->count());
        $row = TradingStrategy::query()->where('profile_id', $profile->id)->where('name', 'Investor Strategy')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->reusable_artifact_id);
    }

    public function test_strategy_registry_create_and_import_persist_runtime_rows(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $this->actingAs($owner)->getJson('/api/v1/strategy-registry')->assertOk();
        $before = TradingStrategy::query()->where('profile_id', $profile->id)->count();

        $this->postJson('/api/v1/strategy-registry', [
            'name' => 'Registry Strategy',
            'description' => 'Created from the default',
        ])->assertCreated()
            ->assertJsonPath('data.metadata.status', 'draft')
            ->assertJsonMissingPath('data.library_path');

        $this->assertSame($before + 1, TradingStrategy::query()->where('profile_id', $profile->id)->count());
        $row = TradingStrategy::query()->where('profile_id', $profile->id)->where('name', 'Registry Strategy')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->reusable_artifact_id);

        $this->postJson('/api/v1/strategy-registry/import', $this->strategyEnvelope())
            ->assertCreated()
            ->assertJsonPath('data.metadata.origin', 'imported')
            ->assertJsonMissingPath('data.library_path');

        $this->assertSame($before + 2, TradingStrategy::query()->where('profile_id', $profile->id)->count());
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
