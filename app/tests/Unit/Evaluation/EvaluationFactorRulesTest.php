<?php

namespace Tests\Unit\Evaluation;

use App\Engines\Evaluation\EvaluationFactorContext;
use App\Engines\Evaluation\Rules\BreakoutScoreRule;
use App\Engines\Evaluation\Rules\MarketRegimeRule;
use App\Engines\Evaluation\Rules\MomentumScoreRule;
use App\Engines\Evaluation\Rules\RelativeStrengthRule;
use App\Engines\Evaluation\Rules\RiskScoreRule;
use App\Engines\Evaluation\Rules\SectorStrengthRule;
use App\Engines\Evaluation\Rules\TrendScoreRule;
use App\Engines\Evaluation\Rules\VolumeScoreRule;
use Tests\TestCase;

class EvaluationFactorRulesTest extends TestCase
{
    public function test_trend_score_formulas(): void
    {
        $rule = new TrendScoreRule;
        $this->assertSame('trend_score', $rule->key());

        $stack = $rule->evaluate($this->context(close: 100, smaFast: 90, smaSlow: 80));
        $this->assertSame(100.0, $stack->score);
        $this->assertSame(['uptrend_sma_stack'], $stack->passed);
        $this->assertSame(['trend' => 100.0], $stack->aliases);

        $above = $rule->evaluate($this->context(close: 100, smaFast: 90, smaSlow: 95));
        $this->assertSame(60.0, $above->score);
        $this->assertSame(['price_above_sma_fast'], $above->passed);

        $below = $rule->evaluate($this->context(close: 80, smaFast: 90, smaSlow: 95));
        $this->assertSame(20.0, $below->score);
        $this->assertSame(['price_below_sma_fast'], $below->failed);

        $missing = $rule->evaluate($this->context(close: null, smaFast: null, smaSlow: null));
        $this->assertSame(0.0, $missing->score);
        $this->assertSame(['sma_unavailable'], $missing->failed);
    }

    public function test_momentum_score_formulas(): void
    {
        $rule = new MomentumScoreRule;
        $this->assertSame(100.0, $rule->evaluate($this->context(rsi: 50))->score);
        $this->assertSame(['rsi_healthy'], $rule->evaluate($this->context(rsi: 45))->passed);
        $this->assertSame(55.0, $rule->evaluate($this->context(rsi: 71))->score);
        $this->assertSame(['rsi_overbought'], $rule->evaluate($this->context(rsi: 71))->failed);
        $this->assertSame(35.0, $rule->evaluate($this->context(rsi: 29))->score);
        $this->assertSame(50.0, $rule->evaluate($this->context(rsi: 40))->score);
        $unavailable = $rule->evaluate($this->context(rsi: null));
        $this->assertSame(50.0, $unavailable->score);
        $this->assertSame(['rsi_unavailable'], $unavailable->failed);
        $this->assertSame(['momentum' => 50.0], $unavailable->aliases);
    }

    public function test_relative_strength_formulas(): void
    {
        $rule = new RelativeStrengthRule;
        $this->assertSame(100.0, $rule->evaluate($this->context(relativeStrength: 1.05))->score);
        $this->assertSame(70.0, $rule->evaluate($this->context(relativeStrength: 1.0))->score);
        $this->assertSame(30.0, $rule->evaluate($this->context(relativeStrength: 0.99))->score);
        $missing = $rule->evaluate($this->context(relativeStrength: null));
        $this->assertSame(50.0, $missing->score);
        $this->assertSame(['rs_unavailable'], $missing->failed);
        $this->assertSame([], $missing->aliases);
    }

    public function test_volume_score_formulas_and_null_is_not_a_failure(): void
    {
        $rule = new VolumeScoreRule;
        $this->assertSame(100.0, $rule->evaluate($this->context(volumeRatio: 1.2))->score);
        $this->assertSame(60.0, $rule->evaluate($this->context(volumeRatio: 0.8))->score);
        $this->assertSame(30.0, $rule->evaluate($this->context(volumeRatio: 0.79))->score);
        $missing = $rule->evaluate($this->context(volumeRatio: null));
        $this->assertSame(50.0, $missing->score);
        $this->assertSame([], $missing->failed);
        $this->assertSame([], $missing->passed);
    }

    public function test_breakout_score_from_pattern_count(): void
    {
        $rule = new BreakoutScoreRule;
        $none = $rule->evaluate($this->context(patternCount: 0));
        $this->assertSame(0.0, $none->score);
        $this->assertSame(['no_pattern'], $none->failed);

        $one = $rule->evaluate($this->context(patternCount: 1));
        $this->assertSame(60.0, $one->score);
        $this->assertSame(['pattern_present'], $one->passed);
        $this->assertSame(['pattern_bonus' => 60.0], $one->aliases);

        $this->assertSame(100.0, $rule->evaluate($this->context(patternCount: 4))->score);
    }

    public function test_market_regime_uses_context_score_only(): void
    {
        $rule = new MarketRegimeRule;
        $this->assertSame('market_regime', $rule->key());
        $this->assertSame(100.0, $rule->evaluate($this->context(marketRegimeScore: 100.0))->score);
        $this->assertSame(0.0, $rule->evaluate($this->context(marketRegimeScore: 0.0))->score);
        $this->assertSame([], $rule->evaluate($this->context(marketRegimeScore: 50.0))->passed);
    }

    public function test_sector_strength_remains_stub_50(): void
    {
        $rule = new SectorStrengthRule;
        $this->assertSame(50.0, $rule->evaluate($this->context())->score);
        $this->assertSame(50.0, $rule->evaluate($this->context(close: 1, rsi: 99))->score);
    }

    public function test_risk_score_from_atr_pct(): void
    {
        $rule = new RiskScoreRule;
        $contained = $rule->evaluate($this->context(atrPct: 1.5));
        $this->assertSame(15.0, $contained->score);
        $this->assertSame(['risk_contained'], $contained->passed);

        $elevated = $rule->evaluate($this->context(atrPct: 5.0));
        $this->assertSame(50.0, $elevated->score);
        $this->assertSame(['risk_elevated'], $elevated->failed);

        $capped = $rule->evaluate($this->context(atrPct: 20.0));
        $this->assertSame(100.0, $capped->score);

        $missing = $rule->evaluate($this->context(atrPct: null));
        $this->assertSame(50.0, $missing->score);
        $this->assertSame(['atr_unavailable'], $missing->failed);
    }

    protected function context(
        ?float $close = 100.0,
        ?float $smaFast = 90.0,
        ?float $smaSlow = 80.0,
        ?float $rsi = 50.0,
        ?float $atr = 2.0,
        ?float $atrPct = 2.0,
        ?float $volumeRatio = 1.0,
        ?float $priceVsSma = 5.0,
        ?float $relativeStrength = 1.0,
        string $marketRegime = 'Neutral',
        float $marketRegimeScore = 50.0,
        int $patternCount = 0,
    ): EvaluationFactorContext {
        return new EvaluationFactorContext(
            close: $close,
            smaFast: $smaFast,
            smaSlow: $smaSlow,
            rsi: $rsi,
            atr: $atr,
            atrPct: $atrPct,
            volumeRatio: $volumeRatio,
            priceVsSma: $priceVsSma,
            relativeStrength: $relativeStrength,
            marketRegime: $marketRegime,
            marketRegimeScore: $marketRegimeScore,
            patternCount: $patternCount,
        );
    }
}
