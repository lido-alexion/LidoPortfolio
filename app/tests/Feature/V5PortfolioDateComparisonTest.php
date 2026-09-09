<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\TransactionWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5PortfolioDateComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_compare_classifies_union_holdings_and_separates_external_flows(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'CMP', 'name' => 'Compare Ltd', 'exchange' => 'NSE']);
        foreach (['2026-08-01' => 100, '2026-08-10' => 120] as $date => $price) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'open_price' => $price,
                'high_price' => $price,
                'low_price' => $price,
                'close_price' => $price,
                'volume' => 1000,
                'data_source' => 'test',
            ]);
        }

        $cash = app(CashManagementService::class);
        $cash->deposit($profile, 1000, 'Opening cash', $user, '2026-08-01');
        app(TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy', 'quantity' => 2, 'price' => 100, 'fees' => 0,
            'transaction_date' => '2026-08-05',
        ], user: $user, applyCash: true);
        $cash->deposit($profile, 500, 'Additional capital', $user, '2026-08-07');
        $cash->adjust($profile, 25, 'Broker correction', $user, '2026-08-08');

        $response = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/portfolio/compare?date_a=2026-08-01&date_b=2026-08-10');

        $response->assertOk()
            ->assertJsonPath('data.holdings.0.classification', 'entered')
            ->assertJsonPath('data.holdings.0.quantity_a', 0)
            ->assertJsonPath('data.holdings.0.quantity_b', 2)
            ->assertJsonPath('data.external_flows.deposits', 500)
            ->assertJsonPath('data.external_flows.net', 500)
            ->assertJsonPath('data.external_flows.adjustments', 25)
            ->assertJsonPath('data.deltas.label', 'Change in portfolio value (not investment return)');
    }

    public function test_compare_requires_ordered_non_future_dates(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/portfolio/compare?date_a=2026-08-10&date_b=2026-08-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_a', 'date_b']);
    }
}
