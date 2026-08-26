<?php

namespace App\Services\Indicators;

/**
 * Minimum bar requirements for Screener Primaries (unchanged formulas from legacy ScreenerCatalog).
 * Kept outside Registry metadata because callables are not serialisable metadata.
 */
final class ScreenerMinBars
{
    /**
     * @param  array<string, mixed>  $params
     */
    public static function compute(string $id, array $params): int
    {
        return match ($id) {
            'change_pct' => (int) ($params['period'] ?? 1) + 1,
            'high_n', 'low_n' => (int) ($params['period'] ?? 20),
            'sma', 'price_vs_sma_pct', 'bb_mid', 'bb_upper', 'bb_lower', 'bb_pct_b', 'bb_width_pct',
            'volume_sma', 'volume_ratio', 'average_volume', 'average_turnover' => (int) ($params['period'] ?? 20),
            'relative_turnover', 'liquidity_score' => max((int) ($params['period'] ?? 20), (int) ($params['baseline'] ?? 60)),
            'gap_frequency', 'gap_fill_ratio', 'circuit_frequency', 'circuit_risk', 'tradability_score' => (int) ($params['period'] ?? 60),
            'ema', 'price_vs_ema_pct' => (int) ($params['period'] ?? 50),
            'sma_spread_pct' => max((int) ($params['fast'] ?? 20), (int) ($params['slow'] ?? 50)),
            'ema_spread_pct' => max((int) ($params['fast'] ?? 12), (int) ($params['slow'] ?? 26)),
            'rsi' => (int) ($params['period'] ?? 14) + 1,
            'roc' => (int) ($params['period'] ?? 12) + 1,
            'stoch_k' => (int) ($params['period'] ?? 14),
            'stoch_d' => (int) ($params['period'] ?? 14) + (int) ($params['smooth'] ?? 3) - 1,
            'macd' => max((int) ($params['fast'] ?? 12), (int) ($params['slow'] ?? 26)),
            'macd_signal', 'macd_hist' => max((int) ($params['fast'] ?? 12), (int) ($params['slow'] ?? 26))
                + (int) ($params['signal'] ?? 9) - 1,
            'atr' => (int) ($params['period'] ?? 14),
            default => 1,
        };
    }
}
