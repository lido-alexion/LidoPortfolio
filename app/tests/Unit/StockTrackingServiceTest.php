<?php

namespace Tests\Unit;

use App\Models\Alert;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\User;
use App\Services\StockTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_holding_makes_stock_portfolio_tracked(): void
    {
        $user = User::query()->create([
            'name' => 'Track User',
            'email' => 'trk-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'TRK',
            'exchange' => 'NSE',
            'name' => 'Tracked',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Holding::query()->create([
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'quantity' => 5,
            'avg_buy_price' => 10,
            'invested_amount' => 50,
            'updated_at' => now(),
        ]);

        $service = new StockTrackingService;
        $this->assertTrue($service->isPortfolioTracked($stock, $user));
        $this->assertFalse($service->isExploratory($stock, $user));
    }

    public function test_stoploss_alert_marks_stock_portfolio_tracked_without_user_id_on_alerts(): void
    {
        $user = User::query()->create([
            'name' => 'Alert User',
            'email' => 'alt-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'ALT',
            'exchange' => 'NSE',
            'name' => 'Alert Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Alert::query()->create([
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'alert_type' => 'stoploss_triggered',
            'message' => 'Test alert',
            'is_sent' => false,
            'created_at' => now(),
        ]);

        $service = new StockTrackingService;
        $this->assertTrue($service->isPortfolioTracked($stock, $user));
    }

    public function test_stock_without_holdings_is_exploratory(): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'EXP',
            'exchange' => 'NSE',
            'name' => 'Explorer',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $service = new StockTrackingService;
        $this->assertFalse($service->isPortfolioTracked($stock));
        $this->assertTrue($service->isExploratory($stock));
    }
}
