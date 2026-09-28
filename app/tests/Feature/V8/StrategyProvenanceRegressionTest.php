<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategyVersion;
use App\Models\User;
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
}
