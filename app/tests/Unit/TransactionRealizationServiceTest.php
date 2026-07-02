<?php

namespace Tests\Unit;

use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRealizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionRealizationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifo_realized_pl_and_squared_off_fees_match_example(): void
    {
        $user = User::query()->create([
            'name' => 'FIFO User',
            'email' => 'fifo-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'FIFO',
            'exchange' => 'NSE',
            'name' => 'FIFO Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 2,
            'price' => 100,
            'fees' => 2.00,
            'transaction_date' => '2026-01-01',
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 6,
            'price' => 120,
            'fees' => 7.20,
            'transaction_date' => '2026-01-02',
        ]);
        $sell = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 5,
            'price' => 150,
            'fees' => 7.50,
            'transaction_date' => '2026-01-03',
        ]);

        app(TransactionRealizationService::class)->recalculateForProfileStock($profile, $stock);

        $sell->refresh();
        $this->assertSame('190.0000', $sell->realized_pl);
        $this->assertSame('13.1000', $sell->squared_off_fees);
    }

    public function test_partial_lot_fees_are_split_across_multiple_sells(): void
    {
        $user = User::query()->create([
            'name' => 'FIFO Split',
            'email' => 'fifo-split-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'SPLIT',
            'exchange' => 'NSE',
            'name' => 'Split Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 6,
            'price' => 120,
            'fees' => 6.00,
            'transaction_date' => '2026-01-01',
        ]);
        $sellOne = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 3,
            'price' => 150,
            'fees' => 1.00,
            'transaction_date' => '2026-01-02',
        ]);
        $sellTwo = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 3,
            'price' => 160,
            'fees' => 2.00,
            'transaction_date' => '2026-01-03',
        ]);

        app(TransactionRealizationService::class)->recalculateForProfileStock($profile, $stock);

        $sellOne->refresh();
        $sellTwo->refresh();

        $this->assertSame('4.0000', $sellOne->squared_off_fees);
        $this->assertSame('5.0000', $sellTwo->squared_off_fees);
    }
}
