<?php

namespace Tests\Feature;

use App\Models\AnalysisPreference;
use App\Models\Benchmark;
use App\Models\PortfolioSnapshot;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\CashManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5PortfolioPerformanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_uses_cash_in_wealth_neutralizes_external_flows_and_compares_tri(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $cash = app(CashManagementService::class);
        $cash->deposit($profile, 1000, 'Opening capital', $user, '2025-12-31');
        $cash->withdraw($profile, 200, 'Capital returned', $user, '2026-01-02');

        foreach (['2026-01-01', '2026-01-02', '2026-01-03'] as $date) {
            PortfolioSnapshot::query()->create([
                'profile_id' => $profile->id,
                'snapshot_date' => $date,
                'portfolio_value' => 0,
                'invested_value' => 0,
                'created_at' => now(),
            ]);
        }

        $benchmark = Stock::query()->create([
            'symbol' => 'NIFTY50-TRI', 'exchange' => 'NSE', 'name' => 'NIFTY 50 TRI', 'is_benchmark' => true,
        ]);
        foreach ([['2026-01-01', 100], ['2026-01-03', 110]] as [$date, $close]) {
            StockPrice::query()->create([
                'stock_id' => $benchmark->id,
                'price_date' => $date,
                'close_price' => $close,
                'adjusted_close_price' => $close,
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/analysis/performance?from=2026-01-01&to=2026-01-03')
            ->assertOk()
            ->assertJsonPath('data.twr_percent', 0)
            ->assertJsonPath('data.xirr_percent', 0)
            ->assertJsonPath('data.benchmark.stable_key', 'nifty-50-tri')
            ->assertJsonPath('data.benchmark.return_percent', 10)
            ->assertJsonPath('data.excess_return_percent', -10)
            ->assertJsonPath('data.evidence.value_source', 'historical_holdings_plus_cash');
    }

    public function test_invalid_period_is_rejected(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/analysis/performance?from=2026-01-03&to=2026-01-01')
            ->assertUnprocessable();
    }

    public function test_selected_comparison_benchmarks_are_returned_without_changing_primary_excess_return(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $cash = app(CashManagementService::class);
        $cash->deposit($profile, 1000, 'Opening capital', $user, '2025-12-31');
        $comparisonStock = Stock::query()->create([
            'symbol' => 'COMPARE-TRI', 'exchange' => 'NSE', 'name' => 'Comparison TRI', 'is_benchmark' => true,
        ]);
        foreach ([['2026-01-01', 200], ['2026-01-03', 210]] as [$date, $close]) {
            StockPrice::query()->create([
                'stock_id' => $comparisonStock->id, 'price_date' => $date,
                'close_price' => $close, 'adjusted_close_price' => $close,
                'data_source' => 'test', 'created_at' => now(),
            ]);
        }
        $comparison = Benchmark::query()->create([
            'stable_key' => 'comparison-tri', 'name' => 'Comparison TRI', 'symbol' => 'COMPARE-TRI',
            'return_type' => 'total_return', 'currency' => 'INR', 'provider' => 'test',
            'provenance' => ['source' => 'test'], 'is_active' => true, 'is_default' => false,
        ]);
        AnalysisPreference::query()->create([
            'user_id' => $user->id, 'profile_id' => $profile->id, 'scope_key' => 'portfolio:'.$profile->id,
            'comparison_benchmark_ids' => [$comparison->id],
            'include_in_account_performance' => true, 'include_in_account_tax' => true,
        ]);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/analysis/performance?from=2026-01-01&to=2026-01-03')
            ->assertOk()
            ->assertJsonPath('data.comparison_benchmarks.0.stable_key', 'comparison-tri')
            ->assertJsonPath('data.comparison_benchmarks.0.return_percent', 5)
            ->assertJsonPath('data.benchmark.stable_key', 'nifty-50-tri');
    }
}
