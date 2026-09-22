<?php

namespace App\Services\Broker;

use App\Exceptions\DomainException;
use App\Models\BrokerConnection;
use App\Models\BrokerInstrument;
use App\Models\Stock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class KiteInstrumentRegistryService
{
    public function __construct(protected \App\Services\PortfolioLoggerService $logger) {}

    /** @return array{matched:int,ambiguous:int,unmatched:int} */
    public function syncForUser(int $userId, ?Stock $target = null): array
    {
        $connection = BrokerConnection::query()
            ->where('user_id', $userId)
            ->where('provider', BrokerConnection::PROVIDER_KITE)
            ->first();
        if (! $connection?->isUsable()) {
            throw new DomainException('Kite session is missing or expired.', 'BROKER_SESSION_EXPIRED', 403);
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-Kite-Version' => '3',
                    'Authorization' => 'token '.(string) config('broker.kite.api_key').':'.$connection->access_token,
                ])
                ->get(rtrim((string) config('broker.kite.api_base'), '/').'/instruments/NSE');
        } catch (ConnectionException $e) {
            throw new DomainException('Kite instrument master is unavailable.', 'BROKER_INSTRUMENT_SYNC_UNAVAILABLE', 422, $e);
        }
        if (! $response->successful()) {
            throw new DomainException('Kite instrument master returned HTTP '.$response->status().'.', 'BROKER_INSTRUMENT_SYNC_UNAVAILABLE', 422);
        }

        $rows = $this->parseCsv((string) $response->body());
        $stocks = $target ? collect([$target]) : Stock::query()->where('exchange', 'NSE')->get();
        if ($target) {
            BrokerInstrument::query()
                ->where('provider', BrokerConnection::PROVIDER_KITE)
                ->where('stock_id', $target->id)
                ->where('exchange', $target->exchange ?: 'NSE')
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }
        $stats = ['matched' => 0, 'ambiguous' => 0, 'unmatched' => 0];
        foreach ($stocks as $stock) {
            $match = $this->match($stock, $rows);
            if ($target && $match === false) {
                throw new DomainException('Multiple Kite instruments plausibly match '.$stock->symbol.'.', 'BROKER_INSTRUMENT_MAPPING_AMBIGUOUS', 422);
            }
            if ($match === null) {
                $stats['unmatched']++;
                continue;
            }
            if ($match === false) {
                $stats['ambiguous']++;
                continue;
            }
            $this->save($stock, $match);
            $stats['matched']++;
        }
        $this->logger->event('KiteInstrumentRegistryService', 'broker.instrument_sync', 'info', 'Kite instrument registry refreshed', $stats);

        return $stats;
    }

    /** @return array{trading_symbol:string,series:?string,instrument_token:?string,exchange_token:?string}|never */
    public function resolve(Stock $stock, int $userId, bool $refresh = false): array
    {
        $current = BrokerInstrument::query()
            ->where('provider', BrokerConnection::PROVIDER_KITE)
            ->where('stock_id', $stock->id)
            ->where('exchange', $stock->exchange ?: 'NSE')
            ->where('is_active', true)
            ->first();
        if ($current && ! $refresh) {
            return $current->only(['trading_symbol', 'series', 'instrument_token', 'exchange_token']);
        }

        $this->syncForUser($userId, $stock);
        $current = BrokerInstrument::query()
            ->where('provider', BrokerConnection::PROVIDER_KITE)
            ->where('stock_id', $stock->id)
            ->where('exchange', $stock->exchange ?: 'NSE')
            ->where('is_active', true)
            ->first();
        if (! $current) {
            throw new DomainException('No deterministic Kite instrument mapping exists for '.$stock->symbol.'.', 'BROKER_INSTRUMENT_MAPPING_NOT_FOUND', 422);
        }

        return $current->only(['trading_symbol', 'series', 'instrument_token', 'exchange_token']);
    }

    /** @return list<array<string,string|null>> */
    protected function parseCsv(string $csv): array
    {
        $lines = preg_split('/\R/', trim($csv)) ?: [];
        $header = str_getcsv((string) array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $values = str_getcsv($line);
            if (count($values) !== count($header)) continue;
            $rows[] = array_combine($header, $values) ?: [];
        }
        return array_values(array_filter($rows, fn (array $row): bool => strtoupper((string) ($row['exchange'] ?? '')) === 'NSE'));
    }

    /** @return array<string,string|null>|false|null */
    protected function match(Stock $stock, array $rows): array|false|null
    {
        $symbol = strtoupper(trim((string) $stock->symbol));
        $exact = array_values(array_filter($rows, fn (array $row): bool => strtoupper((string) ($row['tradingsymbol'] ?? '')) === $symbol));
        if (count($exact) === 1) return $exact[0];
        $base = array_values(array_filter($rows, function (array $row) use ($symbol): bool {
            $candidate = strtoupper((string) ($row['tradingsymbol'] ?? ''));
            return preg_replace('/-[A-Z0-9]+$/', '', $candidate) === $symbol;
        }));
        if ($stock->series) {
            $series = array_values(array_filter($base, fn (array $row): bool => strtoupper((string) ($row['series'] ?? '')) === strtoupper((string) $stock->series)
                || str_ends_with(strtoupper((string) ($row['tradingsymbol'] ?? '')), '-'.strtoupper((string) $stock->series))));
            if (count($series) === 1) return $series[0];
        }
        return count($base) === 1 ? $base[0] : (count($base) > 1 ? false : null);
    }

    protected function save(Stock $stock, array $row): void
    {
        BrokerInstrument::query()->updateOrCreate(
            ['provider' => BrokerConnection::PROVIDER_KITE, 'stock_id' => $stock->id, 'exchange' => 'NSE'],
            [
                'trading_symbol' => (string) ($row['tradingsymbol'] ?? $stock->symbol),
                'instrument_token' => $row['instrument_token'] ?? null,
                'exchange_token' => $row['exchange_token'] ?? null,
                'series' => $row['series'] ?? null,
                'broker_name' => $row['name'] ?? null,
                'raw_metadata' => $row,
                'is_active' => true,
                'last_seen_at' => now(),
                'synced_at' => now(),
            ],
        );
    }
}
