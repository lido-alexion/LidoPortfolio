<?php

namespace Tests\Unit\Recommendation;

use App\Engines\Recommendation\Allocation\ReturnQualityCapitalAllocator;
use App\Engines\Recommendation\RecommendationGenerationPipeline;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategyVersion;
use App\Services\Ranking\CapitalFillOrderService;
use App\Services\Ranking\ReturnQualityRankingService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class V3PipelineCapitalOutcomesTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_unfunded_keeps_open_and_preserves_target(): void
    {
        $pipeline = app(RecommendationGenerationPipeline::class);
        $drafts = [[
            'key' => 'a',
            'action' => TradingRecommendation::ACTION_OPEN_POSITION,
            'plan' => ['suggested_investment_amount' => 18000.0],
            'position_size' => 18000.0,
            'suggested_alloc' => 6.0,
            'target_alloc' => 6.0,
            'is_held' => false,
            'current_alloc' => 0.0,
            'ranking_order_source' => 'od23',
        ]];
        $out = $this->invoke($pipeline, 'applyCapitalOutcomes', [
            $drafts,
            ['a' => [
                'allocated_amount' => 0.0,
                'quantity' => 0,
                'target_amount' => 18000.0,
                'unfunded_amount' => 18000.0,
                'funding_status' => 'UNFUNDED',
                'atomic_reservation' => 20000.0,
            ]],
        ]);

        $this->assertSame(TradingRecommendation::ACTION_OPEN_POSITION, $out[0]['action']);
        $this->assertSame(TradingRecommendation::ALLOCATION_UNFUNDED, $out[0]['capital_allocation']['status']);
        $this->assertEquals(18000.0, $out[0]['capital_allocation']['target_amount']);
        $this->assertEquals(18000.0, $out[0]['plan']['target_investment_amount']);
        $this->assertNotEquals(TradingRecommendation::ACTION_WATCH, $out[0]['action']);
    }

    public function test_partially_funded_preserves_target_and_stays_actionable(): void
    {
        $pipeline = app(RecommendationGenerationPipeline::class);
        $drafts = [[
            'key' => 'a',
            'action' => TradingRecommendation::ACTION_OPEN_POSITION,
            'plan' => ['suggested_investment_amount' => 18000.0],
            'position_size' => 18000.0,
            'suggested_alloc' => 6.0,
            'target_alloc' => 6.0,
            'is_held' => false,
            'current_alloc' => 0.0,
            'ranking_order_source' => 'return_quality',
        ]];
        $out = $this->invoke($pipeline, 'applyCapitalOutcomes', [
            $drafts,
            ['a' => [
                'allocated_amount' => 10000.0,
                'quantity' => 100,
                'target_amount' => 18000.0,
                'unfunded_amount' => 8000.0,
                'funding_status' => 'PARTIALLY_FUNDED',
                'atomic_reservation' => 20000.0,
            ]],
        ]);

        $this->assertSame(TradingRecommendation::ACTION_OPEN_POSITION, $out[0]['action']);
        $this->assertSame(TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED, $out[0]['capital_allocation']['status']);
        $this->assertEquals(18000.0, $out[0]['capital_allocation']['target_amount']);
        $this->assertEquals(10000.0, $out[0]['capital_allocation']['allocated_amount']);
        $this->assertEquals(8000.0, $out[0]['capital_allocation']['unfunded_amount']);
    }

    public function test_fully_funded_stays_funded(): void
    {
        $pipeline = app(RecommendationGenerationPipeline::class);
        $drafts = [[
            'key' => 'a',
            'action' => TradingRecommendation::ACTION_OPEN_POSITION,
            'plan' => ['suggested_investment_amount' => 5000.0],
            'position_size' => 5000.0,
            'suggested_alloc' => 5.0,
            'target_alloc' => 5.0,
            'is_held' => false,
            'current_alloc' => 0.0,
            'ranking_order_source' => 'od23',
        ]];
        $out = $this->invoke($pipeline, 'applyCapitalOutcomes', [
            $drafts,
            ['a' => [
                'allocated_amount' => 5000.0,
                'quantity' => 50,
                'target_amount' => 5000.0,
                'unfunded_amount' => 0.0,
                'funding_status' => 'FULLY_FUNDED',
                'atomic_reservation' => 10000.0,
            ]],
        ]);

        $this->assertSame(TradingRecommendation::ALLOCATION_FUNDED, $out[0]['capital_allocation']['status']);
        $this->assertSame(TradingRecommendation::ACTION_OPEN_POSITION, $out[0]['action']);
    }

    public function test_return_quality_order_used_when_computable(): void
    {
        $ranking = Mockery::mock(ReturnQualityRankingService::class)->makePartial();
        $ranking->shouldReceive('rankForStrategyVersion')->andReturn([
            'computable' => true,
            'bands' => [
                [
                    'eligible' => true,
                    'merged_band_keys' => ['[70,80)'],
                    'return_quality' => 10.0,
                ],
                [
                    'eligible' => true,
                    'merged_band_keys' => ['[40,50)'],
                    'return_quality' => 80.0,
                ],
            ],
        ]);
        $ranking->shouldReceive('bandKeyForScore')->andReturnUsing(function (float $score): string {
            return (new ReturnQualityRankingService)->bandKeyForScore($score);
        });
        $this->app->instance(ReturnQualityRankingService::class, $ranking);

        $pipeline = app(RecommendationGenerationPipeline::class);
        $version = new TradingStrategyVersion;
        $version->id = 9;
        $drafts = [
            $this->buyDraft('low_rq', 75.0, 'AAA', 100000),
            $this->buyDraft('high_rq', 45.0, 'ZZZ', 50000),
        ];
        $out = $this->invoke($pipeline, 'rankDrafts', [$drafts, ['strategy_version' => $version]]);

        $this->assertSame(['high_rq', 'low_rq'], array_column($out, 'key'));
        $this->assertSame('return_quality', $out[0]['ranking_order_source']);
        $this->assertTrue($out[0]['ranking_computable']);
    }

    public function test_od23_order_used_when_ranking_not_computable(): void
    {
        $ranking = Mockery::mock(ReturnQualityRankingService::class);
        $ranking->shouldReceive('rankForStrategyVersion')->andReturn([
            'computable' => false,
            'bands' => [],
            'reason' => 'none',
        ]);
        $this->app->instance(ReturnQualityRankingService::class, $ranking);
        $this->app->instance(CapitalFillOrderService::class, new CapitalFillOrderService);

        $pipeline = app(RecommendationGenerationPipeline::class);
        $version = new TradingStrategyVersion;
        $version->id = 9;
        $drafts = [
            $this->buyDraft('small', 95.0, 'AAA', 100000),
            $this->buyDraft('large', 70.0, 'ZZZ', 120000),
        ];
        $out = $this->invoke($pipeline, 'rankDrafts', [$drafts, ['strategy_version' => $version]]);

        $this->assertSame(['large', 'small'], array_column($out, 'key'));
        $this->assertSame('od23', $out[0]['ranking_order_source']);
        $this->assertFalse($out[0]['ranking_computable']);
    }

    public function test_allocate_capital_passes_drafts_in_supplied_order(): void
    {
        $captured = [];
        $allocator = Mockery::mock(ReturnQualityCapitalAllocator::class);
        $allocator->shouldReceive('allocate')->once()->andReturnUsing(function (float $cash, array $buyDrafts) use (&$captured) {
            $captured = array_column($buyDrafts, 'key');
            $this->assertEquals(12345.0, $cash);

            return [];
        });
        $this->app->instance(ReturnQualityCapitalAllocator::class, $allocator);

        $pipeline = $this->app->make(RecommendationGenerationPipeline::class, ['allocator' => $allocator]);
        $drafts = [
            $this->buyDraft('first', 50.0, 'BBB', 1000),
            $this->buyDraft('second', 99.0, 'AAA', 1000),
        ];
        $this->invoke($pipeline, 'allocateCapital', [
            $drafts,
            ['portfolio_value' => 100000, 'max_pct' => 10, 'max_new_positions' => 5, 'available_cash' => 12345.0],
        ]);

        $this->assertSame(['first', 'second'], $captured);
    }

    /**
     * @param  list<mixed>  $args
     */
    private function invoke(object $target, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($target, $method);

        return $ref->invokeArgs($target, $args);
    }

    /**
     * @return array<string, mixed>
     */
    private function buyDraft(string $key, float $score, string $symbol, float $target): array
    {
        return [
            'key' => $key,
            'action' => TradingRecommendation::ACTION_OPEN_POSITION,
            'score' => $score,
            'symbol' => $symbol,
            'plan' => ['suggested_investment_amount' => $target],
            'position_size' => $target,
            'confidence' => 0.8,
            'priority' => 80,
            'reference_price' => 100,
        ];
    }
}
