<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyImmutableSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_save_forks_version_and_preserves_recommendation_pin(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $active = $this->getJson('/api/v1/strategy')->assertOk()->json('data');
        $versionId = (int) $active['version_id'];
        $strategyId = (int) $active['id'];
        $config = $active['config'];

        $stock = Stock::query()->create([
            'symbol' => 'TST'.random_int(100, 999),
            'exchange' => 'NSE',
            'name' => 'Test Co',
            'is_active' => true,
        ]);
        TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $versionId,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PUBLISHED,
            'priority' => 1,
            'strategy_score' => 75,
            'confidence' => 0.7,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'generated_at' => now(),
        ]);

        $indicators = $config['indicators'];
        $indicators[0]['weight'] = ((float) ($indicators[0]['weight'] ?? 10)) + 1.0;
        $config['indicators'] = $indicators;

        $this->putJson('/api/v1/strategy?strategy_id='.$strategyId, [
            'config' => $config,
            'change_notes' => 'Adjust weights',
        ])->assertOk()
            ->assertJsonPath('data.version', 2);

        $pinned = TradingStrategyVersion::query()->findOrFail($versionId);
        $this->assertSame(TradingStrategyVersion::STATUS_SUPERSEDED, $pinned->status);
        $this->assertNotSame(
            $pinned->definition_hash ?? '',
            TradingStrategyVersion::query()->findOrFail((int) $this->getJson('/api/v1/strategy?strategy_id='.$strategyId)->json('data.version_id'))->definition_hash ?? ''
        );

        $this->assertDatabaseHas('portfolio_tos_recommendations', [
            'strategy_version_id' => $versionId,
        ]);
    }

    public function test_identical_save_does_not_fork_version(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $active = $this->getJson('/api/v1/strategy')->assertOk()->json('data');
        $versionId = (int) $active['version_id'];
        $strategyId = (int) $active['id'];

        $this->putJson('/api/v1/strategy?strategy_id='.$strategyId, [
            'config' => $active['config'],
        ])->assertOk()
            ->assertJsonPath('data.version_id', $versionId);
    }
}
