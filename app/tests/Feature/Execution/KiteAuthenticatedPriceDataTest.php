<?php

namespace Tests\Feature\Execution;

use App\Models\BrokerConnection;
use App\Models\BrokerInstrument;
use App\Models\Stock;
use App\Models\User;
use App\Services\PriceProviders\KiteAuthenticatedPriceData;
use App\Support\KiteTradingSession;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KiteAuthenticatedPriceDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['broker.kite.api_key' => 'test-key']);
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_authenticated_quote_uses_registered_exchange_symbol_and_user_scoped_short_cache(): void
    {
        [$user, $stock] = $this->connectedStock('NSE', 'ABC-BE');
        Http::fake(['https://api.kite.trade/quote*' => Http::response([
            'status' => 'success', 'data' => ['NSE:ABC-BE' => ['last_price' => 123.45, 'last_trade_time' => '2026-11-23 09:59:45']],
        ])]);

        $service = app(KiteAuthenticatedPriceData::class);
        $one = $service->latestQuote($user->id, $stock);
        $two = $service->latestQuote($user->id, $stock);

        $this->assertSame(123.45, $one['last_price']);
        $this->assertSame($one, $two);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/quote?')
            && ($request['i'] ?? null) === 'NSE:ABC-BE'
            && $request->hasHeader('Authorization', 'token test-key:test-token'));
    }

    public function test_historical_daily_returns_exchange_dates_and_raw_ohlcv(): void
    {
        [$user, $stock] = $this->connectedStock('NSE', 'ABC');
        Http::fake(['https://api.kite.trade/instruments/historical/998/day*' => Http::response([
            'status' => 'success', 'data' => ['candles' => [
                ['2026-11-20T09:15:00+0530', 100, 104, 99, 103, 500],
            ]],
        ])]);

        $rows = app(KiteAuthenticatedPriceData::class)->historicalDaily(
            $user->id, $stock, now()->parse('2026-11-20'), now()->parse('2026-11-20'),
        );

        $this->assertSame('2026-11-20', $rows[0]['price_date']);
        $this->assertSame(103.0, $rows[0]['close_price']);
        $this->assertArrayNotHasKey('adjusted_close_price', $rows[0]);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/instruments/historical/998/day')
            && $request->hasHeader('Authorization', 'token test-key:test-token'));
    }

    public function test_quote_cache_is_isolated_between_connected_users(): void
    {
        [$firstUser, $stock] = $this->connectedStock('NSE', 'ABC');
        $secondUser = User::factory()->create();
        BrokerConnection::query()->create([
            'user_id' => $secondUser->id, 'provider' => 'kite', 'connected_at' => now(), 'expires_at' => null,
        ])->forceFill(['access_token' => 'second-token'])->save();
        Http::fake([
            'https://api.kite.trade/quote*' => Http::sequence()
                ->push(['status' => 'success', 'data' => ['NSE:ABC' => ['last_price' => 100, 'last_trade_time' => '2026-11-23 09:59:45']]])
                ->push(['status' => 'success', 'data' => ['NSE:ABC' => ['last_price' => 101, 'last_trade_time' => '2026-11-23 09:59:46']]]),
        ]);

        $service = app(KiteAuthenticatedPriceData::class);
        $first = $service->latestQuote($firstUser->id, $stock);
        $second = $service->latestQuote($secondUser->id, $stock);

        $this->assertSame(100.0, $first['last_price']);
        $this->assertSame(101.0, $second['last_price']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'token test-key:second-token'));
    }

    public function test_historical_request_uses_exchange_local_calendar_dates_for_utc_inputs(): void
    {
        [$user, $stock] = $this->connectedStock('NSE', 'ABC');
        Http::fake(['https://api.kite.trade/instruments/historical/998/day*' => Http::response([
            'status' => 'success', 'data' => ['candles' => [
                ['2026-11-20T00:00:00+0530', 100, 104, 99, 103, 500],
            ]],
        ])]);

        app(KiteAuthenticatedPriceData::class)->historicalDaily(
            $user->id,
            $stock,
            Carbon::parse('2026-11-19 19:00:00', 'UTC'),
            Carbon::parse('2026-11-20 18:29:59', 'UTC'),
        );

        Http::assertSent(fn ($request): bool => ($request['from'] ?? null) === '2026-11-20 00:00:00'
            && ($request['to'] ?? null) === '2026-11-20 23:59:59');
    }

    public function test_incomplete_historical_candle_fails_the_whole_kite_response_for_fallback(): void
    {
        [$user, $stock] = $this->connectedStock('NSE', 'ABC');
        Http::fake(['https://api.kite.trade/instruments/historical/998/day*' => Http::response([
            'status' => 'success', 'data' => ['candles' => [
                ['2026-11-20T09:15:00+0530', 100, 104, 99, 103, 500],
                ['2026-11-20T09:15:00+0530', 100, 104, 99, 103],
            ]],
        ])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('incomplete or invalid candle');
        app(KiteAuthenticatedPriceData::class)->historicalDaily(
            $user->id, $stock, Carbon::parse('2026-11-20'), Carbon::parse('2026-11-20'),
        );
    }

    public function test_missing_internal_session_candle_fails_the_partial_history_for_fallback(): void
    {
        [$user, $stock] = $this->connectedStock('NSE', 'ABC');
        Http::fake(['https://api.kite.trade/instruments/historical/998/day*' => Http::response([
            'status' => 'success', 'data' => ['candles' => [
                ['2026-11-16T00:00:00+0530', 100, 104, 99, 103, 500],
                ['2026-11-18T00:00:00+0530', 100, 104, 99, 103, 500],
            ]],
        ])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing an internal equity-session candle');
        app(KiteAuthenticatedPriceData::class)->historicalDaily(
            $user->id,
            $stock,
            Carbon::parse('2026-11-16', 'Asia/Kolkata'),
            Carbon::parse('2026-11-18', 'Asia/Kolkata'),
        );
    }

    public function test_missing_user_session_falls_back_without_a_kite_request(): void
    {
        $stock = Stock::query()->create(['symbol' => 'ABC', 'exchange' => 'NSE', 'name' => 'ABC LTD']);
        BrokerInstrument::query()->create([
            'provider' => 'kite', 'stock_id' => $stock->id, 'exchange' => 'NSE',
            'trading_symbol' => 'ABC', 'instrument_token' => '998', 'is_active' => true,
        ]);
        Http::preventStrayRequests();

        $this->assertNull(app(KiteAuthenticatedPriceData::class)->latestQuote(98765, $stock));
        Http::assertNothingSent();
    }

    public function test_historical_request_without_user_session_returns_empty_for_existing_fallbacks(): void
    {
        $stock = Stock::query()->create(['symbol' => 'ABC', 'exchange' => 'NSE', 'name' => 'ABC LTD']);
        BrokerInstrument::query()->create([
            'provider' => 'kite', 'stock_id' => $stock->id, 'exchange' => 'NSE',
            'trading_symbol' => 'ABC', 'instrument_token' => '998', 'is_active' => true,
        ]);
        Http::preventStrayRequests();

        $rows = app(KiteAuthenticatedPriceData::class)->historicalDaily(
            98765, $stock, Carbon::parse('2026-11-20'), Carbon::parse('2026-11-20'),
        );

        $this->assertSame([], $rows);
        Http::assertNothingSent();
    }

    public function test_live_quote_is_not_requested_outside_the_local_equity_session(): void
    {
        [$user, $stock] = $this->connectedStock('NSE', 'ABC');
        Carbon::setTestNow(Carbon::parse('2026-11-23 16:00:00', 'Asia/Kolkata'));
        Http::preventStrayRequests();

        $this->assertFalse(KiteTradingSession::isEquityMarketOpen());
        $this->assertNull(app(KiteAuthenticatedPriceData::class)->latestQuote($user->id, $stock));
        Http::assertNothingSent();
    }

    /** @return array{User,Stock} */
    private function connectedStock(string $exchange, string $symbol): array
    {
        $user = User::factory()->create();
        BrokerConnection::query()->create([
            'user_id' => $user->id, 'provider' => 'kite', 'connected_at' => now(), 'expires_at' => null,
        ])->forceFill(['access_token' => 'test-token'])->save();
        $stock = Stock::query()->create(['symbol' => 'ABC', 'exchange' => $exchange, 'name' => 'ABC LTD']);
        BrokerInstrument::query()->create([
            'provider' => 'kite', 'stock_id' => $stock->id, 'exchange' => $exchange,
            'trading_symbol' => $symbol, 'instrument_token' => '998', 'is_active' => true,
        ]);
        return [$user, $stock];
    }
}
