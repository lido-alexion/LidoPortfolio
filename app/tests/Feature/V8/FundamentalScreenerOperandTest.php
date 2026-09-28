<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\Screener\ScreenerCatalog;
use App\Services\Screener\ScreenerEvaluationService;
use App\Services\Screener\TechnicalIndicatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundamentalScreenerOperandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ScreenerCatalog::clearIndicatorCache();
    }

    public function test_catalog_includes_fundamental_indicators(): void
    {
        $ids = ScreenerCatalog::indicatorIds();
        $this->assertContains('fund_roe_ttm', $ids);
    }

    public function test_fundamental_condition_fails_when_metric_missing(): void
    {
        $stock = Stock::query()->create(['symbol' => 'TCS', 'exchange' => 'NSE', 'name' => 'TCS']);
        $eval = app(ScreenerEvaluationService::class);
        $bars = [
            ['open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1000, 'date' => '2025-06-15'],
        ];
        $definition = [
            'root' => [
                'type' => 'condition',
                'operator' => 'gt',
                'left' => ['indicator' => 'fund_roe_ttm'],
                'right' => ['type' => 'constant', 'value' => 5],
            ],
        ];

        $result = $eval->evaluateStock($definition, $bars, [], $stock);
        $this->assertFalse($result['matched']);
        $this->assertFalse($result['skipped']);
    }

    public function test_fundamental_condition_matches_when_roe_available(): void
    {
        $stock = Stock::query()->create(['symbol' => 'ROE', 'exchange' => 'NSE', 'name' => 'Roe Co']);
        $service = app(FundamentalDataService::class);
        foreach (['2024-06-30', '2024-09-30', '2024-12-31', '2025-03-31'] as $period) {
            $service->storeFacts($stock, [
                [
                    'statement_type' => 'income_statement',
                    'cadence' => 'quarterly',
                    'fact_key' => 'net_income',
                    'period_end' => $period,
                    'value' => 2.5,
                    'availability_date' => '2025-06-01',
                ],
            ]);
        }
        $service->storeFacts($stock, [[
            'statement_type' => 'balance_sheet',
            'cadence' => 'quarterly',
            'fact_key' => 'equity',
            'period_end' => '2025-03-31',
            'value' => 100,
            'availability_date' => '2025-06-01',
        ]]);

        $eval = app(ScreenerEvaluationService::class);
        $bars = [
            ['open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1000, 'date' => '2025-06-15'],
        ];
        $definition = [
            'root' => [
                'type' => 'condition',
                'operator' => 'gt',
                'left' => ['indicator' => 'fund_roe_ttm'],
                'right' => ['type' => 'constant', 'value' => 5],
            ],
        ];

        $result = $eval->evaluateStock($definition, $bars, [], $stock);
        $this->assertTrue($result['matched']);
    }

    public function test_fundamental_operand_respects_point_in_time_across_dates(): void
    {
        $stock = Stock::query()->create(['symbol' => 'PIT', 'exchange' => 'NSE', 'name' => 'Pit Co']);
        $service = app(FundamentalDataService::class);
        foreach (['2024-06-30', '2024-09-30', '2024-12-31', '2025-03-31'] as $period) {
            $service->storeFacts($stock, [[
                'statement_type' => 'income_statement',
                'cadence' => 'quarterly',
                'fact_key' => 'net_income',
                'period_end' => $period,
                'value' => 2.5,
                'availability_date' => '2025-06-01',
            ]]);
        }
        $service->storeFacts($stock, [[
            'statement_type' => 'balance_sheet',
            'cadence' => 'quarterly',
            'fact_key' => 'equity',
            'period_end' => '2025-03-31',
            'value' => 100,
            'availability_date' => '2025-06-01',
        ]]);

        $eval = app(ScreenerEvaluationService::class);
        $bars = [
            ['open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1000, 'date' => '2025-05-15'],
            ['open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1000, 'date' => '2025-06-15'],
        ];
        $definition = [
            'root' => [
                'type' => 'condition',
                'operator' => 'gt',
                'left' => ['indicator' => 'fund_roe_ttm'],
                'right' => ['type' => 'constant', 'value' => 5],
            ],
        ];

        $results = $eval->evaluateAcrossDates($definition, $bars, ['2025-05-15', '2025-06-15'], [], $stock);
        $this->assertFalse($results['2025-05-15']['matched']);
        $this->assertTrue($results['2025-06-15']['matched']);
    }
}
