<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\IndiaVixAlertService;
use App\Services\ProfileSettingsService;
use App\Services\TelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IndiaVixAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function seedVixClose(float $close, ?string $date = null): Stock
    {
        $stock = Stock::query()->create([
            'symbol' => 'INDIAVIX',
            'exchange' => 'NSE',
            'name' => 'India VIX',
            'is_active' => true,
            'is_benchmark' => true,
            'yahoo_symbol' => '^INDIAVIX',
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => $date ?? now()->subDay()->toDateString(),
            'close_price' => $close,
            'adjusted_close_price' => $close,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        return $stock;
    }

    public function test_defaults_are_enabled_with_threshold_20(): void
    {
        $user = User::query()->create([
            'name' => 'VIX User',
            'email' => 'vix-defaults-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $settings = app(ProfileSettingsService::class)->all($profile);

        $this->assertSame('true', $settings['indiavix_alert_enabled']);
        $this->assertSame('20', $settings['indiavix_alert_threshold']);
    }

    public function test_settings_api_persists_vix_alert_fields(): void
    {
        $user = User::query()->create([
            'name' => 'VIX Settings',
            'email' => 'vix-settings-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)
            ->putJson('/api/settings', [
                'indiavix_alert_enabled' => 'false',
                'indiavix_alert_threshold' => '18.5',
            ])
            ->assertOk()
            ->assertJsonPath('data.indiavix_alert_enabled', 'false')
            ->assertJsonPath('data.indiavix_alert_threshold', '18.5');
    }

    public function test_notifies_once_when_vix_crosses_above_threshold(): void
    {
        $this->seedVixClose(21.5, '2026-07-18');

        $user = User::query()->create([
            'name' => 'VIX Alert',
            'email' => 'vix-alert-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        app(ProfileSettingsService::class)->update($profile, [
            'indiavix_alert_enabled' => 'true',
            'indiavix_alert_threshold' => '20',
            'notifications_enabled' => 'true',
            'telegram_bot_token' => 'token',
            'telegram_chat_id' => 'chat',
        ]);

        $telegram = $this->createMock(TelegramNotificationService::class);
        $telegram->expects($this->once())
            ->method('sendMessageForProfile')
            ->with(
                $this->callback(fn ($p) => $p->id === $profile->id),
                $this->callback(fn (string $message) => str_contains($message, '21.5')
                    && str_contains($message, '20')
                    && str_contains($message, 'India VIX')),
            )
            ->willReturn(true);
        $this->app->instance(TelegramNotificationService::class, $telegram);

        $first = app(IndiaVixAlertService::class)->evaluateAndNotify();
        $this->assertTrue($first['evaluated']);
        $this->assertSame(1, $first['notified']);

        $telegram2 = $this->createMock(TelegramNotificationService::class);
        $telegram2->expects($this->never())->method('sendMessageForProfile');
        $this->app->instance(TelegramNotificationService::class, $telegram2);

        $second = app(IndiaVixAlertService::class)->evaluateAndNotify();
        $this->assertSame(0, $second['notified']);
    }

    public function test_skips_when_alert_disabled(): void
    {
        $this->seedVixClose(25);

        $user = User::query()->create([
            'name' => 'VIX Off',
            'email' => 'vix-off-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        app(ProfileSettingsService::class)->update($profile, [
            'indiavix_alert_enabled' => 'false',
            'indiavix_alert_threshold' => '20',
            'telegram_bot_token' => 'token',
            'telegram_chat_id' => 'chat',
        ]);

        $telegram = $this->createMock(TelegramNotificationService::class);
        $telegram->expects($this->never())->method('sendMessageForProfile');
        $this->app->instance(TelegramNotificationService::class, $telegram);

        $result = app(IndiaVixAlertService::class)->evaluateAndNotify();
        $this->assertSame(0, $result['notified']);
    }

    public function test_rearms_when_vix_falls_back_below_threshold(): void
    {
        $stock = $this->seedVixClose(22, '2026-07-17');

        $user = User::query()->create([
            'name' => 'VIX Rearm',
            'email' => 'vix-rearm-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $settings = app(ProfileSettingsService::class);
        $settings->update($profile, [
            'indiavix_alert_enabled' => 'true',
            'indiavix_alert_threshold' => '20',
            'telegram_bot_token' => 'token',
            'telegram_chat_id' => 'chat',
        ]);

        $telegram = $this->createMock(TelegramNotificationService::class);
        $telegram->expects($this->exactly(2))->method('sendMessageForProfile')->willReturn(true);
        $this->app->instance(TelegramNotificationService::class, $telegram);

        $this->assertSame(1, app(IndiaVixAlertService::class)->evaluateAndNotify()['notified']);

        StockPrice::query()->where('stock_id', $stock->id)->delete();
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2026-07-18',
            'close_price' => 18,
            'adjusted_close_price' => 18,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $rearm = app(IndiaVixAlertService::class)->evaluateAndNotify();
        $this->assertSame(0, $rearm['notified']);
        $this->assertSame(1, $rearm['rearmed']);

        StockPrice::query()->where('stock_id', $stock->id)->delete();
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2026-07-19',
            'close_price' => 21,
            'adjusted_close_price' => 21,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $this->assertSame(1, app(IndiaVixAlertService::class)->evaluateAndNotify()['notified']);
    }

    public function test_latest_vix_close_repairs_hundredfold_scaled_row(): void
    {
        $stock = $this->seedVixClose(1264.5, '2026-07-28');
        StockPrice::query()->where('stock_id', $stock->id)->update([
            'open_price' => 1266.0,
            'high_price' => 1282.0,
            'low_price' => 1172.25,
            'adjusted_close_price' => 1264.5,
        ]);

        $latest = app(IndiaVixAlertService::class)->latestVixClose();
        $this->assertNotNull($latest);
        $this->assertEqualsWithDelta(12.645, $latest['close'], 0.0001);
        $this->assertSame('2026-07-28', $latest['price_date']);

        $row = StockPrice::query()->where('stock_id', $stock->id)->first();
        $this->assertEqualsWithDelta(12.645, (float) $row->close_price, 0.0001);
        $this->assertEqualsWithDelta(12.66, (float) $row->open_price, 0.0001);
    }
}
