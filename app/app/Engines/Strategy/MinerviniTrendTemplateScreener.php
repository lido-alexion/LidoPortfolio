<?php

namespace App\Engines\Strategy;

/**
 * Factory Mark Minervini Trend Template Screener (SD-030).
 * Eligibility only — no ranking / recommendations.
 *
 * Classic template approximated with existing Screener indicators:
 * price above SMA 50/150/200, SMA stack ordered, ≥25% above 52w low,
 * within 25% of 52w high. (200-DMA rising slope deferred — not in V1 catalog.)
 */
final class MinerviniTrendTemplateScreener
{
    public const NAME = 'Minervini Trend Template';

    public const FACTORY_KEY = 'minervini_trend_template';

    public const DESCRIPTION = 'Mark Minervini–style trend template: price above rising SMA stack, stage-2 proximity to highs. Factory eligibility screener for Momentum Strategy.';

    /**
     * @return array{root: array<string, mixed>}
     */
    public static function definition(): array
    {
        $cond = static function (array $left, string $operator, array $right, float $weight = 1.0): array {
            return [
                'type' => 'condition',
                'left' => $left,
                'operator' => $operator,
                'weight_factor' => $weight,
                'right' => $right,
            ];
        };

        $sma = static fn (int $period): array => ['indicator' => 'sma', 'params' => ['period' => $period]];
        $close = ['indicator' => 'close', 'params' => []];

        return [
            'root' => [
                'type' => 'group',
                'op' => 'AND',
                'children' => [
                    // Price > 150 DMA
                    $cond($close, 'gt', $sma(150)),
                    // Price > 200 DMA
                    $cond($close, 'gt', $sma(200)),
                    // 150 DMA > 200 DMA
                    $cond(
                        ['indicator' => 'sma_spread_pct', 'params' => ['fast' => 150, 'slow' => 200]],
                        'gt',
                        ['type' => 'constant', 'value' => 0]
                    ),
                    // Price > 50 DMA
                    $cond($close, 'gt', $sma(50)),
                    // 50 DMA > 150 DMA
                    $cond(
                        ['indicator' => 'sma_spread_pct', 'params' => ['fast' => 50, 'slow' => 150]],
                        'gt',
                        ['type' => 'constant', 'value' => 0]
                    ),
                    // Price ≥ 25% above 52-week low  → close >= 1.25 × low_52w
                    $cond($close, 'gte', ['indicator' => 'low_52w', 'params' => []], 1.25),
                    // Price within 25% of 52-week high → close >= 0.75 × high_52w
                    $cond($close, 'gte', ['indicator' => 'high_52w', 'params' => []], 0.75),
                ],
            ],
        ];
    }
}
