<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\PortfolioSnapshot;
use App\Models\ProfileSetting;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortfolioDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_user_can_soft_delete_portfolio_and_related_data(): void
    {
        $user = User::query()->create([
            'name' => 'Delete User',
            'email' => 'del-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $defaultProfile = $this->defaultPortfolioFor($user);
        $otherProfile = $this->createPortfolioProfile($user, 'Other', false);
        $stock = Stock::query()->create([
            'symbol' => 'DEL1',
            'exchange' => 'NSE',
            'name' => 'Delete Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Transaction::query()->create([
            'profile_id' => $otherProfile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);
        Holding::query()->create([
            'profile_id' => $otherProfile->id,
            'stock_id' => $stock->id,
            'quantity' => 5,
            'avg_buy_price' => 100,
            'invested_amount' => 500,
            'total_fees' => 0,
        ]);
        PortfolioSnapshot::query()->create([
            'profile_id' => $otherProfile->id,
            'snapshot_date' => '2026-01-01',
            'portfolio_value' => 500,
            'invested_value' => 500,
            'created_at' => now(),
        ]);
        Alert::query()->create([
            'profile_id' => $otherProfile->id,
            'stock_id' => $stock->id,
            'alert_type' => 'stoploss_triggered',
            'message' => 'Test alert',
            'is_sent' => false,
            'created_at' => now(),
        ]);
        ProfileSetting::setValue($otherProfile->id, 'notifications_enabled', '1');

        $this->actingAs($user)
            ->deleteJson("/api/portfolios/{$otherProfile->id}")
            ->assertOk()
            ->assertJson(['message' => 'Portfolio deleted']);

        $this->assertSoftDeleted('portfolio_profiles', ['id' => $otherProfile->id]);
        $this->assertDatabaseMissing('portfolio_transactions', ['profile_id' => $otherProfile->id]);
        $this->assertDatabaseMissing('portfolio_holdings', ['profile_id' => $otherProfile->id]);
        $this->assertDatabaseMissing('portfolio_portfolio_snapshots', ['profile_id' => $otherProfile->id]);
        $this->assertDatabaseMissing('portfolio_alerts', ['profile_id' => $otherProfile->id]);
        $this->assertDatabaseMissing('portfolio_profile_settings', ['profile_id' => $otherProfile->id]);

        $list = $this->actingAs($user)->getJson('/api/portfolios')->json('data');
        $this->assertCount(1, $list);
        $this->assertSame($defaultProfile->id, $list[0]['id']);

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->getJson('/api/transactions?scope=all')
            ->assertNotFound();
    }

    public function test_cannot_delete_only_portfolio(): void
    {
        $user = User::query()->create([
            'name' => 'Solo User',
            'email' => 'solo-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)
            ->deleteJson("/api/portfolios/{$profile->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['portfolio']);

        $this->assertDatabaseHas('portfolio_profiles', [
            'id' => $profile->id,
            'deleted_at' => null,
        ]);
    }

    public function test_cannot_delete_default_portfolio(): void
    {
        $user = User::query()->create([
            'name' => 'Default Delete User',
            'email' => 'defdel-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $defaultProfile = $this->defaultPortfolioFor($user);
        $this->createPortfolioProfile($user, 'Survivor', false);

        $this->actingAs($user)
            ->deleteJson("/api/portfolios/{$defaultProfile->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['portfolio']);

        $this->assertDatabaseHas('portfolio_profiles', [
            'id' => $defaultProfile->id,
            'deleted_at' => null,
            'is_default' => true,
        ]);
    }

    public function test_cannot_delete_portfolio_active_in_requesting_tab(): void
    {
        $user = User::query()->create([
            'name' => 'Active Tab User',
            'email' => 'act-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);
        $otherProfile = $this->createPortfolioProfile($user, 'To delete', false);

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->deleteJson("/api/portfolios/{$otherProfile->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['portfolio']);

        $this->assertDatabaseHas('portfolio_profiles', [
            'id' => $otherProfile->id,
            'deleted_at' => null,
        ]);
    }

    public function test_creating_same_name_after_delete_is_a_new_portfolio(): void
    {
        $user = User::query()->create([
            'name' => 'Rename User',
            'email' => 'rnm-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);
        $otherProfile = $this->createPortfolioProfile($user, 'Retirement', false);
        $deletedId = $otherProfile->id;

        $this->actingAs($user)
            ->deleteJson("/api/portfolios/{$deletedId}")
            ->assertOk();

        $createResponse = $this->actingAs($user)
            ->postJson('/api/portfolios', ['name' => 'Retirement'])
            ->assertCreated();

        $newId = $createResponse->json('data.id');
        $this->assertNotSame($deletedId, $newId);
        $this->assertSoftDeleted('portfolio_profiles', ['id' => $deletedId]);
        $this->assertDatabaseHas('portfolio_profiles', [
            'id' => $newId,
            'name' => 'Retirement',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseMissing('portfolio_transactions', ['profile_id' => $newId]);
    }
}
