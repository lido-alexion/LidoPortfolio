<?php

namespace Tests\Feature;

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
}
