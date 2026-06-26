<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockMetric;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ProfileSettingsService;
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
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'SL'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Stoploss Persist Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 5,
            'avg_buy_price' => 100,
            'invested_amount' => 500,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
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

        $alert = Alert::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('10% below highest close 120.00', $alert->message);
        $this->assertStringContainsString('Trailing stop: 108.00', $alert->message);

        $this->assertDatabaseHas('portfolio_alerts', [
            'profile_id' => $profile->id,
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
        $profileA = $this->defaultPortfolioFor($userA);

        $userB = User::query()->create([
            'name' => 'User B',
            'email' => 'user-b-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profileB = $this->defaultPortfolioFor($userB);

        $stock = Stock::query()->create([
            'symbol' => 'SL'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Shared Stoploss Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        foreach ([[$userA, $profileA], [$userB, $profileB]] as [$user, $profile]) {
            Holding::query()->create([
                'profile_id' => $profile->id,
                'stock_id' => $stock->id,
                'quantity' => 5,
                'avg_buy_price' => 100,
                'invested_amount' => 500,
                'realized_profit' => 0,
                'updated_at' => now(),
            ]);

            Transaction::query()->create([
                'profile_id' => $profile->id,
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
        $this->assertDatabaseHas('portfolio_alerts', ['profile_id' => $profileA->id, 'stock_id' => $stock->id]);
        $this->assertDatabaseHas('portfolio_alerts', ['profile_id' => $profileB->id, 'stock_id' => $stock->id]);
    }

    public function test_stoploss_does_not_trigger_when_above_since_buy_trailing_despite_pre_buy_peak(): void
    {
        $user = User::query()->create([
            'name' => 'Since Buy User',
            'email' => 'since-buy-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'SL'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Pre Buy Peak Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $buyDate = now()->subYear()->toDateString();

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 10,
            'avg_buy_price' => 700,
            'invested_amount' => 7000,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 700,
            'fees' => 0,
            'transaction_date' => $buyDate,
        ]);

        StockMetric::query()->create([
            'stock_id' => $stock->id,
            'highest_close' => 1000,
            'latest_close' => 811.65,
            'stoploss_percent' => 15,
            'trailing_stop_price' => 850,
            'tracking_active' => true,
            'updated_at' => now(),
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->subYears(2)->toDateString(),
            'open_price' => 990,
            'high_price' => 1010,
            'low_price' => 980,
            'close_price' => 1000,
            'volume' => 1000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->subMonths(11)->toDateString(),
            'open_price' => 850,
            'high_price' => 860,
            'low_price' => 840,
            'close_price' => 858.58,
            'volume' => 1000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'open_price' => 808,
            'high_price' => 815,
            'low_price' => 805,
            'close_price' => 811.65,
            'volume' => 1000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $service = app(StoplossService::class);
        $service->updateMetricsForStock($stock);

        $this->assertNull(
            Alert::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->first()
        );
    }

    public function test_stoploss_does_not_trigger_after_full_exit_and_rebuy_despite_prior_position_peak(): void
    {
        $user = User::query()->create([
            'name' => 'Rebuy User',
            'email' => 'rebuy-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        app(ProfileSettingsService::class)->set($profile, 'default_stoploss_percent', '15');

        $stock = Stock::query()->create([
            'symbol' => 'CAMS',
            'exchange' => 'NSE',
            'name' => 'Computer Age Management Services Limited',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $firstBuyDate = '2024-01-05';
        $peakDate = '2024-02-01';
        $exitDate = '2024-03-01';
        $rebuyDate = '2025-06-01';
        $latestDate = '2025-06-10';

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 900,
            'fees' => 0,
            'transaction_date' => $firstBuyDate,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 1,
            'price' => 1000,
            'fees' => 0,
            'transaction_date' => $exitDate,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 500,
            'fees' => 0,
            'transaction_date' => $rebuyDate,
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 1,
            'avg_buy_price' => 500,
            'invested_amount' => 500,
            'realized_profit' => 100,
            'updated_at' => now(),
        ]);

        StockMetric::query()->create([
            'stock_id' => $stock->id,
            'highest_close' => 1000,
            'latest_close' => 600,
            'stoploss_percent' => 15,
            'trailing_stop_price' => 850,
            'tracking_active' => true,
            'updated_at' => now(),
        ]);

        foreach ([
            [$firstBuyDate, 900],
            [$peakDate, 1000],
            [$exitDate, 1000],
            ['2025-05-15', 500],
            [$rebuyDate, 500],
            [$latestDate, 600],
        ] as [$date, $close]) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'open_price' => $close,
                'high_price' => $close,
                'low_price' => $close,
                'close_price' => $close,
                'volume' => 1000,
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        $service = app(StoplossService::class);
        $metric = $service->updateMetricsForStock($stock);

        $this->assertNull(
            Alert::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->first()
        );
        $this->assertSame(600.0, (float) $metric->highest_close);
        $this->assertSame(510.0, (float) $metric->trailing_stop_price);

        $presentation = app(\App\Services\HoldingPresentationService::class);
        $holding = Holding::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->first();
        $holding->setRelation('stock', $stock);
        $summary = $presentation->enrichHolding($profile, $holding)['stoploss_summary'];

        $this->assertSame($rebuyDate, $summary['first_buy_date']);
        $this->assertSame(600.0, (float) $summary['highest_close_since_buy']);
        $this->assertSame(510.0, (float) $summary['trailing_stop_price']);
        $this->assertSame(600.0, (float) $summary['latest_close']);
    }
}




