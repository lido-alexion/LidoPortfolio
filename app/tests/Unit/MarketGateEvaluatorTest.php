<?php

namespace Tests\Unit;

use App\Engines\Recommendation\MarketGateEvaluator;
use Tests\TestCase;

class MarketGateEvaluatorTest extends TestCase
{
    public function test_gates_disabled_passes_through_market_analysis(): void
    {
        $market = $this->sampleMarket([
            'new_entry_allowed' => true,
            'allocation_multiplier' => 1.05,
            'sentiment' => ['score' => 72, 'label' => 'Bullish'],
            'market_phase' => 'Bull',
        ]);

        $decision = MarketGateEvaluator::evaluate($market, ['enabled' => false]);

        $this->assertTrue($decision['allows_entry']);
        $this->assertEqualsWithDelta(1.05, $decision['allocation_multiplier'], 0.0001);
        $this->assertFalse($decision['strategy_gates']['enabled']);
        $this->assertSame([], $decision['strategy_gates']['checks']);
        $this->assertFalse($decision['strategy_gates']['blocked']);
    }

    public function test_gates_enabled_and_passing_allow_entry(): void
    {
        $market = $this->sampleMarket([
            'sentiment' => ['score' => 70, 'label' => 'Bullish'],
            'market_phase' => 'Bull',
            'risk' => ['label' => 'Medium', 'raw_risk' => 45],
        ]);

        $decision = MarketGateEvaluator::evaluate($market, [
            'enabled' => true,
            'min_sentiment' => 45,
            'allowed_phases' => ['Bull', 'Strong Bull'],
            'max_risk_raw' => 70,
        ]);

        $this->assertTrue($decision['allows_entry']);
        $this->assertTrue($decision['strategy_gates']['enabled']);
        $this->assertCount(3, $decision['strategy_gates']['checks']);
        $this->assertTrue(collect($decision['strategy_gates']['checks'])->every(fn (array $c) => $c['passed']));
        $this->assertSame([], $decision['strategy_gates']['block_reasons']);
    }

    public function test_min_sentiment_failure_blocks_entry(): void
    {
        $decision = MarketGateEvaluator::evaluate(
            $this->sampleMarket(['sentiment' => ['score' => 40, 'label' => 'Bearish']]),
            ['enabled' => true, 'min_sentiment' => 45],
        );

        $this->assertFalse($decision['allows_entry']);
        $this->assertContains('Sentiment below strategy minimum', $decision['strategy_gates']['block_reasons']);
        $this->assertFalse($decision['strategy_gates']['checks'][0]['passed']);
    }

    public function test_allowed_phases_failure_blocks_entry(): void
    {
        $decision = MarketGateEvaluator::evaluate(
            $this->sampleMarket(['market_phase' => 'Bear']),
            [
                'enabled' => true,
                'allowed_phases' => ['Bull', 'Consolidation'],
            ],
        );

        $this->assertFalse($decision['allows_entry']);
        $this->assertContains('Market phase not in allowed phases', $decision['strategy_gates']['block_reasons']);
    }

    public function test_max_risk_raw_failure_blocks_entry_and_reduces_multiplier(): void
    {
        $decision = MarketGateEvaluator::evaluate(
            $this->sampleMarket([
                'allocation_multiplier' => 1.1,
                'risk' => ['label' => 'High', 'raw_risk' => 82],
            ]),
            ['enabled' => true, 'max_risk_raw' => 70],
        );

        $this->assertFalse($decision['allows_entry']);
        $this->assertEqualsWithDelta(0.5, $decision['allocation_multiplier'], 0.0001);
        $this->assertContains('Market risk exceeds strategy maximum', $decision['strategy_gates']['block_reasons']);
    }

    public function test_market_analysis_entry_block_without_strategy_gates(): void
    {
        $decision = MarketGateEvaluator::evaluate(
            $this->sampleMarket([
                'new_entry_allowed' => false,
                'market_phase' => 'Bear',
            ]),
            ['enabled' => false],
        );

        $this->assertFalse($decision['allows_entry']);
        $this->assertContains('Market phase "Bear" disallows new entries', $decision['strategy_gates']['block_reasons']);
    }

    public function test_multiple_gate_failures_collect_all_reasons(): void
    {
        $decision = MarketGateEvaluator::evaluate(
            $this->sampleMarket([
                'sentiment' => ['score' => 30, 'label' => 'Bearish'],
                'market_phase' => 'Correction',
                'risk' => ['label' => 'High', 'raw_risk' => 90],
            ]),
            [
                'enabled' => true,
                'min_sentiment' => 45,
                'allowed_phases' => ['Bull'],
                'max_risk_raw' => 70,
            ],
        );

        $this->assertFalse($decision['allows_entry']);
        $this->assertContains('Sentiment below strategy minimum', $decision['strategy_gates']['block_reasons']);
        $this->assertContains('Market phase not in allowed phases', $decision['strategy_gates']['block_reasons']);
        $this->assertContains('Market risk exceeds strategy maximum', $decision['strategy_gates']['block_reasons']);
        $this->assertCount(3, $decision['strategy_gates']['checks']);
    }

    public function test_deterministic_for_identical_inputs(): void
    {
        $market = $this->sampleMarket();
        $gates = [
            'enabled' => true,
            'min_sentiment' => 50,
            'allowed_phases' => ['Bull', 'Consolidation'],
            'max_risk_raw' => 75,
        ];

        $a = MarketGateEvaluator::evaluate($market, $gates);
        $b = MarketGateEvaluator::evaluate($market, $gates);

        $this->assertSame($a, $b);
    }

    public function test_strategy_without_market_gates_section_behaves_like_disabled(): void
    {
        $decision = MarketGateEvaluator::evaluate($this->sampleMarket(), []);

        $this->assertTrue($decision['allows_entry']);
        $this->assertFalse($decision['strategy_gates']['enabled']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function sampleMarket(array $overrides = []): array
    {
        return array_replace_recursive([
            'available' => true,
            'market_phase' => 'Bull',
            'new_entry_allowed' => true,
            'allocation_multiplier' => 1.0,
            'sentiment' => ['score' => 65, 'label' => 'Bullish'],
            'risk' => ['label' => 'Medium', 'raw_risk' => 50],
        ], $overrides);
    }
}
