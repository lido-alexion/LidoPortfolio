<?php

namespace Tests\Unit\Ranking;

use App\Models\User;
use App\Services\ProfileSettingsService;
use App\Services\Ranking\SuccessCriteriaEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuccessCriteriaEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_requires_positive_nifty_beat_and_opportunity_cost(): void
    {
        $eval = app(SuccessCriteriaEvaluator::class);

        // 30 calendar days, r=0.12 → threshold ≈ (1.12)^(30/365) − 1 ≈ 0.0093
        $ok = $eval->evaluate(0.05, 0.01, 30, 0.12);
        $this->assertTrue($ok['success']);
        $this->assertTrue($ok['positive_return']);
        $this->assertTrue($ok['beats_benchmark']);
        $this->assertTrue($ok['beats_opportunity_cost']);

        $failNeg = $eval->evaluate(-0.02, -0.05, 30, 0.12);
        $this->assertFalse($failNeg['success']);
        $this->assertFalse($failNeg['positive_return']);

        $failNifty = $eval->evaluate(0.02, 0.03, 30, 0.12);
        $this->assertFalse($failNifty['success']);
        $this->assertFalse($failNifty['beats_benchmark']);

        $failOpp = $eval->evaluate(0.001, 0.0001, 30, 0.12);
        $this->assertFalse($failOpp['success']);
        $this->assertFalse($failOpp['beats_opportunity_cost']);
    }

    public function test_uses_profile_opportunity_cost_rate_not_hardcoded_only(): void
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'OppCost', true);
        app(ProfileSettingsService::class)->set($profile, 'opportunity_cost_rate', '0.25');

        $eval = app(SuccessCriteriaEvaluator::class);
        $this->assertEqualsWithDelta(0.25, $eval->opportunityCostRateFor($profile), 0.0001);

        // Same 15% period return over 1 year: passes at 12%, fails at 25%
        $atDefault = $eval->evaluate(0.15, 0.01, 365, 0.12);
        $this->assertTrue($atDefault['beats_opportunity_cost']);

        $atHigh = $eval->evaluateForProfile($profile, 0.15, 0.01, 365);
        $this->assertSame(0.25, $atHigh['opportunity_cost_rate']);
        $this->assertFalse($atHigh['beats_opportunity_cost']);
        $this->assertFalse($atHigh['success']);
    }

    public function test_period_threshold_scales_with_calendar_days_od02(): void
    {
        $eval = app(SuccessCriteriaEvaluator::class);
        $short = $eval->evaluate(0.02, 0.0, 7, 0.12);
        $long = $eval->evaluate(0.02, 0.0, 365, 0.12);

        $this->assertLessThan($long['opportunity_cost_period_threshold'], $short['opportunity_cost_period_threshold']);
        $this->assertTrue($short['beats_opportunity_cost']);
        $this->assertFalse($long['beats_opportunity_cost']);
    }
}
