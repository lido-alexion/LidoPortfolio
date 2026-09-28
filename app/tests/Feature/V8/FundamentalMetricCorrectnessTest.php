<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Services\Fundamentals\FundamentalDataService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundamentalMetricCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_ttm_requires_four_consecutive_quarters(): void
    {
        $stock = Stock::query()->create(['symbol' => 'TCS', 'exchange' => 'NSE', 'name' => 'TCS']);
        $service = app(FundamentalDataService::class);

        foreach (['2024-09-30', '2024-12-31', '2025-03-31'] as $period) {
            $service->storeFacts($stock, [[
                'statement_type' => 'income_statement',
                'cadence' => 'quarterly',
                'fact_key' => 'revenue',
                'period_end' => $period,
                'value' => 100,
                'availability_date' => '2025-06-01',
            ]]);
        }

        $metric = $service->metric($stock, 'revenue', 'ttm', Carbon::parse('2025-06-30'));
        $this->assertNull($metric['value']);

        $service->storeFacts($stock, [[
            'statement_type' => 'income_statement',
            'cadence' => 'quarterly',
            'fact_key' => 'revenue',
            'period_end' => '2024-06-30',
            'value' => 100,
            'availability_date' => '2025-06-01',
        ]]);

        $metric = $service->metric($stock, 'revenue', 'ttm', Carbon::parse('2025-06-30'));
        $this->assertSame(400.0, (float) $metric['value']);
    }

    public function test_quarterly_growth_compares_year_ago_period(): void
    {
        $stock = Stock::query()->create(['symbol' => 'INFY', 'exchange' => 'NSE', 'name' => 'Infosys']);
        $service = app(FundamentalDataService::class);

        $service->storeFacts($stock, [
            [
                'statement_type' => 'income_statement',
                'cadence' => 'quarterly',
                'fact_key' => 'revenue',
                'period_end' => '2025-03-31',
                'value' => 120,
                'availability_date' => '2025-06-01',
            ],
            [
                'statement_type' => 'income_statement',
                'cadence' => 'quarterly',
                'fact_key' => 'revenue',
                'period_end' => '2024-12-31',
                'value' => 100,
                'availability_date' => '2025-03-01',
            ],
            [
                'statement_type' => 'income_statement',
                'cadence' => 'quarterly',
                'fact_key' => 'revenue',
                'period_end' => '2024-03-31',
                'value' => 100,
                'availability_date' => '2024-06-01',
            ],
        ]);

        $growth = $service->growthMetric($stock, 'revenue', 'quarterly', Carbon::parse('2025-06-30'));
        $this->assertSame(20.0, (float) $growth['value']);
    }

    public function test_free_cash_flow_requires_both_operating_cash_flow_and_capex(): void
    {
        $stock = Stock::query()->create(['symbol' => 'HDFC', 'exchange' => 'NSE', 'name' => 'HDFC']);
        $service = app(FundamentalDataService::class);

        $service->storeFacts($stock, [[
            'statement_type' => 'cash_flow',
            'cadence' => 'quarterly',
            'fact_key' => 'operating_cash_flow',
            'period_end' => '2025-03-31',
            'value' => 500,
            'availability_date' => '2025-06-01',
        ]]);

        $missingCapex = $service->metric($stock, 'free_cash_flow', 'quarterly', Carbon::parse('2025-06-30'));
        $this->assertNull($missingCapex['value']);

        $service->storeFacts($stock, [[
            'statement_type' => 'cash_flow',
            'cadence' => 'quarterly',
            'fact_key' => 'capital_expenditure',
            'period_end' => '2025-03-31',
            'value' => -50,
            'availability_date' => '2025-06-01',
        ]]);

        $withCapex = $service->metric($stock, 'free_cash_flow', 'quarterly', Carbon::parse('2025-06-30'));
        $this->assertSame(450.0, (float) $withCapex['value']);
    }

    public function test_pe_unavailable_for_non_positive_eps(): void
    {
        $stock = Stock::query()->create(['symbol' => 'LOSS', 'exchange' => 'NSE', 'name' => 'Loss Co']);
        $service = app(FundamentalDataService::class);
        $service->storeFacts($stock, [[
            'statement_type' => 'income_statement',
            'cadence' => 'quarterly',
            'fact_key' => 'eps',
            'period_end' => '2025-03-31',
            'value' => -2,
            'availability_date' => '2025-06-01',
        ]]);

        $metric = $service->metric($stock, 'pe', 'quarterly', Carbon::parse('2025-06-30'), 100.0);
        $this->assertNull($metric['value']);
    }

    public function test_ttm_uses_latest_four_when_five_consecutive_quarters_exist(): void
    {
        $stock = Stock::query()->create(['symbol' => 'FIVE', 'exchange' => 'NSE', 'name' => 'Five Quarters']);
        $service = app(FundamentalDataService::class);
        foreach (['2024-03-31', '2024-06-30', '2024-09-30', '2024-12-31', '2025-03-31'] as $index => $period) {
            $service->storeFacts($stock, [[
                'statement_type' => 'income_statement',
                'cadence' => 'quarterly',
                'fact_key' => 'revenue',
                'period_end' => $period,
                'value' => $index + 1,
                'availability_date' => '2025-06-01',
            ]]);
        }

        $metric = $service->metric($stock, 'revenue', 'ttm', Carbon::parse('2025-06-30'));
        $this->assertSame(14.0, (float) $metric['value']);
    }

    public function test_ttm_rejects_a_non_consecutive_period_chain(): void
    {
        $stock = Stock::query()->create(['symbol' => 'GAP', 'exchange' => 'NSE', 'name' => 'Gap Co']);
        $service = app(FundamentalDataService::class);
        foreach (['2024-03-31', '2024-09-30', '2024-12-31', '2025-03-31'] as $period) {
            $service->storeFacts($stock, [[
                'statement_type' => 'income_statement',
                'cadence' => 'quarterly',
                'fact_key' => 'revenue',
                'period_end' => $period,
                'value' => 100,
                'availability_date' => '2025-06-01',
            ]]);
        }

        $metric = $service->metric($stock, 'revenue', 'ttm', Carbon::parse('2025-06-30'));
        $this->assertNull($metric['value']);
    }

    public function test_market_cap_and_margin_are_unavailable_without_required_inputs(): void
    {
        $stock = Stock::query()->create(['symbol' => 'VALUE', 'exchange' => 'NSE', 'name' => 'Value Co']);
        $service = app(FundamentalDataService::class);
        $service->storeFacts($stock, [[
            'statement_type' => 'balance_sheet',
            'cadence' => 'quarterly',
            'fact_key' => 'shares_outstanding',
            'period_end' => '2025-03-31',
            'value' => 1000000,
            'availability_date' => '2025-06-01',
        ]]);

        $this->assertSame(100000000.0, (float) $service->metric($stock, 'market_cap', 'ttm', Carbon::parse('2025-06-30'), 100)['value']);
        $this->assertNull($service->metric($stock, 'net_margin', 'ttm', Carbon::parse('2025-06-30'))['value']);
    }
}
