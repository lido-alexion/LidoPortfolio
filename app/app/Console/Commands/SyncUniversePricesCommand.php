<?php

namespace App\Console\Commands;

use App\Services\UniversePriceSyncService;
use App\Services\UniverseStockResolverService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SyncUniversePricesCommand extends Command
{
    protected $signature = 'portfolio:sync-universe-prices
        {--mode=daily : daily (incremental) or backfill (full history window)}
        {--scope= : all_equities, all_nse (deprecated), or nifty500 (default from config)}
        {--batch= : Stocks per run when not using --all}
        {--all : Process entire universe in one run (for initial backfill)}
        {--reset-cursor : Start from the first stock in the universe}';

    protected $description = 'Sync OHLCV price history for the NSE stock universe (rate-limited batches)';

    public function handle(
        UniversePriceSyncService $sync,
        UniverseStockResolverService $resolver,
    ): int {
        if (! $sync->isEnabled()) {
            $this->warn('Universe price sync is disabled (UNIVERSE_PRICE_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }

        $mode = strtolower((string) $this->option('mode'));
        if (! in_array($mode, ['daily', 'backfill'], true)) {
            $this->error('Invalid --mode. Use daily or backfill.');

            return self::FAILURE;
        }

        $scopeOption = $this->option('scope');
        try {
            $scope = $scopeOption !== null && $scopeOption !== ''
                ? $resolver->normalizeScope((string) $scopeOption)
                : $resolver->defaultScope();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $batchOption = $this->option('batch');
        $batchSize = is_numeric($batchOption) ? max(1, (int) $batchOption) : null;
        $processAll = (bool) $this->option('all');
        $resetCursor = (bool) $this->option('reset-cursor');

        $universeCount = $resolver->count($scope);
        $this->info(sprintf(
            'Universe price sync: mode=%s scope=%s universe=%d stocks process_all=%s',
            $mode,
            $scope,
            $universeCount,
            $processAll ? 'yes' : 'no',
        ));

        if ($universeCount === 0) {
            $this->warn('No stocks matched this scope. Run `php artisan stocks:sync` first.');
            if ($scope === UniverseStockResolverService::SCOPE_NIFTY500) {
                $this->warn('NIFTY 500 list may be empty if NSE constituent fetch failed.');
            }

            return self::FAILURE;
        }

        if ($mode === 'backfill' && ! $processAll) {
            $this->comment('Backfill runs in batches. Re-run until cycle completes, or use --all for a single long run.');
        }

        $stats = $sync->sync(
            mode: $mode,
            scope: $scope,
            batchSize: $batchSize,
            processAll: $processAll,
            resetCursor: $resetCursor,
        );

        $this->table(
            ['Metric', 'Value'],
            [
                ['Processed', $stats['processed']],
                ['Succeeded', $stats['succeeded']],
                ['Failed', $stats['failed']],
                ['Rows stored', $stats['stored_rows']],
                ['Cache hits', $stats['cache_hits']],
                ['Cursor stock id', $stats['cursor_stock_id']],
                ['Cycle completed', $stats['cycle_completed'] ? 'yes' : 'no'],
            ],
        );

        if ($stats['errors'] !== []) {
            $this->warn('Sample errors:');
            foreach (array_slice($stats['errors'], 0, 10) as $error) {
                $this->line('  - '.$error);
            }
        }

        return $stats['failed'] > 0 && $stats['succeeded'] === 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
