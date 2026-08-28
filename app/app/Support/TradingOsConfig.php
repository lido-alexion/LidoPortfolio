<?php

namespace App\Support;

/**
 * Typed accessors for trading_os config (TD-007).
 *
 * Centralises config path strings and default fallbacks so engines and
 * controllers do not scatter magic config('trading_os....') keys.
 */
final class TradingOsConfig
{
    public const PREFIX = 'trading_os';

    public const KEY_ENABLED = self::PREFIX.'.enabled';

    public const KEY_DISCOVERY = self::PREFIX.'.discovery';

    public const KEY_EVALUATION = self::PREFIX.'.evaluation';

    public const KEY_RECOMMENDATION = self::PREFIX.'.recommendation';

    public const KEY_NOTIFICATION = self::PREFIX.'.notification';

    public const KEY_REVIEW = self::PREFIX.'.review';

    public const KEY_PIPELINE = self::PREFIX.'.pipeline';

    public const KEY_MARKET_ANALYSIS = self::PREFIX.'.market_analysis';

    /** Strategy version config_json section keys (TD-011). */
    public const STRATEGY_THRESHOLDS = 'thresholds';

    public const STRATEGY_RECOMMENDATION_BEHAVIOUR = 'recommendation_behaviour';

    public const STRATEGY_PORTFOLIO_RULES = 'portfolio_rules';

    public const STRATEGY_RISK = 'risk';

    public const STRATEGY_CAPITAL_ALLOCATION = 'capital_allocation';

    public const STRATEGY_MARKET_GATES = 'market_gates';

    /** Strategy threshold field keys within config_json.thresholds. */
    public const THRESHOLD_OPEN_POSITION = 'open_position';

    public const THRESHOLD_INCREASE_POSITION = 'increase_position';

    public const THRESHOLD_WATCH = 'watch';

    public const THRESHOLD_EXIT_POSITION = 'exit_position';

    public const THRESHOLD_REDUCE_POSITION = 'reduce_position';

    public const THRESHOLD_VERY_STRONG_HIGH = 'very_strong_high';

    public const THRESHOLD_VERY_STRONG_LOW = 'very_strong_low';

    public static function enabled(): bool
    {
        return (bool) config(self::KEY_ENABLED, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function discovery(): array
    {
        return config(self::KEY_DISCOVERY, []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function evaluation(): array
    {
        return config(self::KEY_EVALUATION, []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function recommendation(): array
    {
        return config(self::KEY_RECOMMENDATION, []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function notification(): array
    {
        return config(self::KEY_NOTIFICATION, []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function review(): array
    {
        return config(self::KEY_REVIEW, []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function pipeline(): array
    {
        return config(self::KEY_PIPELINE, []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function marketAnalysis(): array
    {
        return config(self::KEY_MARKET_ANALYSIS, []);
    }

    public static function notificationNotifyOnGenerate(): bool
    {
        return (bool) config(self::KEY_NOTIFICATION.'.notify_on_generate', true);
    }

    public static function notificationMaxRetries(): int
    {
        return (int) config(self::KEY_NOTIFICATION.'.max_retries', 3);
    }

    public static function reviewDefaultLookbackDays(): int
    {
        return (int) config(self::KEY_REVIEW.'.default_lookback_days', 90);
    }

    public static function pipelineScheduleEnabled(): bool
    {
        return (bool) config(self::KEY_PIPELINE.'.schedule_enabled', true);
    }

    public static function pipelineScheduleTime(): string
    {
        $time = config(self::KEY_PIPELINE.'.schedule_time', '19:00');

        return is_string($time) ? $time : '19:00';
    }

    public static function pipelineRunAfterDailySync(): bool
    {
        return (bool) config(self::KEY_PIPELINE.'.run_after_daily_sync', true);
    }

    /** Fallback when strategy config_json omits threshold values (TD-011). */
    public static function recommendationBuyScoreMin(): float
    {
        return (float) config(self::KEY_RECOMMENDATION.'.buy_score_min', 65);
    }

    public static function recommendationWatchScoreMin(): float
    {
        return (float) config(self::KEY_RECOMMENDATION.'.watch_score_min', 45);
    }

    public static function recommendationSellScoreMax(): float
    {
        return (float) config(self::KEY_RECOMMENDATION.'.sell_score_max', 35);
    }

    public static function recommendationVeryStrongHigh(): float
    {
        return (float) config(self::KEY_RECOMMENDATION.'.very_strong_high', 85);
    }

    public static function recommendationVeryStrongLow(): float
    {
        return (float) config(self::KEY_RECOMMENDATION.'.very_strong_low', 15);
    }

    public static function recommendationExpiryHours(): int
    {
        return (int) config(self::KEY_RECOMMENDATION.'.expiry_hours', 48);
    }

    public static function recommendationDefaultPositionPct(): float
    {
        return (float) config(self::KEY_RECOMMENDATION.'.default_position_pct', 5.0);
    }

    public static function recommendationMaxPositionPct(): float
    {
        return (float) config(self::KEY_RECOMMENDATION.'.max_position_pct', 10.0);
    }

    public static function recommendationAllocationBandPct(): float
    {
        return (float) config(self::KEY_RECOMMENDATION.'.allocation_band_pct', 1.0);
    }

    public static function recommendationMaxConcurrent(): int
    {
        return (int) config(self::KEY_RECOMMENDATION.'.max_concurrent_recommendations', 100);
    }

    public static function recommendationMaxNewPositionsPerCycle(): int
    {
        return (int) config(self::KEY_RECOMMENDATION.'.max_new_positions_per_cycle', 50);
    }

    public static function recommendationRiskHighAtrPct(): float
    {
        return (float) config(self::KEY_RECOMMENDATION.'.risk.high_atr_pct', 4.0);
    }

    public static function recommendationRiskMediumAtrPct(): float
    {
        return (float) config(self::KEY_RECOMMENDATION.'.risk.medium_atr_pct', 2.0);
    }

    public static function get(string $suffix, mixed $default = null): mixed
    {
        return config(self::PREFIX.'.'.$suffix, $default);
    }
}
