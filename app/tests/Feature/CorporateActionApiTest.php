<?php

namespace Tests\Feature;

use App\Models\CorporateAction;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorporateActionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_and_apply_split_via_api(): void
    {
        $user = User::query()->create([
            'name' => 'API Split',
            'email' => 'api-split-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'APIS',
            'exchange' => 'NSE',
            'name' => 'API Split Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 4,
            'price' => 200,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);

        $preview = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->postJson('/api/corporate-actions/preview', [
                'stock_id' => $stock->id,
                'action_type' => 'split',
                'ratio_from' => 1,
                'ratio_to' => 2,
                'ex_date' => '2026-03-01',
            ]);

        $preview->assertOk();
        $preview->assertJsonPath('data.action_type', 'split');
        $this->assertCount(1, $preview->json('data.adjustments'));

        $apply = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->postJson('/api/corporate-actions', [
                'stock_id' => $stock->id,
                'action_type' => 'split',
                'ratio_from' => 1,
                'ratio_to' => 2,
                'ex_date' => '2026-03-01',
            ]);

        $apply->assertCreated();
        $apply->assertJsonPath('data.holding.quantity', '8.0000');
        $this->assertDatabaseHas('portfolio_corporate_actions', [
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'action_type' => 'split',
        ]);
    }

    public function test_list_corporate_actions_for_stock(): void
    {
        $user = User::query()->create([
            'name' => 'API List',
            'email' => 'api-list-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'LIST',
            'exchange' => 'NSE',
            'name' => 'List Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        CorporateAction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'action_type' => 'bonus',
            'ratio_from' => 1,
            'ratio_to' => 1,
            'ex_date' => '2026-03-01',
            'applied_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson('/api/corporate-actions?stock_id='.$stock->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
