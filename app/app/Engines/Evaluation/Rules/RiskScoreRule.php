<?php

namespace App\Engines\Evaluation\Rules;

use App\Engines\Evaluation\EvaluationFactorContext;
use App\Engines\Evaluation\EvaluationFactorResult;
use App\Engines\Evaluation\EvaluationFactorRule;

final class RiskScoreRule implements EvaluationFactorRule
{
    public function key(): string
    {
        return 'risk_score';
    }

    public function evaluate(EvaluationFactorContext $context): EvaluationFactorResult
    {
        $score = 50.0;
        $passed = [];
        $failed = [];
        if ($context->atrPct !== null) {
            $score = round(max(0.0, min(100.0, $context->atrPct * 10.0)), 4);
            if ($score <= 20) {
                $passed[] = 'risk_contained';
            } elseif ($score >= 40) {
                $failed[] = 'risk_elevated';
            }
        } else {
            $failed[] = 'atr_unavailable';
        }

        return new EvaluationFactorResult($this->key(), $score, $passed, $failed, ['risk' => $score]);
    }
}
