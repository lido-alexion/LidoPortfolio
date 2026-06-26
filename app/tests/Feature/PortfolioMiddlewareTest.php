<?php

namespace Tests\Feature;

use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortfolioMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_default_portfolio_used_when_profile_header_missing(): void
    {
        $user = User::query()->create([
            'name' => 'Middleware User',
            'email' => 'mw-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $defaultProfile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'MW1',
            'exchange' => 'NSE',
            'name' => 'MW Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Transaction::query()->create([
            'profile_id' => $defaultProfile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);

        $response = $this->actingAs($user)->getJson('/api/transactions?scope=all');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_profile_header_scopes_transaction_list(): void
    {
        $user = User::query()->create([
            'name' => 'Header User',
            'email' => 'hdr-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $defaultProfile = $this->defaultPortfolioFor($user);
        $otherProfile = $this->createPortfolioProfile($user, 'Other', false);
        $stock = Stock::query()->create([
            'symbol' => 'HDR1',
            'exchange' => 'NSE',
            'name' => 'Header Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Transaction::query()->create([
            'profile_id' => $defaultProfile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 10,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);
        Transaction::query()->create([
            'profile_id' => $otherProfile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 2,
            'price' => 20,
            'fees' => 0,
            'transaction_date' => '2026-01-02',
        ]);
        Transaction::query()->create([
            'profile_id' => $otherProfile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 3,
            'price' => 30,
            'fees' => 0,
            'transaction_date' => '2026-01-03',
        ]);

        $defaultResponse = $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $defaultProfile->id)
            ->getJson('/api/transactions?scope=all');
        $defaultResponse->assertOk();
        $this->assertCount(1, $defaultResponse->json('data'));

        $otherResponse = $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->getJson('/api/transactions?scope=all');
        $otherResponse->assertOk();
        $this->assertCount(2, $otherResponse->json('data'));
    }

    public function test_foreign_profile_id_returns_not_found(): void
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);

        $other = User::query()->create([
            'name' => 'Other',
            'email' => 'other-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $otherProfile = $this->defaultPortfolioFor($other);

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->getJson('/api/transactions?scope=all')
            ->assertNotFound();
    }

    public function test_different_profile_headers_return_different_transaction_counts(): void
    {
        $user = User::query()->create([
            'name' => 'Parallel User',
            'email' => 'par-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profileA = $this->defaultPortfolioFor($user);
        $profileB = $this->createPortfolioProfile($user, 'B', false);
        $stock = Stock::query()->create([
            'symbol' => 'PAR1',
            'exchange' => 'NSE',
            'name' => 'Parallel Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        foreach ([$profileA, $profileB] as $index => $profile) {
            Transaction::query()->create([
                'profile_id' => $profile->id,
                'stock_id' => $stock->id,
                'type' => 'buy',
                'quantity' => $index + 1,
                'price' => 100,
                'fees' => 0,
                'transaction_date' => '2026-02-0'.($index + 1),
            ]);
        }
        Transaction::query()->create([
            'profile_id' => $profileB->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 50,
            'fees' => 0,
            'transaction_date' => '2026-02-10',
        ]);

        $countA = count($this->actingAs($user)->withHeader('X-Profile-Id', (string) $profileA->id)->getJson('/api/transactions?scope=all')->json('data'));
        $countB = count($this->actingAs($user)->withHeader('X-Profile-Id', (string) $profileB->id)->getJson('/api/transactions?scope=all')->json('data'));

        $this->assertSame(1, $countA);
        $this->assertSame(2, $countB);
    }
}
