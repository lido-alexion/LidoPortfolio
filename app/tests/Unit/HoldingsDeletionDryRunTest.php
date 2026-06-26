<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HoldingsCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class HoldingsDeletionDryRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_passes_when_deleting_only_buy_with_no_sells(): void
    {
        $user = User::query()->create([
            'name' => 'Dry User',
            'email' => 'dry-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'DRY1',
            'exchange' => 'NSE',
            'name' => 'Dry Run Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $buy = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-10',
        ]);

        $service = app(HoldingsCalculationService::class);
        $service->assertReplayValidAfterDeleting($profile, $buy);

        $this->assertTrue(true);
    }

    public function test_dry_run_fails_when_deleting_buy_leaves_orphan_sell(): void
    {
        $user = User::query()->create([
            'name' => 'Dry User 2',
            'email' => 'dry2-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'DRY2',
            'exchange' => 'NSE',
            'name' => 'Dry Run Test 2',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $buy = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-10',
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 4,
            'price' => 120,
            'fees' => 0,
            'transaction_date' => '2026-02-01',
        ]);

        $service = app(HoldingsCalculationService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->assertReplayValidAfterDeleting($profile, $buy);
    }

    public function test_dry_run_passes_when_deleting_one_of_multiple_buys(): void
    {
        $user = User::query()->create([
            'name' => 'Dry User 3',
            'email' => 'dry3-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'DRY3',
            'exchange' => 'NSE',
            'name' => 'Dry Run Test 3',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $firstBuy = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-10',
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 110,
            'fees' => 0,
            'transaction_date' => '2026-01-20',
        ]);

        app(HoldingsCalculationService::class)->assertReplayValidAfterDeleting($profile, $firstBuy);

        $this->assertTrue(true);
    }
}


