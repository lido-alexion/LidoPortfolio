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
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/stocks/search?q=inf');

        $response->assertOk();
        $response->assertJsonFragment(['symbol' => 'INFY']);
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
}
