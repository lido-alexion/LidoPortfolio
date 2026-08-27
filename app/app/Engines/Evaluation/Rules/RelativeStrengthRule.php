<?php

namespace App\Engines\Evaluation\Rules;

use App\Engines\Evaluation\EvaluationFactorContext;
use App\Engines\Evaluation\EvaluationFactorResult;
use App\Engines\Evaluation\EvaluationFactorRule;

final class RelativeStrengthRule implements EvaluationFactorRule
{
    public function key(): string
    {
        return 'relative_strength';
    }

    public function evaluate(EvaluationFactorContext $context): EvaluationFactorResult
    {
        $score = 50.0;
        $passed = [];
        $failed = [];
        if ($context->relativeStrength !== null) {
            if ($context->relativeStrength >= 1.05) {
                $score = 100.0;
                $passed[] = 'rs_outperform';
            } elseif ($context->relativeStrength >= 1.0) {
                $score = 70.0;
                $passed[] = 'rs_inline';
            } else {
                $score = 30.0;
                $failed[] = 'rs_underperform';
            }
        } else {
            $failed[] = 'rs_unavailable';
        }

        return new EvaluationFactorResult($this->key(), $score, $passed, $failed);
    }
}
