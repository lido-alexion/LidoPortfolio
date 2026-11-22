<?php

namespace App\Services\PriceProviders;

use App\Models\BrokerConnection;
use App\Models\Stock;
use App\Services\Broker\KiteInstrumentRegistryService;
use App\Support\KiteTradingSession;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Per-account Kite market data boundary. It deliberately does not implement
 * PriceProviderInterface: generic sync/background consumers have no user
 * session and must keep using the existing public providers.
 */
final class KiteAuthenticatedPriceData
{
    public function __construct(
        protected KiteCandleNormalizer $normalizer,
        protected KitePriceRequestGate $gate,
        protected KiteInstrumentRegistryService $instruments,
    ) {}

    /** @return array{last_price:float,captured_at:string,provider:string}|null */
    public function latestQuote(int $userId, Stock $stock): ?array
    {
        if (! KiteTradingSession::isEquityMarketOpen()) {
            return null;
        }
        if (! $this->usableConnection($userId)) {
            return null;
        }
        try {
            $mapping = $this->mapping($stock, $userId);
        } catch (\Throwable) {
            return null;
        }
        $instrument = $mapping['exchange'].':'.$mapping['trading_symbol'];
        $cacheKey = 'kite:price:quote:'.$userId.':'.hash('sha256', $instrument);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['last_price'], $cached['captured_at'])
            && now('Asia/Kolkata')->diffInSeconds(Carbon::parse($cached['captured_at'], 'Asia/Kolkata')) <= 120) {
            return $cached;
        }

        try {
            $response = $this->request($userId, 'quote', '/quote', ['i' => $instrument]);
            $price = data_get($response, 'data.'.$instrument.'.last_price');
            $tradedAtRaw = data_get($response, 'data.'.$instrument.'.last_trade_time');
            if (! is_numeric($price) || ! is_finite((float) $price) || (float) $price <= 0 || ! is_string($tradedAtRaw) || trim($tradedAtRaw) === '') {
                $this->gate->failed('quote', $userId);
                return null;
            }
            $tradedAt = Carbon::parse($tradedAtRaw, 'Asia/Kolkata');
            if ($tradedAt->gt(now('Asia/Kolkata')) || $tradedAt->diffInSeconds(now('Asia/Kolkata')) > 120) {
                $this->gate->failed('quote', $userId);
                return null;
            }
            $quote = ['last_price' => (float) $price, 'captured_at' => $tradedAt->toIso8601String(), 'provider' => 'kite'];
            Cache::put($cacheKey, $quote, 2);
            $this->gate->succeeded('quote', $userId);
            return $quote;
        } catch (\Throwable) {
            $this->gate->failed('quote', $userId);
            return null;
        }
    }

    /** @return array<int, array{price_date:string,open_price:?float,high_price:?float,low_price:?float,close_price:float,volume:?int}> */
    public function historicalDaily(int $userId, Stock $stock, Carbon $from, Carbon $to): array
    {
        if ($from->gt($to)) {
            return [];
        }
        $mapping = $this->mapping($stock, $userId);
        $rows = [];
        $cursor = $from->copy()->startOfDay();
        while ($cursor->lte($to)) {
            $chunkEnd = $cursor->copy()->addDays(89)->min($to->copy()->endOfDay());
            try {
                $response = $this->request($userId, 'historical', '/instruments/historical/'.$mapping['instrument_token'].'/day', [
                    'from' => $cursor->format('Y-m-d H:i:s'),
                    'to' => $chunkEnd->format('Y-m-d H:i:s'),
                ]);
                $candles = data_get($response, 'data.candles');
                if (! is_array($candles)) {
                    throw new RuntimeException('Kite historical response has no candles array.');
                }
                foreach ($candles as $candle) {
                    if (is_array($candle) && ($row = $this->normalizer->normalize($candle)) !== null
                        && $row['price_date'] >= $cursor->toDateString() && $row['price_date'] <= $chunkEnd->toDateString()) {
                        $rows[$row['price_date']] = $row;
                    }
                }
                $this->gate->succeeded('historical', $userId);
            } catch (\Throwable $error) {
                $this->gate->failed('historical', $userId);
                throw $error;
            }
            $cursor = $chunkEnd->copy()->addDay()->startOfDay();
        }
        ksort($rows);
        return array_values($rows);
    }

    /** @return array{exchange:string,trading_symbol:string,instrument_token:string} */
    protected function mapping(Stock $stock, int $userId): array
    {
        $exchange = strtoupper(trim((string) $stock->exchange));
        if (! in_array($exchange, ['NSE', 'BSE'], true) || $stock->is_benchmark) {
            throw new RuntimeException('Kite market data is only mapped for NSE/BSE equities.');
        }
        $mapping = $this->instruments->resolve($stock, $userId);
        $token = trim((string) ($mapping['instrument_token'] ?? ''));
        $symbol = trim((string) ($mapping['trading_symbol'] ?? ''));
        if ($token === '' || ! ctype_digit($token) || $symbol === '') {
            throw new RuntimeException('No deterministic Kite instrument mapping exists.');
        }
        return ['exchange' => $exchange, 'trading_symbol' => strtoupper($symbol), 'instrument_token' => $token];
    }

    protected function request(int $userId, string $kind, string $path, array $query): array
    {
        $connection = $this->usableConnection($userId);
        if (! $connection || trim((string) config('broker.kite.api_key')) === '') {
            throw new RuntimeException('No usable Kite session is available.');
        }

        $this->gate->before($kind, $userId);
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Kite-Version' => '3',
                    'Authorization' => 'token '.(string) config('broker.kite.api_key').':'.$connection->access_token,
                ])
                ->get(rtrim((string) config('broker.kite.api_base'), '/').$path, $query);
        } catch (ConnectionException $error) {
            throw $error;
        }
        if ($response->status() === 403) {
            throw new RuntimeException('Kite session is not authorized for market data.');
        }
        if (! $response->successful() || data_get($response->json(), 'status') !== 'success') {
            throw new RuntimeException('Kite market-data request failed with HTTP '.$response->status().'.');
        }
        return (array) $response->json();
    }

    protected function usableConnection(int $userId): ?BrokerConnection
    {
        $connection = BrokerConnection::query()
            ->where('user_id', $userId)
            ->where('provider', BrokerConnection::PROVIDER_KITE)
            ->first();
        return $connection?->isUsable() ? $connection : null;
    }
}
