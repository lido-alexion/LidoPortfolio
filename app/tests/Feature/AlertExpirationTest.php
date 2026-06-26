<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AlertExpirationService;
use App\Services\HoldingsCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlertExpirationTest extends TestCase
{
    use RefreshDatabase;

    protected function createUserWithHolding(): array
    {
        [$user, $profile, $stock] = $this->createUserAndStock();

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 5,
            'avg_buy_price' => 100,
            'invested_amount' => 500,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        return [$user, $profile, $stock];
    }

    protected function createAlert(User $user, Stock $stock, array $overrides = []): Alert
    {
        $profile = $this->defaultPortfolioFor($user);

        return Alert::query()->create(array_merge([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'alert_type' => 'stoploss_triggered',
            'message' => 'Test alert',
            'is_sent' => false,
            'created_at' => now(),
        ], $overrides));
    }

    protected function createUserAndStock(): array
    {
        $user = User::query()->create([
            'name' => 'Alert Expire User',
            'email' => 'alert-expire-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'ALRT',
            'exchange' => 'NSE',
            'name' => 'Alert Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        return [$user, $profile, $stock];
    }

    public function test_expire_all_endpoint_clears_active_alerts_for_held_stocks(): void
    {
        [$user, $profile, $stock] = $this->createUserWithHolding();

        $this->createAlert($user, $stock);

        $response = $this->actingAs($user)->postJson('/api/alerts/expire-all');

        $response->assertOk()->assertJsonPath('expired_count', 1);
        $this->assertNotNull(Alert::query()->first()->expired_at);
    }

    public function test_acknowledge_endpoint_expires_single_alert(): void
    {
        [$user, $profile, $stock] = $this->createUserWithHolding();

        $alert = $this->createAlert($user, $stock);

        $response = $this->actingAs($user)->postJson("/api/alerts/{$alert->id}/acknowledge");

        $response->assertOk();
        $this->assertSame('acknowledged', $alert->fresh()->expiration_reason);
    }

    public function test_expire_older_than_100_hours(): void
    {
        [$user, $profile, $stock] = $this->createUserWithHolding();

        $this->createAlert($user, $stock, [
            'message' => 'Old alert',
            'created_at' => now()->subHours(101),
        ]);

        $count = app(AlertExpirationService::class)->expireOlderThanHours(100);

        $this->assertSame(1, $count);
    }

    public function test_expire_on_new_trading_day_data(): void
    {
        [$user, $profile, $stock] = $this->createUserWithHolding();

        $this->createAlert($user, $stock, [
            'message' => 'Prior day alert',
            'created_at' => Carbon::parse('2026-05-28 10:00:00'),
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2026-05-29',
            'open_price' => 100,
            'high_price' => 100,
            'low_price' => 100,
            'close_price' => 100,
            'volume' => 0,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);

        $count = app(AlertExpirationService::class)->expireBeforeTradingDay(
            Carbon::parse('2026-05-29')->startOfDay(),
        );

        $this->assertSame(1, $count);
    }

    public function test_full_sell_expires_alerts_for_stock(): void
    {
        $user = User::query()->create([
            'name' => 'Sell Alert User',
            'email' => 'sell-alert-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'SELL',
            'exchange' => 'NSE',
            'name' => 'Sell Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-05-01',
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 10,
            'price' => 90,
            'fees' => 0,
            'transaction_date' => '2026-05-10',
        ]);

        $this->createAlert($user, $stock, [
            'message' => 'Should expire on full sell',
        ]);

        app(HoldingsCalculationService::class)->recalculateForProfile($profile);

        $this->assertNotNull(Alert::query()->first()->expired_at);
        $this->assertSame('holding_closed', Alert::query()->first()->expiration_reason);
    }

    public function test_acknowledge_rejects_another_users_alert(): void
    {
        [$user, $profile, $stock] = $this->createUserWithHolding();

        $otherUser = User::query()->create([
            'name' => 'Other User',
            'email' => 'other-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $otherProfile = $this->defaultPortfolioFor($otherUser);

        $alert = $this->createAlert($otherUser, $stock);

        $response = $this->actingAs($user)->postJson("/api/alerts/{$alert->id}/acknowledge");

        $response->assertNotFound();
        $this->assertNull($alert->fresh()->expired_at);
    }

    public function test_full_sell_does_not_expire_other_users_alerts(): void
    {
        $seller = User::query()->create([
            'name' => 'Seller',
            'email' => 'seller-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $sellerProfile = $this->defaultPortfolioFor($seller);

        $holder = User::query()->create([
            'name' => 'Holder',
            'email' => 'holder-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $holderProfile = $this->defaultPortfolioFor($holder);

        $stock = Stock::query()->create([
            'symbol' => 'MULTI',
            'exchange' => 'NSE',
            'name' => 'Multi User Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $sellerProfile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-05-01',
        ]);

        Transaction::query()->create([
            'profile_id' => $sellerProfile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 10,
            'price' => 90,
            'fees' => 0,
            'transaction_date' => '2026-05-10',
        ]);

        Holding::query()->create([
            'profile_id' => $holderProfile->id,
            'stock_id' => $stock->id,
            'quantity' => 5,
            'avg_buy_price' => 100,
            'invested_amount' => 500,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        $sellerAlert = $this->createAlert($seller, $stock, ['message' => 'Seller alert']);
        $holderAlert = $this->createAlert($holder, $stock, ['message' => 'Holder alert']);

        app(HoldingsCalculationService::class)->recalculateForProfile($sellerProfile);

        $this->assertNotNull($sellerAlert->fresh()->expired_at);
        $this->assertNull($holderAlert->fresh()->expired_at);
    }
}



