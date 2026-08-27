<?php

namespace App\Engines\Evaluation\Rules;

use App\Engines\Evaluation\EvaluationFactorContext;
use App\Engines\Evaluation\EvaluationFactorResult;
use App\Engines\Evaluation\EvaluationFactorRule;

final class MomentumScoreRule implements EvaluationFactorRule
{
    public function key(): string
    {
        return 'momentum_score';
    }

    public function evaluate(EvaluationFactorContext $context): EvaluationFactorResult
    {
        $score = 50.0;
        $passed = [];
        $failed = [];
        if ($context->rsi !== null) {
            if ($context->rsi >= 45 && $context->rsi <= 70) {
                $score = 100.0;
                $passed[] = 'rsi_healthy';
            } elseif ($context->rsi > 70) {
                $score = 55.0;
                $failed[] = 'rsi_overbought';
            } elseif ($context->rsi < 30) {
                $score = 35.0;
                $failed[] = 'rsi_oversold';
            } else {
                $score = 50.0;
            }
        } else {
            $failed[] = 'rsi_unavailable';
        }

        return new EvaluationFactorResult($this->key(), $score, $passed, $failed, ['momentum' => $score]);
    }
}
