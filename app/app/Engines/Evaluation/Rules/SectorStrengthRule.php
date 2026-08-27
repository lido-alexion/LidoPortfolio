<?php

namespace App\Engines\Evaluation\Rules;

use App\Engines\Evaluation\EvaluationFactorContext;
use App\Engines\Evaluation\EvaluationFactorResult;
use App\Engines\Evaluation\EvaluationFactorRule;

/** Existing 50 stub — not a real sector model (V4-FEAT-029 does not replace it). */
final class SectorStrengthRule implements EvaluationFactorRule
{
    public function key(): string
    {
        return 'sector_strength';
    }

    public function evaluate(EvaluationFactorContext $context): EvaluationFactorResult
    {
        return new EvaluationFactorResult($this->key(), 50.0);
    }
}
