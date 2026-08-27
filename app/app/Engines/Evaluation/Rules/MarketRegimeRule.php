<?php

namespace App\Engines\Evaluation\Rules;

use App\Engines\Evaluation\EvaluationFactorContext;
use App\Engines\Evaluation\EvaluationFactorResult;
use App\Engines\Evaluation\EvaluationFactorRule;

/**
 * Uses the run-level Market Analysis mapping already on the context (V4-FEAT-005).
 * Does not call MarketAnalysisEngine.
 */
final class MarketRegimeRule implements EvaluationFactorRule
{
    public function key(): string
    {
        return 'market_regime';
    }

    public function evaluate(EvaluationFactorContext $context): EvaluationFactorResult
    {
        return new EvaluationFactorResult($this->key(), $context->marketRegimeScore);
    }
}
