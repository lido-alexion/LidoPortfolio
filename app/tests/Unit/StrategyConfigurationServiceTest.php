<?php

namespace Tests\Unit;

use App\Engines\Strategy\SupportedIndicators;
use App\Services\StrategyConfigurationService;
use Tests\TestCase;

class StrategyConfigurationServiceTest extends TestCase
{
    public function test_score_applies_catalogue_weights(): void
    {
        $svc = app(StrategyConfigurationService::class);
        $config = $svc->normalizeConfig([]);
        foreach ($config['indicators'] as &$ind) {
            if (in_array($ind['key'], [SupportedIndicators::RELATIVE_STRENGTH, SupportedIndicators::TREND_SCORE], true)) {
                $ind['enabled'] = true;
                $ind['weight'] = 50;
                $ind['minimum'] = null;
                $ind['maximum'] = null;
            } else {
                $ind['enabled'] = false;
            }
        }
        unset($ind);

        $result = $svc->score([
            'relative_strength' => 100,
            'trend_score' => 50,
        ], $config);

        $this->assertEqualsWithDelta(75.0, $result['overall_score'], 0.01);
        $this->assertCount(2, $result['breakdown']);
    }

    public function test_score_gates_below_minimum(): void
    {
        $svc = app(StrategyConfigurationService::class);
        $config = $svc->normalizeConfig([
            'indicators' => [
                [
                    'key' => SupportedIndicators::RELATIVE_STRENGTH,
                    'enabled' => true,
                    'weight' => 100,
                    'minimum' => 80,
                    'maximum' => null,
                ],
            ],
        ]);

        // Disable other indicators so only RS contributes.
        foreach ($config['indicators'] as &$ind) {
            if ($ind['key'] !== SupportedIndicators::RELATIVE_STRENGTH) {
                $ind['enabled'] = false;
            }
        }
        unset($ind);

        $result = $svc->score(['relative_strength' => 70], $config);
        $this->assertSame(0.0, $result['overall_score']);
        $this->assertTrue($result['breakdown'][0]['gated']);
    }

    public function test_normalize_rejects_unknown_keys_by_dropping_them(): void
    {
        $svc = app(StrategyConfigurationService::class);
        $config = $svc->normalizeConfig([
            'indicators' => [
                ['key' => 'custom_magic', 'enabled' => true, 'weight' => 50],
                ['key' => SupportedIndicators::VOLUME_SCORE, 'enabled' => true, 'weight' => 10],
            ],
        ]);

        $keys = array_column($config['indicators'], 'key');
        $this->assertNotContains('custom_magic', $keys);
        $this->assertContains(SupportedIndicators::VOLUME_SCORE, $keys);
        $this->assertCount(count(SupportedIndicators::keys()), $keys);
    }

    public function test_legacy_aliases_score(): void
    {
        $svc = app(StrategyConfigurationService::class);
        $config = $svc->normalizeConfig([]);
        foreach ($config['indicators'] as &$ind) {
            $ind['enabled'] = $ind['key'] === SupportedIndicators::BREAKOUT_SCORE;
            if ($ind['enabled']) {
                $ind['weight'] = 100;
                $ind['minimum'] = null;
                $ind['maximum'] = null;
            }
        }
        unset($ind);

        $result = $svc->score(['pattern_bonus' => 80], $config);
        $this->assertEqualsWithDelta(80.0, $result['overall_score'], 0.01);
    }

    public function test_factory_defaults_weights_sum_to_100(): void
    {
        $svc = app(StrategyConfigurationService::class);
        $config = $svc->defaultConfig();
        $this->assertEqualsWithDelta(100.0, $svc->enabledWeightTotal($config), 0.01);
        $svc->validateConfig($svc->normalizeConfig($config));
        $this->assertSame('Momentum Strategy', \App\Engines\Strategy\FactoryMomentumStrategy::NAME);
    }

    public function test_validate_rejects_weight_sum_not_100(): void
    {
        $svc = app(StrategyConfigurationService::class);
        $config = $svc->normalizeConfig([]);
        foreach ($config['indicators'] as &$ind) {
            if ($ind['key'] === SupportedIndicators::RELATIVE_STRENGTH) {
                $ind['weight'] = 99;
            }
        }
        unset($ind);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $svc->validateConfig($config);
    }
}
