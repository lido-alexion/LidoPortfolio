<?php

namespace App\Engines\Strategy;

use App\Engines\Strategy\SupportedIndicators;

/**
 * Default Momentum / Minervini strategy config (SD-029).
 * Seeded once per portfolio; editable in place (no version fork).
 */
final class FactoryMomentumStrategy
{
    public const NAME = 'Minervini Strategy';

    public const VERSION_LABEL = '1.0';

    public const FACTORY_KEY = 'momentum_factory';

    public const DESCRIPTION = 'Default strategy: Minervini Trend Template eligibility with momentum scoring, thresholds, allocation, and exits.';

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        $indicators = [];
        foreach (SupportedIndicators::definitions() as $def) {
            $key = $def['key'];
            $row = self::indicatorDefaults()[$key] ?? null;
            if ($row === null) {
                continue;
            }
            $params = [];
            foreach ($def['parameters'] as $paramKey => $meta) {
                $params[$paramKey] = $row['parameters'][$paramKey] ?? $meta['default'];
            }
            $indicators[] = [
                'key' => $key,
                'category' => $def['category'],
                'display_name' => $def['display_name'],
                'description' => $def['description'],
                'enabled' => (bool) $row['enabled'],
                'weight' => (float) $row['weight'],
                'minimum' => $row['minimum'],
                'maximum' => $row['maximum'],
                'supports_maximum' => (bool) $def['supports_maximum'],
                'parameters' => $params,
            ];
        }

        return [
            // Eligibility is via Screeners (SD-030) — IDs filled at seed time.
            'eligibility_sources' => [],
            // Scoring model (BC alias: indicators)
            'indicators' => $indicators,
            'scoring_model' => $indicators,
            'thresholds' => [
                'minimum_overall_score' => 80.0,
                'open_position' => 85.0,
                'increase_position' => 90.0,
                'reduce_position' => 40.0,
                'exit_position' => 20.0,
                'watch' => 60.0,
                'very_strong_high' => 95.0,
                'very_strong_low' => 15.0,
            ],
            'portfolio_rules' => [
                'max_position_size_pct' => 10.0,
                'min_position_size_pct' => 2.0,
                'default_position_size_pct' => 6.0,
                'allocation_band_pct' => 1.0,
                'max_cash_deployment_pct' => 80.0,
                'min_cash_reserve_pct' => 20.0,
                'max_new_positions_per_cycle' => 5,
                'max_exposure_per_stock_pct' => 10.0,
                /** OD-12 / §12.1 — first entry as % of current target amount (default 50). */
                'first_entry_pct' => 50.0,
            ],
            'capital_allocation' => [
                'strategy' => 'proportional',
                'tie_break' => 'highest_score',
                'score_bands' => [
                    ['min' => 95, 'max' => 100, 'allocation_pct' => 10],
                    ['min' => 90, 'max' => 95, 'allocation_pct' => 8],
                    ['min' => 85, 'max' => 90, 'allocation_pct' => 6],
                    ['min' => 80, 'max' => 85, 'allocation_pct' => 4],
                    ['min' => 0, 'max' => 80, 'allocation_pct' => 0],
                ],
            ],
            'cash_rules' => [
                'reservations_enabled' => true,
                'reserve_on_approval' => true,
                'release_on_execution' => true,
                'release_on_cancellation' => true,
                'release_on_expiry' => true,
            ],
            'exit_strategy' => [
                'enabled' => true,
                'mode' => 'any',
                'rules' => ExitStrategyEvaluator::defaultRules(),
            ],
            'market_gates' => [
                'enabled' => false,
                'min_sentiment' => 45,
                'allowed_phases' => ['Strong Bull', 'Bull', 'Consolidation', 'Pullback', 'Recovery'],
                'max_risk_raw' => 70,
            ],
            'recommendation_behaviour' => [
                'allow_increase_position' => true,
                'allow_reduce_position' => true,
                'allow_partial_exit' => true,
                'allow_averaging_up' => true,
                'allow_averaging_down' => false,
                'max_concurrent_recommendations' => 100,
                'expiry_hours' => 48,
                'generate_explainability' => true,
                'display_factor_breakdown' => true,
                'display_indicator_contributions' => true,
                'record_strategy_version' => true,
                'record_cash_snapshot' => true,
                'record_portfolio_snapshot' => true,
            ],
            'risk' => [
                'high_atr_pct' => 4.0,
                'medium_atr_pct' => 2.0,
            ],
        ];
    }

    /**
     * Factory indicator defaults. Enabled weights sum to 100.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function indicatorDefaults(): array
    {
        return [
            SupportedIndicators::RELATIVE_STRENGTH => [
                'enabled' => true,
                'weight' => 35,
                'minimum' => 80,
                'maximum' => null,
                'parameters' => [
                    'lookback_days' => 90,
                    'benchmark' => 'NIFTY50',
                ],
            ],
            SupportedIndicators::MOMENTUM_SCORE => [
                'enabled' => true,
                'weight' => 15,
                'minimum' => 70,
                'maximum' => null,
                'parameters' => [],
            ],
            SupportedIndicators::TREND_SCORE => [
                'enabled' => true,
                'weight' => 20,
                'minimum' => 70,
                'maximum' => null,
                'parameters' => [],
            ],
            SupportedIndicators::BREAKOUT_SCORE => [
                'enabled' => true,
                'weight' => 10,
                'minimum' => 75,
                'maximum' => null,
                'parameters' => [],
            ],
            SupportedIndicators::VOLUME_SCORE => [
                'enabled' => true,
                'weight' => 8,
                'minimum' => 60,
                'maximum' => null,
                'parameters' => [],
            ],
            SupportedIndicators::MARKET_REGIME => [
                'enabled' => true,
                'weight' => 5,
                'minimum' => 60,
                'maximum' => null,
                'parameters' => [],
            ],
            SupportedIndicators::SECTOR_STRENGTH => [
                'enabled' => true,
                'weight' => 4,
                'minimum' => 60,
                'maximum' => null,
                'parameters' => [],
            ],
            SupportedIndicators::RISK_SCORE => [
                'enabled' => true,
                'weight' => 3,
                'minimum' => 0,
                'maximum' => 40,
                'parameters' => [],
            ],
        ];
    }
}
