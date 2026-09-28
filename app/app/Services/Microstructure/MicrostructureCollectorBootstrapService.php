<?php

namespace App\Services\Microstructure;

use App\Models\BrokerConnection;
use App\Models\BrokerInstrument;
use App\Models\MicrostructureCollectorState;
use App\Models\Stock;
use App\Models\User;
use App\Services\Broker\BrokerConnectionService;
use App\Services\Nifty500ConstituentService;
use App\Support\TradingCalendar;
use Illuminate\Support\Str;

class MicrostructureCollectorBootstrapService
{
    public function __construct(
        protected BrokerConnectionService $brokerConnections,
        protected Nifty500ConstituentService $nifty500,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(bool $refreshUniverse = false): array
    {
        $state = MicrostructureCollectorState::current();
        $kiteUserId = config('microstructure_collector.kite_user_id');
        $user = is_int($kiteUserId) ? User::query()->find($kiteUserId) : null;
        $connection = $user !== null ? $this->brokerConnections->connectionFor($user) : null;

        $kite = null;
        if ($connection?->isUsable()) {
            $kite = [
                'api_key' => (string) config('broker.kite.api_key'),
                'access_token' => $connection->access_token,
                'user_id' => $user?->id,
                'expires_at' => $connection->expires_at?->toIso8601String(),
            ];
        }

        return [
            'schema_version' => (string) config('microstructure_collector.schema_version'),
            'enabled' => (bool) config('microstructure_collector.enabled'),
            'manual_hold' => $state->manual_hold,
            'trading_session_day' => TradingCalendar::isScheduledMarketDataDay(),
            'data_root' => (string) config('microstructure_collector.data_root'),
            'backup_root' => (string) config('microstructure_collector.backup_root'),
            'raw_tick_spool_max_mb' => (int) config('microstructure_collector.raw_tick_spool_max_mb', 512),
            'raw_tick_spool_max_age_minutes' => (int) config('microstructure_collector.raw_tick_spool_max_age_minutes', 60),
            'kite' => $kite,
            'universe' => $this->universeInstruments($user?->id, $refreshUniverse),
            'universe_refreshed_at' => $state->universe_refreshed_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function universeInstruments(?int $kiteUserId, bool $refreshUniverse): array
    {
        $symbols = $this->nifty500->symbols($refreshUniverse);
        if ($symbols === []) {
            return [];
        }

        $stocks = Stock::query()
            ->whereIn('symbol', $symbols)
            ->where('exchange', 'NSE')
            ->get(['id', 'symbol', 'exchange', 'name']);

        $stockIds = $stocks->pluck('id')->all();
        $instruments = BrokerInstrument::query()
            ->where('provider', BrokerConnection::PROVIDER_KITE)
            ->where('is_active', true)
            ->whereIn('stock_id', $stockIds)
            ->get(['stock_id', 'trading_symbol', 'instrument_token', 'exchange']);

        $byStock = $instruments->keyBy('stock_id');

        $rows = [];
        foreach ($stocks as $stock) {
            $mapping = $byStock->get($stock->id);
            if ($mapping === null || $mapping->instrument_token === null || $mapping->instrument_token === '') {
                continue;
            }

            $rows[] = [
                'instrument_id' => (int) $stock->id,
                'source_instrument_token' => (int) $mapping->instrument_token,
                'exchange' => $stock->exchange ?: 'NSE',
                'tradingsymbol' => $mapping->trading_symbol ?: $stock->symbol,
                'symbol' => Str::upper($stock->symbol),
            ];
        }

        usort($rows, fn (array $a, array $b) => $a['instrument_id'] <=> $b['instrument_id']);

        return $rows;
    }
}
