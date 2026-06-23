<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockMetric;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StoplossService;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoplossServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stoploss_service_can_be_constructed(): void
    {
        $service = app(StoplossService::class);
        $this->assertInstanceOf(StoplossService::class, $service);
    }

    public function test_stoploss_persists_alert_when_threshold_is_breached(): void
    {
        $user = User::query()->create([
            'name' => 'Stoploss User',
            'email' => 'stoploss-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'SL'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Stoploss Persist Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Holding::query()->create([
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'quantity' => 5,
            'avg_buy_price' => 100,
            'invested_amount' => 500,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        Transaction::query()->create([
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->subMonths(2)->toDateString(),
        ]);

        StockMetric::query()->create([
            'stock_id' => $stock->id,
            'highest_close' => 120,
            'latest_close' => 120,
            'stoploss_percent' => 10,
            'trailing_stop_price' => 108,
            'tracking_active' => true,
            'updated_at' => now(),
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->subMonth()->toDateString(),
            'open_price' => 118,
            'high_price' => 121,
            'low_price' => 117,
            'close_price' => 120,
            'volume' => 1000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'open_price' => 104,
            'high_price' => 105,
            'low_price' => 99,
            'close_price' => 100,
            'volume' => 1000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $service = app(StoplossService::class);
        $service->updateMetricsForStock($stock);

        $alert = Alert::query()->where('user_id', $user->id)->where('stock_id', $stock->id)->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('10% below highest close 120.00', $alert->message);
        $this->assertStringContainsString('Trailing stop: 108.00', $alert->message);

        $this->assertDatabaseHas('portfolio_alerts', [
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'alert_type' => 'stoploss_triggered',
            'is_sent' => 0,
        ]);
    }

    public function test_stoploss_creates_per_user_alerts_when_multiple_users_hold_stock(): void
    {
        $userA = User::query()->create([
            'name' => 'User A',
            'email' => 'user-a-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $userB = User::query()->create([
            'name' => 'User B',
            'email' => 'user-b-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'SL'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Shared Stoploss Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        foreach ([$userA, $userB] as $user) {
            Holding::query()->create([
                'user_id' => $user->id,
                'stock_id' => $stock->id,
                'quantity' => 5,
                'avg_buy_price' => 100,
                'invested_amount' => 500,
                'realized_profit' => 0,
                'updated_at' => now(),
            ]);

            Transaction::query()->create([
                'user_id' => $user->id,
                'stock_id' => $stock->id,
                'type' => 'buy',
                'quantity' => 5,
                'price' => 100,
                'fees' => 0,
                'transaction_date' => now()->subMonths(2)->toDateString(),
            ]);
        }

        StockMetric::query()->create([
            'stock_id' => $stock->id,
            'highest_close' => 120,
            'latest_close' => 120,
            'stoploss_percent' => 10,
            'trailing_stop_price' => 108,
            'tracking_active' => true,
            'updated_at' => now(),
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->subMonth()->toDateString(),
            'open_price' => 118,
            'high_price' => 121,
            'low_price' => 117,
            'close_price' => 120,
            'volume' => 1000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'open_price' => 104,
            'high_price' => 105,
            'low_price' => 99,
            'close_price' => 100,
            'volume' => 1000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $service = app(StoplossService::class);
        $service->updateMetricsForStock($stock);

        $this->assertSame(2, Alert::query()->where('stock_id', $stock->id)->count());
        $this->assertDatabaseHas('portfolio_alerts', ['user_id' => $userA->id, 'stock_id' => $stock->id]);
        $this->assertDatabaseHas('portfolio_alerts', ['user_id' => $userB->id, 'stock_id' => $stock->id]);
    }
}
