<?php

namespace App\Engines\Evaluation;

/**
 * V4-FEAT-005 — numeric Evaluation representation of MarketAnalysisEngine::market_regime.
 *
 * Does not classify phases or regimes. Maps only the existing categorical values
 * Bullish / Neutral / Bearish (from MarketAnalysisEngine::regimeFromPhase()).
 */
class MarketRegimeScoreMapper
{
    public const BULLISH = 100.0;

    public const NEUTRAL = 50.0;

    public const BEARISH = 0.0;

    public function score(?string $regime): float
    {
        return match ($regime) {
            'Bullish' => self::BULLISH,
            'Bearish' => self::BEARISH,
            default => self::NEUTRAL,
        };
    }
}
