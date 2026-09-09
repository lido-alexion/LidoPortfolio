<?php

namespace Tests\Feature;

use App\Models\Stock;
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
        Transaction::query()->create([
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
        $this->assertContains('unrealized_and_cash_effects_are_reconciliation_residual', $response->json('data.limitations'));
    }
}
