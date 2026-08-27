<?php

namespace App\Engines\Evaluation\Rules;

use App\Engines\Evaluation\EvaluationFactorContext;
use App\Engines\Evaluation\EvaluationFactorResult;
use App\Engines\Evaluation\EvaluationFactorRule;

final class TrendScoreRule implements EvaluationFactorRule
{
    public function key(): string
    {
        return 'trend_score';
    }

    public function evaluate(EvaluationFactorContext $context): EvaluationFactorResult
    {
        $score = 0.0;
        $passed = [];
        $failed = [];
        if ($context->close !== null && $context->smaFast !== null && $context->smaSlow !== null) {
            if ($context->close > $context->smaFast && $context->smaFast > $context->smaSlow) {
                $score = 100.0;
                $passed[] = 'uptrend_sma_stack';
            } elseif ($context->close > $context->smaFast) {
                $score = 60.0;
                $passed[] = 'price_above_sma_fast';
            } else {
                $score = 20.0;
                $failed[] = 'price_below_sma_fast';
            }
        } else {
            $failed[] = 'sma_unavailable';
        }

        return new EvaluationFactorResult($this->key(), $score, $passed, $failed, ['trend' => $score]);
    }
}
