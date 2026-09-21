<?php

namespace App\Services\ML;

use App\Engines\Evaluation\EvaluationParameterResolver;
use App\Engines\Strategy\MinerviniTrendTemplateScreener;
use App\Models\StockPrice;
use App\Services\Artifacts\DefinitionHasher;
use App\Services\Backtest\AsOfFactorScorer;
use App\Services\Screener\ScreenerEvaluationService;
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
        private readonly ScreenerEvaluationService $screeners,
    ) {}

    /** @return array<string,mixed> */
    public function definition(): array
    {
        $config = $this->strategies->defaultConfig();
        $eligibility = MinerviniTrendTemplateScreener::definition();
        $eligibilityHash = DefinitionHasher::hash($eligibility);
        $config['eligibility_sources'] = [[
            'factory_key' => MinerviniTrendTemplateScreener::FACTORY_KEY,
            'screener_factory_key' => MinerviniTrendTemplateScreener::FACTORY_KEY,
            'screener_slug' => MinerviniTrendTemplateScreener::FACTORY_KEY,
            'artifact_version' => 'factory:1.0',
            'definition_hash' => $eligibilityHash,
            'definition' => $eligibility,
            'enabled' => true,
            'priority' => 1,
        ]];
        $encoded = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $strategyHash = hash('sha256', $encoded);

        return [
            'strategy_id' => null,
            'strategy_key' => 'momentum_factory',
            'artifact_version' => 'factory:1.0',
            'definition_hash' => $strategyHash,
            'strategy_definition_hash' => $strategyHash,
            'adapter_version' => self::ADAPTER_VERSION,
            'decision_semantics' => [
                'type' => 'entry_decision',
                'threshold_key' => 'open_position',
                'threshold' => (float) ($config['thresholds']['open_position'] ?? 85.0),
                'requires_eligibility' => true,
            ],
            'eligibility_sources' => $config['eligibility_sources'],
            'config' => $config,
        ];
    }

    /** @return array{score:float,eligible:bool,strategy_eligible:bool,positive_decision:bool,decision_threshold:float,factor_scores:array<string,mixed>,parameters:array<string,mixed>} */
    public function score(int $stockId, string $referenceDate, ?array $definition = null, ?array $bars = null): array
    {
        $definition ??= $this->definition();
        $bars = $bars === null ? null : $this->barsAsOf($bars, $referenceDate);
        $config = $definition['config'] ?? $this->strategies->defaultConfig();
        $resolved = $this->parameters->resolve($config);
        $evaluation = $this->factors->score($stockId, $referenceDate, $resolved, $bars);
        if (($evaluation['skipped'] ?? false) === true) {
            return ['score' => 0.0, 'eligible' => false, 'strategy_eligible' => false, 'positive_decision' => false, 'decision_threshold' => $this->decisionThreshold($definition), 'factor_scores' => [], 'parameters' => $resolved];
        }

        $scored = $this->strategies->score($evaluation['factor_scores'] ?? [], $config);
        $thresholds = $config['thresholds'] ?? [];
        $minimum = (float) ($thresholds['minimum_overall_score'] ?? 0.0);
        $strategyEligible = $this->evaluateEligibility($stockId, $referenceDate, $definition, $bars);
        $score = (float) $scored['overall_score'];
        $decisionThreshold = $this->decisionThreshold($definition);

        return [
            'score' => $score,
            'eligible' => $strategyEligible && $score >= $minimum,
            'strategy_eligible' => $strategyEligible,
            'positive_decision' => $this->isPositiveDecision($score, $strategyEligible, $definition),
            'decision_threshold' => $decisionThreshold,
            'factor_scores' => $evaluation['factor_scores'] ?? [],
            'parameters' => $resolved,
        ];
    }

    private function decisionThreshold(array $definition): float
    {
        return (float) ($definition['decision_semantics']['threshold'] ?? 85.0);
    }

    public function isPositiveDecision(float $score, bool $strategyEligible, array $definition): bool
    {
        return $strategyEligible && $score >= $this->decisionThreshold($definition);
    }

    /** @return list<array<string,mixed>> */
    public function barsForStock(int $stockId, string $throughDate): array
    {
        return StockPrice::query()->where('stock_id', $stockId)->whereDate('price_date', '<=', $throughDate)
            ->orderBy('price_date')->get()->map(fn ($row) => [
                'date' => $row->price_date->toDateString(),
                'open' => $row->open_price !== null ? (float) $row->open_price : null,
                'high' => $row->high_price !== null ? (float) $row->high_price : null,
                'low' => $row->low_price !== null ? (float) $row->low_price : null,
                'close' => $row->close_price !== null ? (float) $row->close_price : null,
                'volume' => $row->volume !== null ? (float) $row->volume : null,
            ])->all();
    }

    private function evaluateEligibility(int $stockId, string $referenceDate, array $definition, ?array $bars = null): bool
    {
        $source = $definition['eligibility_sources'][0] ?? null;
        $screenerDefinition = is_array($source) ? ($source['definition'] ?? null) : null;
        if (! is_array($screenerDefinition)) {
            return false;
        }
        $bars ??= $this->barsForStock($stockId, $referenceDate);
        if (count($bars) > 400) {
            $bars = array_slice($bars, -400);
        }

        $result = $this->screeners->evaluateStock($screenerDefinition, $bars);
        return ($result['skipped'] ?? true) === false && ($result['matched'] ?? false) === true;
    }

    /** @param list<array<string,mixed>> $bars */
    private function barsAsOf(array $bars, string $referenceDate): array
    {
        $bounded = array_values(array_filter($bars, static fn (array $bar): bool => (string) ($bar['date'] ?? '') <= $referenceDate));
        return count($bounded) > 400 ? array_slice($bounded, -400) : $bounded;
    }
}
