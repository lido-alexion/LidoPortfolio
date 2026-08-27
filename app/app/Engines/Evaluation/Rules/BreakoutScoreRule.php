<?php

namespace App\Engines\Evaluation\Rules;

use App\Engines\Evaluation\EvaluationFactorContext;
use App\Engines\Evaluation\EvaluationFactorResult;
use App\Engines\Evaluation\EvaluationFactorRule;

final class BreakoutScoreRule implements EvaluationFactorRule
{
    public function key(): string
    {
        return 'breakout_score';
    }

    public function evaluate(EvaluationFactorContext $context): EvaluationFactorResult
    {
        $score = 0.0;
        $passed = [];
        $failed = [];
        if ($context->patternCount > 0) {
            $score = min(100.0, 40.0 + ($context->patternCount * 20.0));
            $passed[] = 'pattern_present';
        } else {
            $failed[] = 'no_pattern';
        }

        return new EvaluationFactorResult($this->key(), $score, $passed, $failed, ['pattern_bonus' => $score]);
    }
}
