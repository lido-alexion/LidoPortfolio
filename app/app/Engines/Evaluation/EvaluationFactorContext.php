<?php

namespace App\Engines\Evaluation;

/**
 * Immutable, already-resolved inputs for factor rules (V4-FEAT-029).
 * Built once per candidate after shared indicator / RS / market-regime lookup.
 */
final class EvaluationFactorContext
{
    public function __construct(
        public readonly ?float $close,
        public readonly ?float $smaFast,
        public readonly ?float $smaSlow,
        public readonly ?float $rsi,
        public readonly ?float $atr,
        public readonly ?float $atrPct,
        public readonly ?float $volumeRatio,
        public readonly ?float $priceVsSma,
        public readonly ?float $relativeStrength,
        public readonly string $marketRegime,
        public readonly float $marketRegimeScore,
        public readonly int $patternCount,
    ) {}
}
