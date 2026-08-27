<?php

namespace App\Engines\Evaluation;

/**
 * Ordered, explicitly registered Evaluation factor rules (V4-FEAT-029).
 * Registration order is the pass/fail tag order (must match pre-refactor EvaluationEngine).
 */
final class EvaluationFactorRuleSet
{
    /**
     * Catalogue keys used for list ranking (equal-weight mean) and indicator_scores.
     * Order matches the pre-refactor factor_scores object (not pass/fail tag order).
     *
     * @var list<string>
     */
    public const CATALOGUE_KEYS = [
        'relative_strength',
        'momentum_score',
        'trend_score',
        'breakout_score',
        'volume_score',
        'market_regime',
        'sector_strength',
        'risk_score',
    ];

    /**
     * @param  iterable<EvaluationFactorRule>  $rules
     */
    public function __construct(
        protected iterable $rules,
    ) {}

    /**
     * @return list<EvaluationFactorRule>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->rules as $rule) {
            if ($rule instanceof EvaluationFactorRule) {
                $out[] = $rule;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values(array_map(static fn (EvaluationFactorRule $rule) => $rule->key(), $this->all()));
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    /**
     * @return list<EvaluationFactorRule>
     */
    public function without(string $key): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (EvaluationFactorRule $rule) => $rule->key() !== $key,
        ));
    }
}
