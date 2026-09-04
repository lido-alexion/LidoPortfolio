<?php

namespace Tests\Unit\Execution;

use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\Execution\RecommendationSupersessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationSupersessionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_target_change_supersedes_prior_same_strategy_symbol_intent(): void
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'Primary');
        $stock = Stock::query()->create(['symbol' => 'SUPER', 'name' => 'Super', 'exchange' => 'NSE', 'is_active' => true]);
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id, 'name' => 'Strategy', 'slug' => 'strategy',
            'definition_hash' => str_repeat('a', 64), 'status' => TradingStrategy::STATUS_ACTIVE,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id, 'version' => 1, 'config_json' => [],
            'definition_hash' => str_repeat('b', 64), 'status' => TradingStrategyVersion::STATUS_ACTIVE,
        ]);
        $make = function (float $target) use ($profile, $stock, $version): TradingRecommendation {
            return TradingRecommendation::query()->create([
                'profile_id' => $profile->id, 'security_id' => $stock->id,
                'strategy_version_id' => $version->id,
                'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
                'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
                'target_amount' => $target, 'remaining_target_amount' => $target,
                'execution_anchor_date' => now()->toDateString(),
            ]);
        };
        $old = $make(1_000);
        $replacement = $make(1_500);

        $count = app(RecommendationSupersessionService::class)
            ->supersedeMateriallyDifferentPriorIntent($replacement);

        $this->assertSame(1, $count);
        $this->assertSame(TradingRecommendation::STATUS_SUPERSEDED, $old->fresh()->status);
        $this->assertSame($replacement->id, $old->fresh()->superseded_by_id);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $replacement->fresh()->status);
    }

    public function test_same_target_does_not_replace_live_intent(): void
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'Primary');
        $stock = Stock::query()->create(['symbol' => 'SAME', 'name' => 'Same', 'exchange' => 'NSE', 'is_active' => true]);
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id, 'name' => 'Strategy', 'slug' => 'same-strategy',
            'definition_hash' => str_repeat('c', 64), 'status' => TradingStrategy::STATUS_ACTIVE,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id, 'version' => 1, 'config_json' => [],
            'definition_hash' => str_repeat('d', 64), 'status' => TradingStrategyVersion::STATUS_ACTIVE,
        ]);
        $values = [
            'profile_id' => $profile->id, 'security_id' => $stock->id, 'strategy_version_id' => $version->id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_EXECUTION, 'target_amount' => 1_000,
            'remaining_target_amount' => 1_000, 'execution_anchor_date' => now()->toDateString(),
        ];
        $old = TradingRecommendation::query()->create($values);
        $replacement = TradingRecommendation::query()->create($values);

        $this->assertSame(0, app(RecommendationSupersessionService::class)->supersedeMateriallyDifferentPriorIntent($replacement));
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $old->fresh()->status);
    }
}
