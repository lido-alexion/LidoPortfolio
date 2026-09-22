<?php

namespace App\Console\Commands;

use App\Models\BrokerConnection;
use App\Services\Broker\KiteInstrumentRegistryService;
use Illuminate\Console\Command;

class SyncKiteInstrumentsCommand extends Command
{
    protected $signature = 'portfolio:sync-kite-instruments {--stock= : Reconcile one Stock id}';

    protected $description = 'Refresh the deterministic Kite NSE instrument registry';

    public function handle(KiteInstrumentRegistryService $registry): int
    {
        $connection = BrokerConnection::query()
            ->where('provider', BrokerConnection::PROVIDER_KITE)
            ->whereNotNull('access_token')
            ->orderBy('id')
            ->first();
        if (! $connection) {
            $this->warn('No Kite connection is available; instrument refresh skipped.');

            return self::SUCCESS;
        }

        $stock = $this->option('stock') ? \App\Models\Stock::query()->find((int) $this->option('stock')) : null;
        if ($this->option('stock') && ! $stock) {
            $this->error('Stock not found.');

            return self::FAILURE;
        }

        try {
            $stats = $registry->syncForUser((int) $connection->user_id, $stock);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('Kite instruments: %d matched, %d ambiguous, %d unmatched.', $stats['matched'], $stats['ambiguous'], $stats['unmatched']));

        return ($stats['matched'] === 0 && $stats['ambiguous'] > 0) ? self::FAILURE : self::SUCCESS;
    }
}
