<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5PortfolioAttributionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_attribution_reconciles_strategy_unmanaged_charges_and_residual_without_causal_claims(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, 1000, 'Opening', $user, '2025-12-31');
        $stock = Stock::query()->create(['symbol' => 'ATTR', 'exchange' => 'NSE', 'name' => 'Attribution']);
        $sell = Transaction::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'sell',
            'quantity' => 1, 'price' => 100, 'fees' => 2, 'realized_pl' => 20,
            'squared_off_fees' => 3, 'transaction_date' => '2026-01-02', 'owner_key' => 'strategy:7',
        ]);

        $response = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/analysis/attribution?from=2026-01-01&to=2026-01-03')
            ->assertOk()
            ->assertJsonPath('data.method', 'reconciliation_based_not_causal')
            ->assertJsonPath('data.reconciles', true);

        $dimensions = collect($response->json('data.dimensions'));
        $this->assertNotNull($dimensions->firstWhere('owner_key', 'unmanaged'));
        $strategy = $dimensions->firstWhere('owner_key', 'strategy:7');
        $this->assertSame(20, $strategy['realized_gross']);
        $this->assertSame(3, $strategy['allocated_charges']);
        $this->assertSame(17, $strategy['net_realized_contribution']);
        $this->assertContains('owner_level_historical_oversell:'.$sell->id, $response->json('data.limitations'));
    }

    public function test_attribution_reconciles_owner_level_wavg_unrealized_change(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, 1000, 'Opening', $user, '2025-12-31');
        $stock = Stock::query()->create(['symbol' => 'UNREAL', 'exchange' => 'NSE', 'name' => 'Unrealized Attribution']);
        Transaction::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 1, 'price' => 100, 'fees' => 0, 'transaction_date' => '2025-12-31',
            'owner_key' => 'strategy:7',
        ]);
        foreach ([['2026-01-01', 100], ['2026-01-03', 120]] as [$date, $close]) {
            StockPrice::query()->create([
                'stock_id' => $stock->id, 'price_date' => $date, 'close_price' => $close,
                'adjusted_close_price' => $close, 'data_source' => 'test', 'created_at' => now(),
            ]);
        }

        $response = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/analysis/attribution?from=2026-01-01&to=2026-01-03')
            ->assertOk()
            ->assertJsonPath('data.economic_result', 20)
            ->assertJsonPath('data.explained_total', 20)
            ->assertJsonPath('data.reconciliation_residual', 0)
            ->assertJsonPath('data.completeness', 'complete');

        $strategy = collect($response->json('data.dimensions'))->firstWhere('owner_key', 'strategy:7');
        $this->assertSame(20, $strategy['unrealized_change']);
        $this->assertSame(20, $strategy['explained_contribution']);
    }
}
