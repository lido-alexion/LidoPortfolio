<?php

namespace App\Services\Indicators;

/**
 * Builds the default in-memory Registry (SD-033).
 *
 * Epic 2: Registry is the metadata source of truth. Seed data lives in
 * {@see ScreenerPrimarySeed} / {@see StrategyCompositeSeed}; catalogues are façades.
 */
final class IndicatorRegistryFactory
{
    public function make(): IndicatorRegistry
    {
        $definitions = [
            ...$this->screenerPrimaries(),
            ...$this->relativeStrengthRawPrimaries(),
            ...$this->discoveryMetric(),
            ...$this->strategyComposites(),
            ...$this->stockAnalyticsMetrics(),
            ...$this->liquidityTradabilityComposites(),
        ];

        return new IndicatorRegistry($definitions);
    }

    /**
     * @return list<IndicatorDefinition>
     */
    private function screenerPrimaries(): array
    {
        $out = [];
        foreach (ScreenerPrimarySeed::rows() as $row) {
            $id = (string) $row['id'];
            $params = [];
            foreach ($row['params'] ?? [] as $param) {
                if (! is_array($param)) {
                    continue;
                }
                $params[] = [
                    'id' => (string) ($param['id'] ?? ''),
                    'label' => (string) ($param['label'] ?? $param['id'] ?? ''),
                    'type' => 'number',
                    'default' => $param['default'] ?? null,
                    'min' => $param['min'] ?? null,
                    'max' => $param['max'] ?? null,
                    'step' => $param['step'] ?? 1,
                ];
            }

            $category = $this->categoryForScreenerId($id);
            $units = $this->unitsForScreenerId($id);
            $capabilities = [];
            if (! empty($row['needs_volume'])) {
                $capabilities[IndicatorCapability::NEEDS_VOLUME] = true;
            }

            $futureResearchIds = [
                'average_volume', 'average_turnover', 'relative_turnover',
                'gap_frequency', 'gap_fill_ratio', 'circuit_frequency', 'circuit_risk',
            ];
            $consumers = in_array($id, $futureResearchIds, true)
                ? [
                    IndicatorConsumer::SCREENER,
                    IndicatorConsumer::DISCOVERY,
                    IndicatorConsumer::DASHBOARD,
                    IndicatorConsumer::STOCK_DETAILS,
                    IndicatorConsumer::ADMIN_REGISTRY,
                ]
                : [
                    IndicatorConsumer::SCREENER,
                    IndicatorConsumer::EVALUATION,
                    IndicatorConsumer::ADMIN_REGISTRY,
                ];

            $out[] = IndicatorDefinition::make($id, IndicatorType::PRIMARY, $category, [
                'display_name' => (string) ($row['label'] ?? $id),
                'description' => (string) ($row['description'] ?? 'Screener / TechnicalIndicatorService indicator.'),
                'version' => in_array($id, $futureResearchIds, true) ? '1.0.0' : '1.0.0',
                'parameters' => $params,
                'units' => $units,
                'precision' => $this->precisionForUnits($units),
                'screenable' => true,
                'chartable' => true,
                'sortable' => true,
                'filterable' => true,
                'supports_history' => true,
                'stock_level' => true,
                'market_level' => in_array($id, ['close', 'sma', 'ema', 'rsi', 'atr', 'macd', 'macd_signal', 'roc', 'price_vs_sma_pct'], true),
                'consumers' => $consumers,
                'status' => IndicatorStatus::ACTIVE,
                'formula_explanation' => isset($row['formula_explanation']) ? (string) $row['formula_explanation'] : null,
                'capabilities' => $capabilities,
            ]);
        }

        return $out;
    }

    /**
     * @return list<IndicatorDefinition>
     */
    private function relativeStrengthRawPrimaries(): array
    {
        $make = function (string $id, string $label, string $desc): IndicatorDefinition {
            return IndicatorDefinition::make($id, IndicatorType::PRIMARY, IndicatorCategory::RELATIVE_PERFORMANCE, [
                'display_name' => $label,
                'description' => $desc,
                'version' => '1.0.0',
                'units' => 'ratio',
                'precision' => 4,
                'screenable' => false,
                'chartable' => false,
                'sortable' => true,
                'filterable' => true,
                'supports_history' => false,
                'stock_level' => true,
                'consumers' => [
                    IndicatorConsumer::EVALUATION,
                    IndicatorConsumer::STOCK_DETAILS,
                    IndicatorConsumer::PORTFOLIO_ANALYTICS,
                    IndicatorConsumer::DASHBOARD,
                    IndicatorConsumer::ADMIN_REGISTRY,
                ],
                'status' => IndicatorStatus::ACTIVE,
            ]);
        };

        return [
            $make('relative_strength_1m', 'Relative Strength (1m)', 'Stock vs benchmark return ratio over ~1 month.'),
            $make('relative_strength_3m', 'Relative Strength (3m)', 'Stock vs benchmark return ratio over ~3 months (Evaluation default input).'),
            $make('relative_strength_6m', 'Relative Strength (6m)', 'Stock vs benchmark return ratio over ~6 months.'),
        ];
    }

    /**
     * @return list<IndicatorDefinition>
     */
    private function discoveryMetric(): array
    {
        return [
            IndicatorDefinition::make('discovery_pattern_count', IndicatorType::METRIC, IndicatorCategory::DESCRIPTIVE, [
                'display_name' => 'Discovery Pattern Count',
                'description' => 'Count of pattern matches on the Discovery candidate evidence (not a TI series).',
                'version' => '1.0.0',
                'units' => 'count',
                'precision' => 0,
                'screenable' => false,
                'stock_level' => true,
                'consumers' => [
                    IndicatorConsumer::DISCOVERY,
                    IndicatorConsumer::EVALUATION,
                    IndicatorConsumer::ADMIN_REGISTRY,
                ],
                'status' => IndicatorStatus::ACTIVE,
            ]),
        ];
    }

    /**
     * @return list<IndicatorDefinition>
     */
    private function strategyComposites(): array
    {
        $aliasByKey = [
            'relative_strength' => [],
            'momentum_score' => ['momentum'],
            'trend_score' => ['trend'],
            'breakout_score' => ['pattern_bonus'],
            'volume_score' => ['volume'],
            'market_regime' => [],
            'sector_strength' => [],
            'risk_score' => ['risk'],
        ];

        $out = [];
        foreach (StrategyCompositeSeed::rows() as $def) {
            $key = (string) $def['key'];
            $params = [];
            foreach ($def['parameters'] ?? [] as $paramKey => $meta) {
                if (! is_array($meta)) {
                    continue;
                }
                $params[] = [
                    'id' => (string) $paramKey,
                    'label' => (string) ($meta['label'] ?? $paramKey),
                    'type' => (string) ($meta['type'] ?? 'integer'),
                    'default' => $meta['default'] ?? null,
                ];
            }

            $capabilities = [
                IndicatorCapability::STRATEGY_SCORABLE => true,
                IndicatorCapability::EVALUATION_FACT => true,
            ];
            if (! empty($def['supports_maximum'])) {
                $capabilities[IndicatorCapability::SUPPORTS_MAXIMUM] = true;
            }

            $out[] = IndicatorDefinition::make($key, IndicatorType::COMPOSITE, (string) $def['registry_category'], [
                'display_name' => (string) $def['display_name'],
                'description' => (string) $def['description'],
                'version' => '1.0.0',
                'depends_on' => $def['depends_on'] ?? [],
                'parameters' => $params,
                'units' => 'score_0_100',
                'precision' => 2,
                'screenable' => false,
                'chartable' => false,
                'sortable' => true,
                'filterable' => true,
                'supports_history' => false,
                'stock_level' => true,
                'portfolio_level' => true,
                'consumers' => [
                    IndicatorConsumer::STRATEGY,
                    IndicatorConsumer::EVALUATION,
                    IndicatorConsumer::RECOMMENDATION,
                    IndicatorConsumer::DASHBOARD,
                    IndicatorConsumer::PORTFOLIO_ANALYTICS,
                    IndicatorConsumer::STOCK_DETAILS,
                    IndicatorConsumer::ADMIN_REGISTRY,
                ],
                'status' => (string) $def['status'],
                'formula_explanation' => (string) $def['formula_explanation'],
                'aliases' => $aliasByKey[$key] ?? [],
                'capabilities' => $capabilities,
                'legacy' => [
                    'strategy_category_label' => (string) $def['category'],
                    'default_enabled' => (bool) $def['default_enabled'],
                    'default_weight' => $def['default_weight'],
                    'default_minimum' => $def['default_minimum'],
                    'default_maximum' => $def['default_maximum'],
                    'supports_maximum' => (bool) $def['supports_maximum'],
                    'strategy_parameters' => $def['parameters'],
                ],
            ]);
        }

        return $out;
    }

    /**
     * @return list<IndicatorDefinition>
     */
    private function stockAnalyticsMetrics(): array
    {
        $metric = function (
            string $id,
            string $name,
            string $category,
            string $units,
            string $description,
            int $precision = 2,
        ): IndicatorDefinition {
            return IndicatorDefinition::make($id, IndicatorType::METRIC, $category, [
                'display_name' => $name,
                'description' => $description,
                'version' => '1.0.0',
                'units' => $units,
                'precision' => $precision,
                'screenable' => false,
                'chartable' => false,
                'sortable' => true,
                'filterable' => true,
                'stock_level' => true,
                'portfolio_level' => in_array($id, ['relative_strength', 'trend_strength'], true),
                'consumers' => [
                    IndicatorConsumer::STOCK_DETAILS,
                    IndicatorConsumer::PORTFOLIO_ANALYTICS,
                    IndicatorConsumer::DASHBOARD,
                    IndicatorConsumer::ADMIN_REGISTRY,
                ],
                'status' => IndicatorStatus::ACTIVE,
            ]);
        };

        return [
            $metric('distance_52w_high_pct', 'Distance from 52-week High %', IndicatorCategory::DESCRIPTIVE, 'percent', 'Percent distance of latest close from 52-week high (Stock Analytics).'),
            $metric('distance_52w_low_pct', 'Distance from 52-week Low %', IndicatorCategory::DESCRIPTIVE, 'percent', 'Percent distance of latest close from 52-week low (Stock Analytics).'),
            $metric('historical_volatility_pct', 'Historical Volatility %', IndicatorCategory::VOLATILITY, 'percent', 'Annualised log-return volatility proxy (Stock Analytics).'),
            $metric('beta', 'Beta (proxy)', IndicatorCategory::RISK, 'ratio', 'Soft beta proxy from volatility (Stock Analytics); not a formal regression beta.'),
            $metric('trend_strength', 'Trend Strength', IndicatorCategory::TREND, 'score_0_100', 'Heuristic 0–100 from close vs SMA50/200 alignment (Stock Analytics; independent of Strategy trend_score).'),
            $metric('maximum_drawdown_pct', 'Maximum Drawdown %', IndicatorCategory::RISK, 'percent', 'Peak-to-trough drawdown over loaded history (Stock Analytics).'),
            $metric('current_drawdown_pct', 'Current Drawdown %', IndicatorCategory::RISK, 'percent', 'Drawdown from peak to latest close (Stock Analytics).'),
            $metric('average_daily_volume_metric', 'Average Daily Volume (analytics)', IndicatorCategory::VOLUME, 'count', 'Descriptive ADV from Stock Analytics (distinct from planned Primary average_volume).', 0),
            $metric('liquidity_rating', 'Liquidity Rating', IndicatorCategory::LIQUIDITY, 'none', 'High/Medium/Low/Unknown label from notional ADV (Stock Analytics).', 0),
        ];
    }

    /**
     * Liquidity / Tradability composites — active for discovery, not Strategy-scorable.
     *
     * @return list<IndicatorDefinition>
     */
    private function liquidityTradabilityComposites(): array
    {
        $futureConsumers = [
            IndicatorConsumer::DISCOVERY,
            IndicatorConsumer::DASHBOARD,
            IndicatorConsumer::STOCK_DETAILS,
            IndicatorConsumer::ADMIN_REGISTRY,
            IndicatorConsumer::SCREENER,
        ];

        return [
            IndicatorDefinition::make('liquidity_score', IndicatorType::COMPOSITE, IndicatorCategory::LIQUIDITY, [
                'display_name' => 'Liquidity Score',
                'description' => '0–100 liquidity quality from Relative Turnover, Average Daily Turnover, and Average Daily Volume.',
                'version' => '1.0.0',
                'depends_on' => ['relative_turnover', 'average_turnover', 'average_volume'],
                'units' => 'score_0_100',
                'precision' => 2,
                'screenable' => false,
                'stock_level' => true,
                'consumers' => $futureConsumers,
                'status' => IndicatorStatus::ACTIVE,
                'formula_explanation' => implode("\n", [
                    'Computed by LiquidityTradabilityCalculator (not EvaluationEngine / Strategy).',
                    '1) Map relative_turnover → min(100, max(0, relative_turnover × 50)) (1.0 ≈ 50).',
                    '2) Map average_turnover → min(100, log10(turnover+1)/9 × 100).',
                    '3) Map average_volume → min(100, log10(volume+1)/8 × 100).',
                    '4) liquidity_score = mean of available mapped components.',
                    'Not included in Strategy catalogue or Recommendation Engine.',
                ]),
                'capabilities' => [
                    IndicatorCapability::STRATEGY_SCORABLE => false,
                    IndicatorCapability::EVALUATION_FACT => false,
                ],
            ]),
            IndicatorDefinition::make('tradability_score', IndicatorType::COMPOSITE, IndicatorCategory::TRADABILITY, [
                'display_name' => 'Tradability Score',
                'description' => '0–100 tradability / execution-friction score from gap and circuit heuristics.',
                'version' => '1.0.0',
                'depends_on' => ['gap_frequency', 'gap_fill_ratio', 'circuit_frequency', 'circuit_risk'],
                'units' => 'score_0_100',
                'precision' => 2,
                'screenable' => false,
                'stock_level' => true,
                'consumers' => $futureConsumers,
                'status' => IndicatorStatus::ACTIVE,
                'formula_explanation' => implode("\n", [
                    'Computed by LiquidityTradabilityCalculator (not EvaluationEngine / Strategy).',
                    '1) From gap_frequency → 100 × (1 − clamp01(frequency)).',
                    '2) From gap_fill_ratio → 100 × clamp01(ratio).',
                    '3) From circuit_frequency → 100 × (1 − clamp01(frequency)).',
                    '4) From circuit_risk → 100 − clamp(risk, 0, 100).',
                    '5) tradability_score = mean of available mapped components (higher = easier to trade).',
                    'Circuit inputs are OHLCV heuristics, not exchange circuit feeds.',
                    'Not included in Strategy catalogue or Recommendation Engine.',
                ]),
                'capabilities' => [
                    IndicatorCapability::STRATEGY_SCORABLE => false,
                    IndicatorCapability::EVALUATION_FACT => false,
                ],
            ]),
        ];
    }

    private function categoryForScreenerId(string $id): string
    {
        return match ($id) {
            'close', 'open', 'high', 'low', 'change_pct', 'high_n', 'low_n', 'high_52w', 'low_52w', 'range_pct' => IndicatorCategory::PRICE,
            'sma', 'ema', 'price_vs_sma_pct', 'price_vs_ema_pct', 'sma_spread_pct', 'ema_spread_pct' => IndicatorCategory::TREND,
            'rsi', 'roc', 'stoch_k', 'stoch_d', 'macd', 'macd_signal', 'macd_hist' => IndicatorCategory::MOMENTUM,
            'atr', 'bb_mid', 'bb_upper', 'bb_lower', 'bb_pct_b', 'bb_width_pct' => IndicatorCategory::VOLATILITY,
            'volume', 'volume_sma', 'volume_ratio', 'average_volume' => IndicatorCategory::VOLUME,
            'average_turnover', 'relative_turnover' => IndicatorCategory::LIQUIDITY,
            'gap_frequency', 'gap_fill_ratio', 'circuit_frequency' => IndicatorCategory::TRADABILITY,
            'circuit_risk' => IndicatorCategory::RISK,
            default => IndicatorCategory::DESCRIPTIVE,
        };
    }

    private function unitsForScreenerId(string $id): string
    {
        return match ($id) {
            'close', 'open', 'high', 'low', 'sma', 'ema', 'high_n', 'low_n', 'high_52w', 'low_52w', 'bb_mid', 'bb_upper', 'bb_lower', 'atr', 'macd', 'macd_signal', 'macd_hist' => 'price',
            'volume', 'volume_sma', 'average_volume' => 'count',
            'average_turnover' => 'currency',
            'change_pct', 'range_pct', 'price_vs_sma_pct', 'price_vs_ema_pct', 'sma_spread_pct', 'ema_spread_pct', 'roc', 'bb_width_pct', 'rsi', 'stoch_k', 'stoch_d' => 'percent',
            'volume_ratio', 'bb_pct_b', 'relative_turnover', 'gap_frequency', 'gap_fill_ratio', 'circuit_frequency' => 'ratio',
            'circuit_risk' => 'score_0_100',
            default => 'none',
        };
    }

    private function precisionForUnits(string $units): int
    {
        return match ($units) {
            'count' => 0,
            'percent', 'ratio', 'score_0_100' => 2,
            'price', 'currency' => 4,
            default => 2,
        };
    }
}
