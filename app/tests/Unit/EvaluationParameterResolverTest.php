<?php

namespace Tests\Unit;

use App\Engines\Evaluation\EvaluationParameterResolver;
use App\Engines\Strategy\SupportedIndicators;
use App\Support\TradingOsConfig;
use Tests\TestCase;

class EvaluationParameterResolverTest extends TestCase
{
    public function test_missing_strategy_parameters_fall_back_to_evaluation_globals(): void
    {
        $resolver = app(EvaluationParameterResolver::class);
        $globals = $resolver->globals();
        $resolved = $resolver->resolve([
            'indicators' => [
                [
                    'key' => SupportedIndicators::MOMENTUM_SCORE,
                    'parameters' => [],
                ],
            ],
        ]);

        $eval = TradingOsConfig::evaluation();
        $this->assertSame((int) ($eval['rsi_period'] ?? 14), $resolved['rsi_period']);
        $this->assertSame((int) ($eval['sma_fast'] ?? 20), $resolved['sma_fast']);
        $this->assertSame((int) ($eval['sma_slow'] ?? 50), $resolved['sma_slow']);
        $this->assertSame((int) ($eval['atr_period'] ?? 14), $resolved['atr_period']);
        $this->assertSame((int) ($eval['volume_sma_period'] ?? 20), $resolved['volume_sma_period']);
        $this->assertNull($resolved['lookback_days']);
        $this->assertFalse($resolved['use_lookback_days']);
        $this->assertNull($resolved['benchmark']);
        $this->assertSame($globals['weights'], $resolved['weights']);
        $this->assertSame($resolver->fingerprint($globals), $resolver->fingerprint($resolved));
    }

    public function test_valid_strategy_parameters_override_globals(): void
    {
        config([
            TradingOsConfig::KEY_EVALUATION.'.rsi_period' => 14,
            TradingOsConfig::KEY_EVALUATION.'.sma_fast' => 20,
            TradingOsConfig::KEY_EVALUATION.'.sma_slow' => 50,
            TradingOsConfig::KEY_EVALUATION.'.atr_period' => 14,
            TradingOsConfig::KEY_EVALUATION.'.volume_sma_period' => 20,
        ]);

        $resolved = app(EvaluationParameterResolver::class)->resolve([
            'indicators' => [
                [
                    'key' => SupportedIndicators::RELATIVE_STRENGTH,
                    'parameters' => [
                        'lookback_days' => 21,
                        'benchmark' => 'niftybank',
                    ],
                ],
                [
                    'key' => SupportedIndicators::MOMENTUM_SCORE,
                    'parameters' => ['rsi_period' => 7],
                ],
                [
                    'key' => SupportedIndicators::TREND_SCORE,
                    'parameters' => ['sma_fast' => 10, 'sma_slow' => 30],
                ],
                [
                    'key' => SupportedIndicators::VOLUME_SCORE,
                    'parameters' => ['volume_sma_period' => 5],
                ],
                [
                    'key' => SupportedIndicators::RISK_SCORE,
                    'parameters' => ['atr_period' => 8],
                ],
            ],
        ]);

        $this->assertSame(7, $resolved['rsi_period']);
        $this->assertSame(10, $resolved['sma_fast']);
        $this->assertSame(30, $resolved['sma_slow']);
        $this->assertSame(8, $resolved['atr_period']);
        $this->assertSame(5, $resolved['volume_sma_period']);
        $this->assertSame(21, $resolved['lookback_days']);
        $this->assertTrue($resolved['use_lookback_days']);
        $this->assertSame('NIFTYBANK', $resolved['benchmark']);
    }

    public function test_invalid_strategy_parameters_fall_back_safely(): void
    {
        config([
            TradingOsConfig::KEY_EVALUATION.'.rsi_period' => 14,
            TradingOsConfig::KEY_EVALUATION.'.sma_fast' => 20,
            TradingOsConfig::KEY_EVALUATION.'.sma_slow' => 50,
            TradingOsConfig::KEY_EVALUATION.'.atr_period' => 14,
            TradingOsConfig::KEY_EVALUATION.'.volume_sma_period' => 20,
        ]);

        $resolved = app(EvaluationParameterResolver::class)->resolve([
            'scoring_model' => [
                [
                    'key' => SupportedIndicators::RELATIVE_STRENGTH,
                    'parameters' => [
                        'lookback_days' => 0,
                        'benchmark' => 'NOT_A_REAL_INDEX',
                    ],
                ],
                [
                    'key' => SupportedIndicators::MOMENTUM_SCORE,
                    'parameters' => ['rsi_period' => 'abc'],
                ],
                [
                    'key' => SupportedIndicators::TREND_SCORE,
                    'parameters' => ['sma_fast' => -3, 'sma_slow' => 12.5],
                ],
                [
                    'key' => SupportedIndicators::VOLUME_SCORE,
                    'parameters' => ['volume_sma_period' => null],
                ],
                [
                    'key' => SupportedIndicators::RISK_SCORE,
                    'parameters' => ['atr_period' => ''],
                ],
            ],
        ]);

        $this->assertSame(14, $resolved['rsi_period']);
        $this->assertSame(20, $resolved['sma_fast']);
        $this->assertSame(50, $resolved['sma_slow']);
        $this->assertSame(14, $resolved['atr_period']);
        $this->assertSame(20, $resolved['volume_sma_period']);
        $this->assertNull($resolved['lookback_days']);
        $this->assertFalse($resolved['use_lookback_days']);
        $this->assertNull($resolved['benchmark']);
    }

    public function test_does_not_treat_absent_lookback_as_catalogue_default(): void
    {
        $resolved = app(EvaluationParameterResolver::class)->resolve([]);
        $this->assertNull($resolved['lookback_days']);
        $this->assertFalse($resolved['use_lookback_days']);
    }

    public function test_weights_are_passed_through_unchanged_from_globals(): void
    {
        $weights = [
            'trend' => 0.30,
            'momentum' => 0.25,
            'relative_strength' => 0.25,
            'volume' => 0.10,
            'pattern_bonus' => 0.10,
        ];
        config([TradingOsConfig::KEY_EVALUATION.'.weights' => $weights]);

        $resolved = app(EvaluationParameterResolver::class)->resolve([
            'indicators' => [
                [
                    'key' => SupportedIndicators::MOMENTUM_SCORE,
                    'weight' => 99,
                    'parameters' => ['rsi_period' => 9],
                ],
            ],
        ]);

        $this->assertSame($weights, $resolved['weights']);
        $this->assertSame(9, $resolved['rsi_period']);
    }
}
