<?php

namespace Tests\Unit;

use App\Support\TradingOsConfig;
use Tests\TestCase;

class TradingOsConfigTest extends TestCase
{
    public function test_recommendation_getters_read_config_with_documented_defaults(): void
    {
        config([
            TradingOsConfig::KEY_RECOMMENDATION => [
                'buy_score_min' => 70,
                'watch_score_min' => 50,
                'sell_score_max' => 30,
                'very_strong_high' => 90,
                'very_strong_low' => 10,
                'expiry_hours' => 72,
                'default_position_pct' => 6.0,
                'max_position_pct' => 12.0,
                'allocation_band_pct' => 1.5,
                'max_concurrent_recommendations' => 25,
                'max_new_positions_per_cycle' => 10,
                'risk' => [
                    'high_atr_pct' => 5.0,
                    'medium_atr_pct' => 2.5,
                ],
            ],
        ]);

        $this->assertSame(70.0, TradingOsConfig::recommendationBuyScoreMin());
        $this->assertSame(50.0, TradingOsConfig::recommendationWatchScoreMin());
        $this->assertSame(30.0, TradingOsConfig::recommendationSellScoreMax());
        $this->assertSame(90.0, TradingOsConfig::recommendationVeryStrongHigh());
        $this->assertSame(10.0, TradingOsConfig::recommendationVeryStrongLow());
        $this->assertSame(72, TradingOsConfig::recommendationExpiryHours());
        $this->assertSame(6.0, TradingOsConfig::recommendationDefaultPositionPct());
        $this->assertSame(12.0, TradingOsConfig::recommendationMaxPositionPct());
        $this->assertSame(1.5, TradingOsConfig::recommendationAllocationBandPct());
        $this->assertSame(25, TradingOsConfig::recommendationMaxConcurrent());
        $this->assertSame(10, TradingOsConfig::recommendationMaxNewPositionsPerCycle());
        $this->assertSame(5.0, TradingOsConfig::recommendationRiskHighAtrPct());
        $this->assertSame(2.5, TradingOsConfig::recommendationRiskMediumAtrPct());
    }

    public function test_enabled_and_pipeline_schedule_accessors(): void
    {
        config([
            TradingOsConfig::KEY_ENABLED => false,
            TradingOsConfig::KEY_PIPELINE.'.schedule_enabled' => true,
            TradingOsConfig::KEY_PIPELINE.'.schedule_time' => '18:30',
        ]);

        $this->assertFalse(TradingOsConfig::enabled());
        $this->assertTrue(TradingOsConfig::pipelineScheduleEnabled());
        $this->assertSame('18:30', TradingOsConfig::pipelineScheduleTime());
    }

    public function test_pipeline_schedule_defaults_on_when_keys_omitted(): void
    {
        config([TradingOsConfig::KEY_PIPELINE => []]);

        $this->assertTrue(TradingOsConfig::pipelineScheduleEnabled());
        $this->assertTrue(TradingOsConfig::pipelineRunAfterDailySync());
        $this->assertSame('19:00', TradingOsConfig::pipelineScheduleTime());
    }

    public function test_pipeline_after_sync_accessor(): void
    {
        config([
            TradingOsConfig::KEY_PIPELINE.'.run_after_daily_sync' => true,
        ]);

        $this->assertTrue(TradingOsConfig::pipelineRunAfterDailySync());
    }
}
