<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_search_requires_minimum_characters(): void
    {
        $user = User::query()->create([
            'name' => 'Search User',
            'email' => 'search-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $this->actingAs($user);

        $this->getJson('/api/stocks/search?q=I')->assertUnprocessable();
    }

    public function test_stock_search_returns_local_master_matches(): void
    {
        $user = User::query()->create([
            'name' => 'Search User 2',
            'email' => 'search2-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'NSE',
            'name' => 'Infosys Ltd',
            'is_dual_listed' => true,
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/stocks/search?q=inf');

        $response->assertOk();
        $response->assertJsonFragment(['symbol' => 'INFY', 'exchange_label' => 'NSE+']);
    }

    public function test_stock_validate_check_only_uses_local_master_without_persist_side_effects(): void
    {
        $user = User::query()->create([
            'name' => 'Validate User',
            'email' => 'validate-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        Stock::query()->create([
            'symbol' => 'TCS',
            'exchange' => 'NSE',
            'name' => 'Tata Consultancy',
            'is_active' => true,
            'is_benchmark' => false,
            'last_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/stocks/validate', [
            'symbol' => 'TCS',
            'exchange' => 'NSE',
            'check_only' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('valid', true);
        $response->assertJsonPath('source', 'local');
        $response->assertJsonFragment(['symbol' => 'TCS']);
    }

    public function test_stock_validate_bse_toggle_resolves_dual_listed_to_nse_row(): void
    {
        $user = User::query()->create([
            'name' => 'Dual User',
            'email' => 'dual-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $nse = Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'NSE',
            'name' => 'Infosys Ltd',
            'isin' => 'INE009A01021',
            'is_dual_listed' => true,
            'is_active' => true,
            'is_benchmark' => false,
            'last_verified_at' => now(),
        ]);

        Stock::query()->create([
            'symbol' => '500209',
            'exchange' => 'BSE',
            'name' => 'Infosys Ltd BSE',
            'isin' => 'INE009A01021',
            'is_active' => false,
            'is_benchmark' => false,
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/stocks/validate', [
            'symbol' => 'INFY',
            'exchange' => 'BSE',
            'check_only' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('valid', true);
        $response->assertJsonPath('data.id', $nse->id);
        $response->assertJsonPath('data.exchange', 'NSE');
        $response->assertJsonFragment(['exchange_label' => 'NSE+']);
    }

    public function test_stock_search_bse_scope_includes_bse_only_symbols(): void
    {
        $user = User::query()->create([
            'name' => 'BSE Search User',
            'email' => 'bse-search-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        Stock::query()->create([
            'symbol' => 'BSEONLY',
            'exchange' => 'BSE',
            'name' => 'BSE Only Ltd',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/stocks/search?q=bse&exchange=BSE');

        $response->assertOk();
        $response->assertJsonFragment(['symbol' => 'BSEONLY', 'exchange' => 'BSE']);
    }
}
