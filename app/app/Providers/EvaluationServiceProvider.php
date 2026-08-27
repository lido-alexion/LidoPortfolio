<?php

namespace App\Providers;

use App\Engines\Evaluation\EvaluationFactorRuleSet;
use App\Engines\Evaluation\Rules\BreakoutScoreRule;
use App\Engines\Evaluation\Rules\MarketRegimeRule;
use App\Engines\Evaluation\Rules\MomentumScoreRule;
use App\Engines\Evaluation\Rules\RelativeStrengthRule;
use App\Engines\Evaluation\Rules\RiskScoreRule;
use App\Engines\Evaluation\Rules\SectorStrengthRule;
use App\Engines\Evaluation\Rules\TrendScoreRule;
use App\Engines\Evaluation\Rules\VolumeScoreRule;
use Illuminate\Support\ServiceProvider;

/**
 * V4-FEAT-029 — explicit Evaluation factor-rule registration.
 * Add a rule: create the class, append it here (tag order = pass/fail tag order).
 */
class EvaluationServiceProvider extends ServiceProvider
{
    public const RULE_TAG = 'evaluation.factor_rules';

    public function register(): void
    {
        $this->app->tag(self::defaultRuleClasses(), self::RULE_TAG);

        $this->app->singleton(EvaluationFactorRuleSet::class, function ($app) {
            return new EvaluationFactorRuleSet($app->tagged(self::RULE_TAG));
        });
    }

    /**
     * Pass/fail tag order matches the pre-refactor EvaluationEngine
     * (trend → momentum → RS → volume → breakout → regime/sector stubs → risk).
     *
     * @return list<class-string>
     */
    public static function defaultRuleClasses(): array
    {
        return [
            TrendScoreRule::class,
            MomentumScoreRule::class,
            RelativeStrengthRule::class,
            VolumeScoreRule::class,
            BreakoutScoreRule::class,
            MarketRegimeRule::class,
            SectorStrengthRule::class,
            RiskScoreRule::class,
        ];
    }
}
