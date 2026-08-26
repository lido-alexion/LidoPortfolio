<?php

namespace App\Services\Indicators;

use App\Engines\Strategy\SupportedIndicators;

/**
 * Canonical seed for Strategy scoring Composites (formerly SupportedIndicators::definitions body).
 * Registry is SoT; SupportedIndicators projects BC-shaped rows from Registry.
 *
 * @phpstan-type StrategyRow array{
 *   key: string,
 *   category: string,
 *   display_name: string,
 *   description: string,
 *   supports_maximum: bool,
 *   default_enabled: bool,
 *   default_weight: int|float,
 *   default_minimum: int|float,
 *   default_maximum: int|float|null,
 *   parameters: array<string, array<string, mixed>>,
 *   depends_on: list<string>,
 *   formula_explanation: string,
 *   status: string,
 *   registry_category: string
 * }
 */
final class StrategyCompositeSeed
{
    /**
     * @return list<StrategyRow>
     */
    public static function rows(): array
    {
        return [
            [
                'key' => SupportedIndicators::RELATIVE_STRENGTH,
                'category' => SupportedIndicators::CATEGORY_MOMENTUM,
                'registry_category' => IndicatorCategory::RELATIVE_PERFORMANCE,
                'display_name' => 'Relative Strength',
                'description' => 'Relative strength vs benchmark over the lookback window.',
                'supports_maximum' => false,
                'default_enabled' => true,
                'default_weight' => 35,
                'default_minimum' => 80,
                'default_maximum' => null,
                'parameters' => [
                    'lookback_days' => ['type' => 'integer', 'label' => 'Lookback Period (days)', 'default' => 90],
                    'benchmark' => ['type' => 'string', 'label' => 'Benchmark', 'default' => 'NIFTY50'],
                ],
                'depends_on' => ['relative_strength_3m'],
                'formula_explanation' => 'Maps Evaluation relative-strength fact (stock return minus benchmark return over Strategy lookback_days when set, else 3-month vs primary/Strategy benchmark): ≥1.05 → 100; ≥1.0 → 70; else 30. Valid Strategy lookback_days and benchmark override Evaluation globals (V4-FEAT-021).',
                'status' => IndicatorStatus::ACTIVE,
            ],
            [
                'key' => SupportedIndicators::MOMENTUM_SCORE,
                'category' => SupportedIndicators::CATEGORY_MOMENTUM,
                'registry_category' => IndicatorCategory::MOMENTUM,
                'display_name' => 'Momentum Score',
                'description' => 'RSI-based momentum strength (objective measurement).',
                'supports_maximum' => false,
                'default_enabled' => true,
                'default_weight' => 15,
                'default_minimum' => 70,
                'default_maximum' => null,
                'parameters' => [
                    'rsi_period' => ['type' => 'integer', 'label' => 'RSI Period', 'default' => 14],
                ],
                'depends_on' => ['rsi'],
                'formula_explanation' => 'Maps RSI into a 0–100 Evaluation fact: RSI in [45, 70] → 100; RSI > 70 → 55; RSI < 30 → 35; otherwise 50. Strategy applies weight and minimum gate.',
                'status' => IndicatorStatus::ACTIVE,
            ],
            [
                'key' => SupportedIndicators::TREND_SCORE,
                'category' => SupportedIndicators::CATEGORY_TREND,
                'registry_category' => IndicatorCategory::TREND,
                'display_name' => 'Trend Score',
                'description' => 'Price vs SMA stack trend strength.',
                'supports_maximum' => false,
                'default_enabled' => true,
                'default_weight' => 20,
                'default_minimum' => 70,
                'default_maximum' => null,
                'parameters' => [
                    'sma_fast' => ['type' => 'integer', 'label' => 'Fast SMA', 'default' => 20],
                    'sma_slow' => ['type' => 'integer', 'label' => 'Slow SMA', 'default' => 50],
                ],
                'depends_on' => ['close', 'sma'],
                'formula_explanation' => 'Uses close vs SMA fast/slow stack: aligned uptrend (close > fast > slow) → 100; close > fast → 60; else 20.',
                'status' => IndicatorStatus::ACTIVE,
            ],
            [
                'key' => SupportedIndicators::BREAKOUT_SCORE,
                'category' => SupportedIndicators::CATEGORY_TREND,
                'registry_category' => IndicatorCategory::TREND,
                'display_name' => 'Breakout Score',
                'description' => 'Pattern / breakout strength from discovery evidence.',
                'supports_maximum' => false,
                'default_enabled' => true,
                'default_weight' => 10,
                'default_minimum' => 75,
                'default_maximum' => null,
                'parameters' => [],
                'depends_on' => ['discovery_pattern_count'],
                'formula_explanation' => 'From Discovery pattern count: min(100, 40 + 20×count). Zero patterns → 0.',
                'status' => IndicatorStatus::ACTIVE,
            ],
            [
                'key' => SupportedIndicators::VOLUME_SCORE,
                'category' => SupportedIndicators::CATEGORY_VOLUME,
                'registry_category' => IndicatorCategory::VOLUME,
                'display_name' => 'Volume Score',
                'description' => 'Volume expansion versus recent average.',
                'supports_maximum' => false,
                'default_enabled' => true,
                'default_weight' => 8,
                'default_minimum' => 60,
                'default_maximum' => null,
                'parameters' => [
                    'volume_sma_period' => ['type' => 'integer', 'label' => 'Volume SMA Period', 'default' => 20],
                ],
                'depends_on' => ['volume_ratio'],
                'formula_explanation' => 'Maps volume_ratio: ≥1.2 → 100; ≥0.8 → 60; else 30.',
                'status' => IndicatorStatus::ACTIVE,
            ],
            [
                'key' => SupportedIndicators::MARKET_REGIME,
                'category' => SupportedIndicators::CATEGORY_MARKET,
                'registry_category' => IndicatorCategory::MARKET,
                'display_name' => 'Market Regime',
                'description' => 'Broad market regime from Market Analysis (Bullish=100, Neutral=50, Bearish=0).',
                'supports_maximum' => false,
                'default_enabled' => true,
                'default_weight' => 5,
                'default_minimum' => 60,
                'default_maximum' => null,
                'parameters' => [],
                'depends_on' => [],
                'formula_explanation' => 'MarketAnalysisEngine.market_regime via existing regimeFromPhase(): Bullish→100, Neutral→50, Bearish→0. No independent phase/regime calculation.',
                'status' => IndicatorStatus::ACTIVE,
            ],
            [
                'key' => SupportedIndicators::SECTOR_STRENGTH,
                'category' => SupportedIndicators::CATEGORY_MARKET,
                'registry_category' => IndicatorCategory::MARKET,
                'display_name' => 'Sector Strength',
                'description' => 'Sector relative strength (neutral stub until sector model ships).',
                'supports_maximum' => false,
                'default_enabled' => true,
                'default_weight' => 4,
                'default_minimum' => 60,
                'default_maximum' => null,
                'parameters' => [],
                'depends_on' => [],
                'formula_explanation' => 'Stub: constant 50 until a sector relative-strength model ships.',
                'status' => IndicatorStatus::STUB,
            ],
            [
                'key' => SupportedIndicators::RISK_SCORE,
                'category' => SupportedIndicators::CATEGORY_RISK,
                'registry_category' => IndicatorCategory::RISK,
                'display_name' => 'Risk Score',
                'description' => 'Volatility / ATR risk. Higher is riskier; use Maximum to cap acceptable risk.',
                'supports_maximum' => true,
                'default_enabled' => true,
                'default_weight' => 3,
                'default_minimum' => 0,
                'default_maximum' => 40,
                'parameters' => [
                    'atr_period' => ['type' => 'integer', 'label' => 'ATR Period', 'default' => 14],
                ],
                'depends_on' => ['atr', 'close'],
                'formula_explanation' => 'atr_pct = atr/close×100; score = clamp(atr_pct×10, 0, 100). Higher is riskier; Strategy may apply maximum gate.',
                'status' => IndicatorStatus::ACTIVE,
            ],
        ];
    }
}
