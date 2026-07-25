<?php

namespace App\Engines\Strategy;

/**
 * Evaluate Strategy exit rules against holding facts (SD-030).
 * Rules are declarative — no duplicated Screener engine; uses Evaluation facts.
 */
final class ExitStrategyEvaluator
{
    /**
     * @param  array<string, mixed>  $exitConfig
     * @param  array<string, mixed>  $context  score, indicator_scores, indicators, holding meta
     * @return array{triggered: bool, matched: list<array<string, mixed>>, status: string}
     */
    public static function evaluate(array $exitConfig, array $context): array
    {
        if (! ($exitConfig['enabled'] ?? true)) {
            return ['triggered' => false, 'matched' => [], 'status' => 'Disabled'];
        }

        $rules = $exitConfig['rules'] ?? [];
        if (! is_array($rules) || $rules === []) {
            return ['triggered' => false, 'matched' => [], 'status' => 'Not Triggered'];
        }

        $matched = [];
        $scores = is_array($context['indicator_scores'] ?? null) ? $context['indicator_scores'] : [];
        $indicators = is_array($context['indicators'] ?? null) ? $context['indicators'] : [];
        $score = (float) ($context['overall_score'] ?? 0);
        $unrealizedPct = isset($context['unrealized_pnl_pct']) && is_numeric($context['unrealized_pnl_pct'])
            ? (float) $context['unrealized_pnl_pct']
            : null;

        foreach ($rules as $rule) {
            if (! is_array($rule) || ! ($rule['enabled'] ?? true)) {
                continue;
            }
            $key = (string) ($rule['key'] ?? '');
            $hit = false;
            $detail = null;

            switch ($key) {
                case 'score_exit':
                    $max = (float) ($rule['value'] ?? 20);
                    $hit = $score <= $max;
                    $detail = "Overall score {$score} ≤ {$max}";
                    break;
                case 'rs_weakening':
                    $max = (float) ($rule['value'] ?? 40);
                    $rs = (float) ($scores['relative_strength'] ?? 100);
                    $hit = $rs < $max;
                    $detail = "Relative strength {$rs} < {$max}";
                    break;
                case 'trend_weakening':
                    $max = (float) ($rule['value'] ?? 40);
                    $trend = (float) ($scores['trend_score'] ?? $scores['trend'] ?? 100);
                    $hit = $trend < $max;
                    $detail = "Trend score {$trend} < {$max}";
                    break;
                case 'ma_breakdown':
                    $period = (int) ($rule['params']['period'] ?? 50);
                    $priceVs = $indicators['price_vs_sma_pct'] ?? null;
                    // Fallback: compare close vs sma_fast/slow when period matches evaluation defaults
                    if (is_numeric($priceVs) && $period <= 50) {
                        $hit = (float) $priceVs < 0;
                        $detail = "Price vs SMA {$priceVs}% < 0";
                    } elseif (isset($indicators['close'], $indicators['sma_fast']) && $period <= 50) {
                        $hit = (float) $indicators['close'] < (float) $indicators['sma_fast'];
                        $detail = 'Close below fast SMA';
                    } elseif (isset($indicators['close'], $indicators['sma_slow'])) {
                        $hit = (float) $indicators['close'] < (float) $indicators['sma_slow'];
                        $detail = 'Close below slow SMA';
                    }
                    break;
                case 'max_loss':
                    $maxLoss = (float) ($rule['value'] ?? 8);
                    if ($unrealizedPct !== null) {
                        $hit = $unrealizedPct <= -abs($maxLoss);
                        $detail = "Unrealized PnL {$unrealizedPct}% ≤ -{$maxLoss}%";
                    }
                    break;
                case 'atr_stop':
                    $mult = (float) ($rule['atr_multiple'] ?? $rule['value'] ?? 2);
                    $atrPct = isset($indicators['atr_pct']) ? (float) $indicators['atr_pct'] : null;
                    if ($atrPct !== null && $unrealizedPct !== null) {
                        $stop = -abs($mult * $atrPct);
                        $hit = $unrealizedPct <= $stop;
                        $detail = "Unrealized {$unrealizedPct}% ≤ ATR stop {$stop}%";
                    }
                    break;
                case 'trailing_stop':
                    // V1: treat as max_loss from peak proxy using unrealized when negative
                    $trail = (float) ($rule['value'] ?? 10);
                    if ($unrealizedPct !== null) {
                        $hit = $unrealizedPct <= -abs($trail);
                        $detail = "Trailing proxy: unrealized {$unrealizedPct}% ≤ -{$trail}%";
                    }
                    break;
                default:
                    break;
            }

            if ($hit) {
                $matched[] = [
                    'key' => $key,
                    'display_name' => (string) ($rule['display_name'] ?? $key),
                    'detail' => $detail,
                ];
            }
        }

        $mode = (string) ($exitConfig['mode'] ?? 'any');
        $triggered = $mode === 'all'
            ? (count($matched) === count(array_filter($rules, fn ($r) => is_array($r) && ($r['enabled'] ?? true))))
            : $matched !== [];

        return [
            'triggered' => $triggered,
            'matched' => $matched,
            'status' => $triggered ? 'Triggered' : 'Not Triggered',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function defaultRules(): array
    {
        return [
            [
                'key' => 'ma_breakdown',
                'display_name' => 'Moving Average Breakdown',
                'description' => 'Price closes below the primary moving average.',
                'enabled' => true,
                'params' => ['period' => 50],
            ],
            [
                'key' => 'rs_weakening',
                'display_name' => 'Relative Strength Weakening',
                'description' => 'Relative strength falls below threshold.',
                'enabled' => true,
                'value' => 40,
            ],
            [
                'key' => 'trend_weakening',
                'display_name' => 'Trend Weakening',
                'description' => 'Trend score falls below threshold.',
                'enabled' => true,
                'value' => 40,
            ],
            [
                'key' => 'score_exit',
                'display_name' => 'Overall Score Exit',
                'description' => 'Strategy score at or below exit threshold.',
                'enabled' => true,
                'value' => 20,
            ],
            [
                'key' => 'max_loss',
                'display_name' => 'Maximum Loss',
                'description' => 'Unrealized loss exceeds maximum loss %.',
                'enabled' => true,
                'value' => 8,
            ],
            [
                'key' => 'atr_stop',
                'display_name' => 'ATR Stop',
                'description' => 'Unrealized loss exceeds N × ATR%.',
                'enabled' => false,
                'atr_multiple' => 2,
            ],
            [
                'key' => 'trailing_stop',
                'display_name' => 'Trailing Stop',
                'description' => 'Trailing stop proxy via unrealized drawdown % (V1).',
                'enabled' => false,
                'value' => 10,
            ],
        ];
    }
}
