<?php

namespace Tests\Feature\Execution;

use App\Exceptions\DomainException;
use App\Models\BrokerConnection;
use App\Models\Stock;
use App\Models\User;
use App\Services\Broker\KiteInstrumentRegistryService;
use App\Services\Broker\KiteBrokerGateway;
use App\Services\Broker\BrokerOrderRequest;
use App\Models\BrokerInstrument;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KiteInstrumentRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_series_qualified_kite_symbol_is_deterministically_registered(): void
    {
        $user = User::factory()->create();
        BrokerConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'kite',
            'connected_at' => now(),
            'expires_at' => now()->addDay(),
        ])->forceFill(['access_token' => 'token'])->save();
        $stock = Stock::query()->create(['symbol' => 'SITINET', 'exchange' => 'NSE', 'name' => 'SITI NETWORKS']);
        Http::fake(['https://api.kite.trade/instruments/NSE' => Http::response($this->csv([
            ['7477761', '29210', 'SITINET-BZ', 'SITI NETWORKS', 'NSE'],
        ]))]);

        $result = app(KiteInstrumentRegistryService::class)->resolve($stock, $user->id, refresh: true);

        $this->assertSame('SITINET-BZ', $result['trading_symbol']);
        $this->assertDatabaseHas('stox_broker_instruments', [
            'stock_id' => $stock->id,
            'trading_symbol' => 'SITINET-BZ',
            'instrument_token' => '7477761',
        ]);
    }

    public function test_ambiguous_series_candidates_block_without_guessing(): void
    {
        $user = User::factory()->create();
        BrokerConnection::query()->create(['user_id' => $user->id, 'provider' => 'kite', 'connected_at' => now(), 'expires_at' => now()->addDay()])->forceFill(['access_token' => 'token'])->save();
        $stock = Stock::query()->create(['symbol' => 'SITINET', 'exchange' => 'NSE', 'name' => 'SITI NETWORKS']);
        Http::fake(['https://api.kite.trade/instruments/NSE' => Http::response($this->csv([
            ['1', '2', 'SITINET-BZ', 'SITI NETWORKS', 'NSE'],
            ['3', '4', 'SITINET-BE', 'SITI NETWORKS', 'NSE'],
        ]))]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Multiple Kite instruments');
        app(KiteInstrumentRegistryService::class)->resolve($stock, $user->id, refresh: true);
        $this->assertDatabaseCount('stox_broker_instruments', 0);
    }

    public function test_market_order_uses_registry_symbol_regular_endpoint_and_unbounded_protection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00', 'Asia/Kolkata'));
        $user = User::factory()->create();
        BrokerConnection::query()->create(['user_id' => $user->id, 'provider' => 'kite', 'connected_at' => now(), 'expires_at' => now()->addDay()])->forceFill(['access_token' => 'token'])->save();
        $stock = Stock::query()->create(['symbol' => 'SITINET', 'exchange' => 'NSE', 'name' => 'SITI NETWORKS']);
        BrokerInstrument::query()->create(['provider' => 'kite', 'stock_id' => $stock->id, 'exchange' => 'NSE', 'trading_symbol' => 'SITINET-BZ', 'is_active' => true]);
        Http::fake(['https://api.kite.trade/orders/regular' => Http::response(['status' => 'success', 'data' => ['order_id' => 'order-1']])]);

        $submission = app(KiteBrokerGateway::class)->placeOrder(new BrokerOrderRequest(1, 1, 1, $stock->id, 'SITINET', 'NSE', 'buy', 1, 'submission-1'));

        $this->assertSame('regular', $submission->variety);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.kite.trade/orders/regular'
                && $request['tradingsymbol'] === 'SITINET-BZ'
                && (int) $request['market_protection'] === -1;
        });
        Carbon::setTestNow();
    }

    public function test_invalid_instrument_refreshes_and_retries_once_with_same_submission(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00', 'Asia/Kolkata'));
        $user = User::factory()->create();
        BrokerConnection::query()->create(['user_id' => $user->id, 'provider' => 'kite', 'connected_at' => now(), 'expires_at' => now()->addDay()])->forceFill(['access_token' => 'token'])->save();
        $stock = Stock::query()->create(['symbol' => 'SITINET', 'exchange' => 'NSE', 'name' => 'SITI NETWORKS']);
        BrokerInstrument::query()->create(['provider' => 'kite', 'stock_id' => $stock->id, 'exchange' => 'NSE', 'trading_symbol' => 'SITINET', 'is_active' => true]);
        $orders = 0;
        Http::fake(function ($request) use (&$orders) {
            if (str_contains($request->url(), '/instruments/NSE')) {
                return Http::response($this->csv([['7477761', '29210', 'SITINET-BZ', 'SITI NETWORKS', 'NSE']]));
            }
            $orders++;
            return $orders === 1
                ? Http::response(['status' => 'error', 'error_type' => 'InputException', 'message' => 'instrument SITINET expired or does not exist'], 400)
                : Http::response(['status' => 'success', 'data' => ['order_id' => 'order-retried']]);
        });

        $submission = app(KiteBrokerGateway::class)->placeOrder(new BrokerOrderRequest(1, 1, 1, $stock->id, 'SITINET', 'NSE', 'buy', 1, 'same-submission'));

        $this->assertSame(2, $orders);
        $this->assertSame('order-retried', $submission->brokerOrderId);
        $this->assertSame('SITINET-BZ', BrokerInstrument::query()->where('stock_id', $stock->id)->value('trading_symbol'));
        Carbon::setTestNow();
    }

    public function test_after_market_order_uses_amo_endpoint(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 18:00:00', 'Asia/Kolkata'));
        $user = User::factory()->create();
        BrokerConnection::query()->create(['user_id' => $user->id, 'provider' => 'kite', 'connected_at' => now(), 'expires_at' => now()->addDay()])->forceFill(['access_token' => 'token'])->save();
        $stock = Stock::query()->create(['symbol' => 'SITINET', 'exchange' => 'NSE', 'name' => 'SITI NETWORKS']);
        BrokerInstrument::query()->create(['provider' => 'kite', 'stock_id' => $stock->id, 'exchange' => 'NSE', 'trading_symbol' => 'SITINET-BZ', 'is_active' => true]);
        Http::fake(['https://api.kite.trade/orders/amo' => Http::response(['status' => 'success', 'data' => ['order_id' => 'amo-order-1']])]);

        app(KiteBrokerGateway::class)->placeOrder(new BrokerOrderRequest(1, 1, 1, $stock->id, 'SITINET', 'NSE', 'buy', 1, 'amo-submission'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.kite.trade/orders/amo');
        Carbon::setTestNow();
    }

    public function test_missing_mapping_blocks_without_calling_the_order_endpoint(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00', 'Asia/Kolkata'));
        $user = User::factory()->create();
        BrokerConnection::query()->create(['user_id' => $user->id, 'provider' => 'kite', 'connected_at' => now(), 'expires_at' => now()->addDay()])->forceFill(['access_token' => 'token'])->save();
        $stock = Stock::query()->create(['symbol' => 'UNKNOWN', 'exchange' => 'NSE', 'name' => 'Unknown']);
        Http::fake(['https://api.kite.trade/instruments/NSE' => Http::response($this->csv([]))]);

        try {
            app(KiteBrokerGateway::class)->placeOrder(new BrokerOrderRequest(1, 1, 1, $stock->id, 'UNKNOWN', 'NSE', 'buy', 1, 'missing-submission'));
            $this->fail('Expected missing instrument mapping to block placement.');
        } catch (DomainException $exception) {
            $this->assertSame('BROKER_INSTRUMENT_MAPPING_NOT_FOUND', $exception->errorCode());
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/orders/'));
        Carbon::setTestNow();
    }

    public function test_live_quote_uses_the_registered_broker_symbol(): void
    {
        $user = User::factory()->create();
        BrokerConnection::query()->create(['user_id' => $user->id, 'provider' => 'kite', 'connected_at' => now(), 'expires_at' => now()->addDay()])->forceFill(['access_token' => 'token'])->save();
        $stock = Stock::query()->create(['symbol' => 'SITINET', 'exchange' => 'NSE', 'name' => 'SITI NETWORKS']);
        BrokerInstrument::query()->create(['provider' => 'kite', 'stock_id' => $stock->id, 'exchange' => 'NSE', 'trading_symbol' => 'SITINET-BZ', 'is_active' => true]);
        Http::fake(fn ($request) => str_starts_with($request->url(), 'https://api.kite.trade/quote/ltp')
            ? Http::response([
                'status' => 'success',
                'data' => ['NSE:SITINET-BZ' => ['last_price' => 0.29]],
            ])
            : Http::response([], 404));

        $this->assertSame(0.29, app(KiteBrokerGateway::class)->liveQuote($user->id, $stock));
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.kite.trade/quote/ltp')
            && $request['i'] === 'NSE:SITINET-BZ');
    }

    public function test_targeted_refresh_deactivates_stale_mapping_when_master_has_no_match(): void
    {
        $user = User::factory()->create();
        BrokerConnection::query()->create(['user_id' => $user->id, 'provider' => 'kite', 'connected_at' => now(), 'expires_at' => now()->addDay()])->forceFill(['access_token' => 'token'])->save();
        $stock = Stock::query()->create(['symbol' => 'SITINET', 'exchange' => 'NSE', 'name' => 'SITI NETWORKS']);
        $old = BrokerInstrument::query()->create(['provider' => 'kite', 'stock_id' => $stock->id, 'exchange' => 'NSE', 'trading_symbol' => 'SITINET-BZ', 'is_active' => true]);
        Http::fake(['https://api.kite.trade/instruments/NSE' => Http::response($this->csv([]))]);

        try {
            app(KiteInstrumentRegistryService::class)->resolve($stock, $user->id, refresh: true);
            $this->fail('Expected the refreshed missing mapping to block.');
        } catch (DomainException $exception) {
            $this->assertSame('BROKER_INSTRUMENT_MAPPING_NOT_FOUND', $exception->errorCode());
        }

        $this->assertFalse($old->fresh()->is_active);
    }

    public function test_ambiguous_targeted_refresh_blocks_without_order_submission(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00', 'Asia/Kolkata'));
        $user = User::factory()->create();
        BrokerConnection::query()->create(['user_id' => $user->id, 'provider' => 'kite', 'connected_at' => now(), 'expires_at' => now()->addDay()])->forceFill(['access_token' => 'token'])->save();
        $stock = Stock::query()->create(['symbol' => 'SITINET', 'exchange' => 'NSE', 'name' => 'SITI NETWORKS']);
        BrokerInstrument::query()->create(['provider' => 'kite', 'stock_id' => $stock->id, 'exchange' => 'NSE', 'trading_symbol' => 'SITINET', 'is_active' => true]);
        $orderCalls = 0;
        Http::fake(function ($request) use (&$orderCalls) {
            if (str_contains($request->url(), '/instruments/NSE')) {
                return Http::response($this->csv([
                    ['1', '2', 'SITINET-BZ', 'SITI NETWORKS', 'NSE'],
                    ['3', '4', 'SITINET-BE', 'SITI NETWORKS', 'NSE'],
                ]));
            }

            if (str_contains($request->url(), '/orders/')) {
                $orderCalls++;
                return Http::response(['status' => 'error', 'error_type' => 'InputException', 'message' => 'instrument SITINET expired or does not exist'], 400);
            }

            return Http::response([], 404);
        });

        // Force the gateway into its single refresh path before the ambiguous master is evaluated.
        BrokerInstrument::query()->where('stock_id', $stock->id)->update(['trading_symbol' => 'STALE']);

        try {
            app(KiteBrokerGateway::class)->placeOrder(new BrokerOrderRequest(1, 1, 1, $stock->id, 'SITINET', 'NSE', 'buy', 1, 'ambiguous-submission'));
            $this->fail('Expected ambiguous mapping to block.');
        } catch (DomainException $exception) {
            $this->assertSame('BROKER_INSTRUMENT_MAPPING_AMBIGUOUS', $exception->errorCode());
        }

        $this->assertSame(1, $orderCalls, 'Ambiguous refresh must not retry the broker order.');
        Carbon::setTestNow();
    }

    public function test_amo_cancellation_uses_the_persisted_variety_endpoint(): void
    {
        $user = User::factory()->create();
        BrokerConnection::query()->create(['user_id' => $user->id, 'provider' => 'kite', 'connected_at' => now(), 'expires_at' => now()->addDay()])->forceFill(['access_token' => 'token'])->save();
        Http::fake([
            'https://api.kite.trade/orders/amo/order-amo' => Http::response(['status' => 'success']),
            'https://api.kite.trade/orders/order-amo' => Http::response(['status' => 'success', 'data' => [['status' => 'CANCELLED', 'quantity' => 1, 'filled_quantity' => 0]]]),
        ]);

        app(KiteBrokerGateway::class)->cancelOrder($user->id, 'order-amo', 'amo');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.kite.trade/orders/amo/order-amo' && $request->method() === 'DELETE');
    }

    public function test_regular_cancellation_uses_the_regular_endpoint(): void
    {
        $user = User::factory()->create();
        BrokerConnection::query()->create(['user_id' => $user->id, 'provider' => 'kite', 'connected_at' => now(), 'expires_at' => now()->addDay()])->forceFill(['access_token' => 'token'])->save();
        Http::fake([
            'https://api.kite.trade/orders/regular/order-regular' => Http::response(['status' => 'success']),
            'https://api.kite.trade/orders/order-regular' => Http::response(['status' => 'success', 'data' => [['status' => 'CANCELLED', 'quantity' => 1, 'filled_quantity' => 0]]]),
        ]);

        app(KiteBrokerGateway::class)->cancelOrder($user->id, 'order-regular', 'regular');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.kite.trade/orders/regular/order-regular' && $request->method() === 'DELETE');
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows): string
    {
        $lines = ['instrument_token,exchange_token,tradingsymbol,name,exchange'];
        foreach ($rows as $row) $lines[] = implode(',', $row);
        return implode("\n", $lines)."\n";
    }
}
