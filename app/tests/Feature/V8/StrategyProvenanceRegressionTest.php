<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\ScreenerRun;
use App\Models\ScreenerRunHit;
use App\Models\StrategyScreener;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\Screener\ScreenerVersioningService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FEAT-064 WP-10 — historical recommendation still resolves pinned strategy version after later edits.
 */
class StrategyProvenanceRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommendation_resolves_original_strategy_version_after_save(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $active = $this->getJson('/api/v1/strategy')->assertOk()->json('data');
        $versionId = (int) $active['version_id'];
        $strategyId = (int) $active['id'];
        $originalWeight = (float) ($active['config']['indicators'][0]['weight'] ?? 0);

        $stock = Stock::query()->create([
            'symbol' => 'PRV',
            'exchange' => 'NSE',
            'name' => 'Prove',
            'is_active' => true,
        ]);
        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $versionId,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PUBLISHED,
            'priority' => 1,
            'strategy_score' => 70,
            'confidence' => 0.7,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'generated_at' => now(),
        ]);

        $config = $active['config'];
        $config['indicators'][0]['weight'] = $originalWeight + 2.0;
        $config['indicators'][1]['weight'] = max(0, (float) ($config['indicators'][1]['weight'] ?? 0) - 2.0);

        $this->putJson('/api/v1/strategy?strategy_id='.$strategyId, [
            'config' => $config,
            'change_notes' => 'Provenance regression edit',
        ])->assertOk();

        $pinned = TradingStrategyVersion::query()->findOrFail($versionId);
        $pinnedWeight = (float) ($pinned->config_json['indicators'][0]['weight'] ?? -1);
        $this->assertSame($originalWeight, $pinnedWeight);

        $rec->refresh();
        $this->assertSame($versionId, (int) $rec->strategy_version_id);
        $this->assertSame($originalWeight, (float) ($rec->strategyVersion->config_json['indicators'][0]['weight'] ?? -1));
    }

    public function test_strategy_execution_uses_exact_pinned_screener_run_version(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $screener = $this->postJson('/api/screeners', [
            'name' => 'Pinned ROC',
            'scope' => 'all_equities',
            'definition_json' => ['root' => [
                'type' => 'condition',
                'left' => ['indicator' => 'roc'],
                'operator' => 'gt',
                'right' => ['type' => 'constant', 'value' => 15],
            ]],
        ])->assertCreated()->json('data');
        $strategy = $this->postJson('/api/v1/strategies', ['name' => 'Pinned strategy'])
            ->assertCreated()->json('data');
        $editor = $this->getJson('/api/v1/strategy?strategy_id='.$strategy['id'])->json('data');
        $editor['config']['eligibility_sources'] = [[
            'screener_id' => (int) $screener['id'], 'enabled' => true, 'priority' => 1,
        ]];
        $saved = $this->putJson('/api/v1/strategy?strategy_id='.$strategy['id'], [
            'config' => $editor['config'],
        ])->assertOk()->json('data');
        $version = TradingStrategyVersion::query()->findOrFail((int) $saved['version_id']);
        $link = StrategyScreener::query()->where('strategy_version_id', $version->id)->firstOrFail();

        $stockV1 = Stock::query()->create(['symbol' => 'PIN1', 'exchange' => 'NSE', 'name' => 'Pinned one', 'is_active' => true]);
        $stockV2 = Stock::query()->create(['symbol' => 'PIN2', 'exchange' => 'NSE', 'name' => 'Pinned two', 'is_active' => true]);
        $runV1 = ScreenerRun::query()->create([
            'screener_id' => $screener['id'], 'screener_version_id' => $link->screener_version_id,
            'triggered_by' => 'test',
            'status' => 'completed', 'started_at' => now()->subMinute(), 'finished_at' => now(),
        ]);
        ScreenerRunHit::query()->create(['run_id' => $runV1->id, 'stock_id' => $stockV1->id, 'symbol' => 'PIN1', 'exchange' => 'NSE', 'name' => 'Pinned one']);

        $screenerModel = \App\Models\Screener::query()->findOrFail($screener['id']);
        $screenerModel->definition_json = ['root' => ['type' => 'condition', 'changed' => true]];
        app(ScreenerVersioningService::class)->afterUpdate($screenerModel, $screenerModel->definition_hash, 'V2');
        $versionV2 = $screenerModel->fresh()->artifact_version;
        $versionV2Id = \App\Models\ScreenerVersion::query()->where('screener_id', $screener['id'])->where('version', $versionV2)->value('id');
        $runV2 = ScreenerRun::query()->create([
            'screener_id' => $screener['id'], 'screener_version_id' => $versionV2Id,
            'triggered_by' => 'test',
            'status' => 'completed', 'started_at' => now()->subMinute(), 'finished_at' => now(),
        ]);
        ScreenerRunHit::query()->create(['run_id' => $runV2->id, 'stock_id' => $stockV2->id, 'symbol' => 'PIN2', 'exchange' => 'NSE', 'name' => 'Pinned two']);

        $resolved = app(StrategyEligibilityService::class)->resolve($profile, $version->config_json, $version);
        $this->assertSame([$stockV1->id], $resolved['eligible_security_ids']);
        $this->assertSame($runV1->id, $resolved['screeners'][0]['run_id']);
        $this->assertSame($link->screener_version_id, $resolved['screeners'][0]['screener_version_id']);
    }

    public function test_activation_does_not_mutate_existing_strategy_version_or_repin_dependencies(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);
        $active = $this->getJson('/api/v1/strategy')->json('data');
        $version = TradingStrategyVersion::query()->findOrFail($active['version_id']);
        $beforeConfig = $version->config_json;
        $beforeHash = $version->definition_hash;
        $beforeLinks = StrategyScreener::query()->where('strategy_version_id', $version->id)->get()->toArray();

        app(StrategyRegistrySupport::class)->archive($profile, $version->strategy);
        app(StrategyRegistrySupport::class)->activate($profile, $version->strategy->fresh());

        $version->refresh();
        $this->assertSame($beforeConfig, $version->config_json);
        $this->assertSame($beforeHash, $version->definition_hash);
        $this->assertSame($beforeLinks, StrategyScreener::query()->where('strategy_version_id', $version->id)->get()->toArray());
    }

    public function test_last_active_strategy_can_be_archived(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);
        $active = $this->getJson('/api/v1/strategy')->json('data');
        $strategy = \App\Models\TradingStrategy::query()->findOrFail($active['id']);

        $archived = app(StrategyRegistrySupport::class)->archive($profile, $strategy);

        $this->assertSame(\App\Models\TradingStrategy::STATUS_ARCHIVED, $archived->status);
    }
}
