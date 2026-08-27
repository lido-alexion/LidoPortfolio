<?php

namespace App\Engines\Evaluation;

/**
 * V4-FEAT-029 — one Evaluation catalogue factor.
 * Stateless. Does not fetch data, persist results, or aggregate the run score.
 */
interface EvaluationFactorRule
{
    /**
     * Catalogue factor key (e.g. market_regime). Must match existing Evaluation evidence keys.
     */
    public function key(): string;

    public function evaluate(EvaluationFactorContext $context): EvaluationFactorResult;
}
