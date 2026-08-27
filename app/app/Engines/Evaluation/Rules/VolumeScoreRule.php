<?php

namespace App\Engines\Evaluation\Rules;

use App\Engines\Evaluation\EvaluationFactorContext;
use App\Engines\Evaluation\EvaluationFactorResult;
use App\Engines\Evaluation\EvaluationFactorRule;

final class VolumeScoreRule implements EvaluationFactorRule
{
    public function key(): string
    {
        return 'volume_score';
    }

    public function evaluate(EvaluationFactorContext $context): EvaluationFactorResult
    {
        $score = 50.0;
        $passed = [];
        $failed = [];
        if ($context->volumeRatio !== null) {
            if ($context->volumeRatio >= 1.2) {
                $score = 100.0;
                $passed[] = 'volume_expansion';
            } elseif ($context->volumeRatio >= 0.8) {
                $score = 60.0;
            } else {
                $score = 30.0;
                $failed[] = 'volume_weak';
            }
        }

        return new EvaluationFactorResult($this->key(), $score, $passed, $failed, ['volume' => $score]);
    }
}
