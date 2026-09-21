<?php

namespace App\Services\ML;

use App\Engines\Evaluation\EvaluationParameterResolver;
use App\Services\Backtest\AsOfFactorScorer;
use App\Services\StrategyConfigurationService;

/**
 * The deterministic historical Strategy/Evaluation boundary used by ML
 * comparisons.  It is deliberately pinned to the factory definition rather
 * than resolving a user's current live strategy while a run is in progress.
 */
final class HistoricalStrategyScoreService
{
    public const ADAPTER_VERSION = 'v7-historical-strategy-baseline-2';

    public function __construct(
        private readonly AsOfFactorScorer $factors,
        private readonly EvaluationParameterResolver $parameters,
        private readonly StrategyConfigurationService $strategies,
    ) {}

    /** @return array<string,mixed> */
    public function definition(): array
    {
        $config = $this->strategies->defaultConfig();
        $encoded = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'strategy_id' => null,
            'strategy_key' => 'momentum_factory',
            'artifact_version' => 'factory:1.0',
            'definition_hash' => hash('sha256', $encoded),
            'adapter_version' => self::ADAPTER_VERSION,
            'config' => $config,
        ];
    }

    /** @return array{score:float,eligible:bool,factor_scores:array<string,mixed>,parameters:array<string,mixed>} */
    public function score(int $stockId, string $referenceDate, ?array $definition = null): array
    {
        $definition ??= $this->definition();
        $config = $definition['config'] ?? $this->strategies->defaultConfig();
        $resolved = $this->parameters->resolve($config);
        $evaluation = $this->factors->score($stockId, $referenceDate, $resolved);
        if (($evaluation['skipped'] ?? false) === true) {
            return ['score' => 0.0, 'eligible' => false, 'factor_scores' => [], 'parameters' => $resolved];
        }

        $scored = $this->strategies->score($evaluation['factor_scores'] ?? [], $config);
        $thresholds = $config['thresholds'] ?? [];
        $minimum = (float) ($thresholds['minimum_overall_score'] ?? 0.0);

        return [
            'score' => (float) $scored['overall_score'],
            'eligible' => (float) $scored['overall_score'] >= $minimum,
            'factor_scores' => $evaluation['factor_scores'] ?? [],
            'parameters' => $resolved,
        ];
    }
}
