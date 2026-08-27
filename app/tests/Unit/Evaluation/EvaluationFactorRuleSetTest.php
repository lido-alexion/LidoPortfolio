<?php

namespace Tests\Unit\Evaluation;

use App\Engines\Evaluation\EvaluationFactorRuleSet;
use App\Engines\Evaluation\Rules\BreakoutScoreRule;
use App\Engines\Evaluation\Rules\MarketRegimeRule;
use App\Engines\Evaluation\Rules\MomentumScoreRule;
use App\Engines\Evaluation\Rules\RelativeStrengthRule;
use App\Engines\Evaluation\Rules\RiskScoreRule;
use App\Engines\Evaluation\Rules\SectorStrengthRule;
use App\Engines\Evaluation\Rules\TrendScoreRule;
use App\Engines\Evaluation\Rules\VolumeScoreRule;
use App\Providers\EvaluationServiceProvider;
use Tests\TestCase;

class EvaluationFactorRuleSetTest extends TestCase
{
    public function test_container_registers_all_current_catalogue_rules_in_pass_fail_order(): void
    {
        $set = app(EvaluationFactorRuleSet::class);

        $this->assertSame([
            'trend_score',
            'momentum_score',
            'relative_strength',
            'volume_score',
            'breakout_score',
            'market_regime',
            'sector_strength',
            'risk_score',
        ], $set->keys());

        $this->assertSame(EvaluationFactorRuleSet::CATALOGUE_KEYS, [
            'relative_strength',
            'momentum_score',
            'trend_score',
            'breakout_score',
            'volume_score',
            'market_regime',
            'sector_strength',
            'risk_score',
        ]);

        $classes = array_map(static fn ($rule) => $rule::class, $set->all());
        $this->assertSame(EvaluationServiceProvider::defaultRuleClasses(), $classes);
        $this->assertContains(TrendScoreRule::class, $classes);
        $this->assertContains(MomentumScoreRule::class, $classes);
        $this->assertContains(RelativeStrengthRule::class, $classes);
        $this->assertContains(VolumeScoreRule::class, $classes);
        $this->assertContains(BreakoutScoreRule::class, $classes);
        $this->assertContains(MarketRegimeRule::class, $classes);
        $this->assertContains(SectorStrengthRule::class, $classes);
        $this->assertContains(RiskScoreRule::class, $classes);
    }

    public function test_without_drops_only_the_named_rule(): void
    {
        $set = app(EvaluationFactorRuleSet::class);
        $keys = array_map(static fn ($rule) => $rule->key(), $set->without('sector_strength'));

        $this->assertNotContains('sector_strength', $keys);
        $this->assertContains('market_regime', $keys);
        $this->assertCount(7, $keys);
    }
}
