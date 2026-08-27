<?php

namespace App\Engines\Evaluation;

/**
 * One factor's numeric fact plus the pass/fail tags that factor historically emitted.
 *
 * @phpstan-type AliasMap array<string, float>
 */
final class EvaluationFactorResult
{
    /**
     * @param  list<string>  $passed
     * @param  list<string>  $failed
     * @param  array<string, float>  $aliases  Legacy factor_scores keys (momentum, trend, …)
     */
    public function __construct(
        public readonly string $key,
        public readonly float $score,
        public readonly array $passed = [],
        public readonly array $failed = [],
        public readonly array $aliases = [],
    ) {}
}
