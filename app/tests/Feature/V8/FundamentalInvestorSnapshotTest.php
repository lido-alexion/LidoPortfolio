<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\Fundamentals\FundamentalDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundamentalInvestorSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_fundamentals_api_returns_summary_and_resolves_market_price(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'TCS', 'exchange' => 'NSE', 'name' => 'TCS']);
        $this->defaultPortfolioFor($user);

        app(FundamentalDataService::class)->storeFacts($stock, [[
            'statement_type' => 'income_statement',
            'cadence' => 'quarterly',
            'fact_key' => 'revenue',
            'period_end' => '2025-03-31',
            'value' => 1000,
            'availability_date' => '2025-06-01',
        ]]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2025-06-15',
            'close_price' => 400,
            'adjusted_close_price' => 400,
            'data_source' => 'test',
        ]);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?as_of=2025-06-20")
            ->assertOk()
            ->assertJsonPath('data.market_price', 400)
            ->assertJsonPath('data.summary.0.id', 'market_cap')
            ->assertJsonPath('data.summary.0.provenance.derived', true)
            ->assertJsonPath('data.summary.0.provenance.latest_period_end', '2025-03-31')
            ->assertJsonFragment(['id' => 'free_cash_flow'])
            ->assertJsonCount(12, 'data.summary');
    }

    public function test_fundamentals_history_groups_periods(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'INFY', 'exchange' => 'NSE', 'name' => 'Infosys']);
        $this->defaultPortfolioFor($user);
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
                'period_end' => '2024-03-31',
                'value' => 100,
                'availability_date' => '2024-06-01',
            ],
        ]);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals/history?cadence=quarterly")
            ->assertOk()
            ->assertJsonCount(2, 'data.periods')
            ->assertJsonPath('data.rows.0.cells.0.yoy_pct', 20);
    }

    public function test_metric_history_returns_ttm_points_at_quarterly_pit_dates(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'HDFC', 'exchange' => 'NSE', 'name' => 'HDFC Bank']);
        $this->defaultPortfolioFor($user);
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

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals/metrics/roe/history?as_of=2025-06-20")
            ->assertOk()
            ->assertJsonPath('data.metric', 'roe')
            ->assertJsonPath('data.points.0.value', 10);
    }

    public function test_valuation_metric_history_defaults_to_monthly_adjusted_price_series(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $stock = Stock::query()->create(['symbol' => 'PECO', 'exchange' => 'NSE', 'name' => 'PE Co']);
        $this->defaultPortfolioFor($user);
        $service = app(FundamentalDataService::class);
        foreach (['2024-06-30', '2024-09-30', '2024-12-31', '2025-03-31'] as $period) {
            $service->storeFacts($stock, [[
                'statement_type' => 'income_statement',
                'cadence' => 'quarterly',
                'fact_key' => 'eps',
                'period_end' => $period,
                'value' => 1.0,
                'availability_date' => '2025-06-01',
            ]]);
        }
        foreach (['2025-06-10', '2025-06-20', '2025-07-15'] as $date) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'close_price' => 100,
                'adjusted_close_price' => 100,
                'data_source' => 'test',
            ]);
        }

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals/metrics/pe/history?as_of=2025-07-20&range=1y")
            ->assertOk()
            ->assertJsonPath('data.metric', 'pe')
            ->assertJsonPath('data.frequency', 'monthly')
            ->assertJsonPath('data.price_basis', 'adjusted_close')
            ->assertJsonCount(2, 'data.points');
    }
}
