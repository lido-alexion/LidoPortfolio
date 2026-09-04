<?php

return [
    /*
    | Trading Operating System (specs/) configuration.
    | Engines read these values; do not hardcode thresholds in engine classes.
    */
    'enabled' => env('TRADING_OS_ENABLED', true),

    'discovery' => [
        'include_patterns' => true,
        'include_screener_hits' => true,
        'pattern_scopes' => ['holdings', 'watchlist'],
        'max_candidates' => 100,
        'screener_hit_lookback_hours' => 48,
    ],

    'evaluation' => [
        'min_bars' => 60,
        'weights' => [
            'trend' => 0.30,
            'momentum' => 0.25,
            'relative_strength' => 0.25,
            'volume' => 0.10,
            'pattern_bonus' => 0.10,
        ],
        'rsi_period' => 14,
        'sma_fast' => 20,
        'sma_slow' => 50,
        'atr_period' => 14,
        'volume_sma_period' => 20,
    ],

    'recommendation' => [
        'buy_score_min' => 65,
        'watch_score_min' => 45,
        'sell_score_max' => 35,
        'very_strong_high' => 85,
        'very_strong_low' => 15,
        'expiry_hours' => 48,
        'default_position_pct' => 5.0,
        'max_position_pct' => 10.0,
        'allocation_band_pct' => 1.0,
        'max_concurrent_recommendations' => 100,
        'max_new_positions_per_cycle' => 50,
        'risk' => [
            'high_atr_pct' => 4.0,
            'medium_atr_pct' => 2.0,
        ],
    ],

    'notification' => [
        'channels' => ['telegram'],
        'max_retries' => 3,
        'notify_on_generate' => true,
    ],

    'review' => [
        'default_lookback_days' => 90,
    ],

    'pipeline' => [
        // V4-FEAT-010: unattended production defaults. Set env to false to disable.
        'run_after_daily_sync' => env('TRADING_OS_PIPELINE_AFTER_SYNC', true),
        'schedule_enabled' => env('TRADING_OS_PIPELINE_SCHEDULE', true),
        'schedule_time' => env('TRADING_OS_PIPELINE_TIME', '19:00'),
    ],

    'execution' => [
        // FEAT-039: current primary-order cutoff; Day #2 expires at this instant.
        'cutoff_time' => env('TRADING_OS_EXECUTION_CUTOFF', '15:30'),
    ],

    /*
    | Market Analysis Engine (SD-032) — sentiment weights sum to 100.
    */
    'market_analysis' => [
        'sentiment_weights' => [
            'trend' => 25,
            'momentum' => 20,
            'breadth' => 20,
            'risk' => 20,
            'volatility' => 15,
        ],
    ],
];
