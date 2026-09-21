<?php

namespace Tests\Unit;

use App\Services\Backtest\AsOfFactorScorer;
use App\Services\ML\HistoricalStrategyScoreService;
use App\Services\ML\MlDeterministicBaselineAdapter;
use App\Services\Screener\ScreenerEvaluationService;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HistoricalStrategyScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_weights_change_the_historical_baseline_score(): void
    {
        $factors = Mockery::mock(AsOfFactorScorer::class);
        $factors->shouldReceive('score')->twice()->andReturn([
            'skipped' => false,
            'factor_scores' => ['relative_strength' => 100.0, 'trend_score' => 20.0],
        ]);
        $service = new HistoricalStrategyScoreService($factors, app(\App\Engines\Evaluation\EvaluationParameterResolver::class), app(StrategyConfigurationService::class), app(ScreenerEvaluationService::class));
        $definition = $service->definition();

        foreach ($definition['config']['indicators'] as &$indicator) {
            $indicator['enabled'] = in_array($indicator['key'], ['relative_strength', 'trend_score'], true);
            $indicator['minimum'] = null;
            $indicator['maximum'] = null;
            $indicator['weight'] = $indicator['key'] === 'relative_strength' ? 50 : ($indicator['key'] === 'trend_score' ? 50 : 0);
        }
        unset($indicator);
        $balanced = $service->score(7, '2025-01-01', $definition)['score'];

        foreach ($definition['config']['indicators'] as &$indicator) {
            $indicator['weight'] = $indicator['key'] === 'relative_strength' ? 90 : ($indicator['key'] === 'trend_score' ? 10 : 0);
        }
        unset($indicator);
        $weighted = $service->score(7, '2025-01-01', $definition)['score'];

        $this->assertNotSame($balanced, $weighted);
        $this->assertSame(HistoricalStrategyScoreService::ADAPTER_VERSION, $definition['adapter_version']);
        $this->assertNotEmpty($definition['definition_hash']);
    }

    public function test_baseline_adapter_aligns_one_strategy_score_to_each_test_row(): void
    {
        $factors = Mockery::mock(AsOfFactorScorer::class);
        $factors->shouldReceive('score')->twice()->andReturn(
            ['skipped' => false, 'factor_scores' => array_fill_keys(['relative_strength', 'momentum_score', 'trend_score', 'breakout_score', 'volume_score', 'market_regime', 'sector_strength', 'risk_score'], 100.0)],
            ['skipped' => false, 'factor_scores' => array_fill_keys(['relative_strength', 'momentum_score', 'trend_score', 'breakout_score', 'volume_score', 'market_regime', 'sector_strength', 'risk_score'], 0.0)],
        );
        $strategy = new HistoricalStrategyScoreService($factors, app(\App\Engines\Evaluation\EvaluationParameterResolver::class), app(StrategyConfigurationService::class), app(ScreenerEvaluationService::class));
        $adapter = new MlDeterministicBaselineAdapter($strategy);

        $result = $adapter->evaluate([
            ['partition' => 'train', 'stock_id' => 1, 'reference_date' => '2025-01-01'],
            ['partition' => 'test', 'stock_id' => 1, 'reference_date' => '2025-02-01'],
            ['partition' => 'test', 'stock_id' => 2, 'reference_date' => '2025-02-02'],
        ]);

        $this->assertCount(2, $result);
        $this->assertGreaterThan($result[1]['probability'], $result[0]['probability']);
        $this->assertSame($result[0]['score'] / 100, $result[0]['probability']);
    }

    public function test_factory_entry_decision_uses_open_position_threshold_not_classifier_half(): void
    {
        $factors = Mockery::mock(AsOfFactorScorer::class);
        $service = new HistoricalStrategyScoreService($factors, app(\App\Engines\Evaluation\EvaluationParameterResolver::class), app(StrategyConfigurationService::class), app(ScreenerEvaluationService::class));
        $definition = $service->definition();

        $this->assertSame(85.0, $definition['decision_semantics']['threshold']);
        $this->assertFalse($service->isPositiveDecision(60.0, true, $definition));
        $this->assertFalse($service->isPositiveDecision(84.99, true, $definition));
        $this->assertTrue($service->isPositiveDecision(85.0, true, $definition));
        $this->assertFalse($service->isPositiveDecision(100.0, false, $definition));
    }

    public function test_preloaded_historical_bars_are_bounded_to_the_latest_four_hundred_as_of_reference(): void
    {
        $factors = Mockery::mock(AsOfFactorScorer::class);
        $factors->shouldReceive('score')->once()->andReturn([
            'skipped' => false,
            'factor_scores' => array_fill_keys(['relative_strength', 'momentum_score', 'trend_score', 'breakout_score', 'volume_score', 'market_regime', 'sector_strength', 'risk_score'], 100.0),
        ]);
        $screeners = Mockery::mock(ScreenerEvaluationService::class);
        $screeners->shouldReceive('evaluateStock')->once()->withArgs(function (array $definition, array $bars): bool {
            return count($bars) === 400 && $bars[0]['date'] === '2025-02-04' && $bars[array_key_last($bars)]['date'] === '2026-03-10';
        })->andReturn(['skipped' => false, 'matched' => true]);
        $service = new HistoricalStrategyScoreService($factors, app(\App\Engines\Evaluation\EvaluationParameterResolver::class), app(StrategyConfigurationService::class), $screeners);
        $bars = [];
        foreach (range(0, 799) as $day) {
            $bars[] = ['date' => \Carbon\Carbon::parse('2024-01-01')->addDays($day)->toDateString(), 'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1000];
        }

        $result = $service->score(7, '2026-03-10', $service->definition(), $bars);

        $this->assertTrue($result['positive_decision']);
    }
}
