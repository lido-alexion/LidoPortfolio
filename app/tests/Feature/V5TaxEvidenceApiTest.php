<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5TaxEvidenceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dividends_are_account_level_and_deduplicated(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $payload = ['received_on' => '2026-01-01', 'amount' => 125.50, 'source_reference' => 'stmt-1'];

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/tax/dividends', $payload)
            ->assertCreated()
            ->assertJsonPath('data.source', 'manual');
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/tax/dividends', $payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('portfolio_dividends', 1);
    }

    public function test_opening_tax_lots_are_isolated_by_active_portfolio(): void
    {
        $user = User::factory()->create();
        $first = $this->defaultPortfolioFor($user);
        $second = $user->portfolios()->create(['name' => 'Second']);
        $stock = Stock::query()->create(['symbol' => 'LOT', 'exchange' => 'NSE', 'name' => 'Lot Test']);

        $this->actingAs($user)->withProfileHeader($user, $first)
            ->postJson('/api/tax/opening-lots', [
                'stock_id' => $stock->id,
                'acquired_on' => '2020-01-01',
                'quantity' => 10,
                'cost_basis' => 1000,
                'reason' => 'Pre-StoX broker history',
            ])->assertCreated();

        $this->actingAs($user)->withProfileHeader($user, $second)
            ->getJson('/api/tax/opening-lots')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_confirmed_loss_requires_reason_and_remains_separate_from_calculated_loss(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/tax/losses', [
                'financial_year' => '2025-26',
                'loss_type' => 'short_term',
                'amount' => 500,
                'status' => 'confirmed',
            ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/tax/losses', [
                'financial_year' => '2025-26',
                'loss_type' => 'short_term',
                'amount' => 500,
                'status' => 'calculated',
            ])->assertCreated()->assertJsonPath('data.status', 'calculated');
    }
}
