<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    protected function makeUser(bool $isAdmin = false): User
    {
        $user = User::query()->create([
            'name' => 'Stock User',
            'email' => 'stock-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        if ($isAdmin) {
            $user->is_admin = true;
            $user->save();
        }

        return $user->fresh();
    }

    public function test_non_admin_cannot_create_stock_via_store_endpoint(): void
    {
        $user = $this->makeUser(false);
        $stock = Stock::query()->create([
            'symbol' => 'ZZZTEST',
            'exchange' => 'NSE',
            'name' => 'Test',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/stocks', [
                'symbol' => 'NEWTEST',
                'exchange' => 'NSE',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->putJson("/api/stocks/{$stock->id}", ['name' => 'Changed'])
            ->assertForbidden();
    }

    public function test_admin_can_update_stock_metadata(): void
    {
        $admin = $this->makeUser(true);
        $stock = Stock::query()->create([
            'symbol' => 'ADMINTST',
            'exchange' => 'NSE',
            'name' => 'Before',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/stocks/{$stock->id}", ['name' => 'After'])
            ->assertOk()
            ->assertJsonPath('data.name', 'After');
    }
}
