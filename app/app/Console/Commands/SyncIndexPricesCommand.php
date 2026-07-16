<?php

namespace App\Console\Commands;

use App\Services\IndexPriceSyncService;
use Illuminate\Console\Command;

class SyncIndexPricesCommand extends Command
{
    protected $signature = 'portfolio:sync-index-prices
        {--mode=daily : daily (incremental) or backfill (full history window)}
        {--batch= : Indexes per run when not using --all}
        {--all : Process entire index catalog in one run}
        {--reset-cursor : Start from the first configured index}
        {--symbol= : Sync a single index symbol only}
        {--fill-gaps : Fill gaps for indexes with missing ranges (batched)}';

    protected $description = 'Sync OHLCV for configured market indexes (is_benchmark rows)';

    public function handle(IndexPriceSyncService $sync): int
    {
        if (! $sync->isEnabled()) {
            $this->warn('Index price sync is disabled (INDEX_PRICE_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }

        if ($this->option('fill-gaps')) {
            $batchOption = $this->option('batch');
            $batchSize = is_numeric($batchOption) ? max(1, (int) $batchOption) : null;
            $stats = $sync->fillGapsBatch(
                batchSize: $batchSize,
                resetCursor: (bool) $this->option('reset-cursor'),
            );
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Mode', $stats['mode'] ?? 'gap_fill'],
                    ['With gaps', $stats['with_gaps'] ?? 0],
                    ['Processed', $stats['processed'] ?? 0],
                    ['Succeeded', $stats['succeeded'] ?? 0],
                    ['Failed', $stats['failed'] ?? 0],
                    ['Rows stored', $stats['stored_rows'] ?? 0],
                    ['Completed', ! empty($stats['completed']) ? 'yes' : 'no'],
                ],
            );

            return (($stats['failed'] ?? 0) > 0 && ($stats['succeeded'] ?? 0) === 0)
                ? self::FAILURE
                : self::SUCCESS;
        }

        $symbol = trim((string) ($this->option('symbol') ?? ''));
        if ($symbol !== '') {
            $mode = strtolower((string) $this->option('mode'));
            if (! in_array($mode, ['daily', 'backfill'], true)) {
                $this->error('Invalid --mode. Use daily or backfill.');

                return self::FAILURE;
            }
            $result = $sync->syncOneSymbol($symbol, $mode);
            if ($result['success']) {
                $this->info(sprintf(
                    '%s sync OK (%d rows stored, %s).',
                    strtoupper($symbol),
                    $result['stored_rows'],
                    $result['full_history'] ? 'full history' : 'incremental',
                ));

                return self::SUCCESS;
            }
            $this->error(strtoupper($symbol).' sync failed: '.implode('; ', $result['errors']));

            return self::FAILURE;
        }

        $mode = strtolower((string) $this->option('mode'));
        if (! in_array($mode, ['daily', 'backfill'], true)) {
            $this->error('Invalid --mode. Use daily or backfill.');

            return self::FAILURE;
        }

        $batchOption = $this->option('batch');
        $batchSize = is_numeric($batchOption) ? max(1, (int) $batchOption) : null;
        $processAll = (bool) $this->option('all');
        $resetCursor = (bool) $this->option('reset-cursor');

        $this->info(sprintf(
            'Index price sync: mode=%s process_all=%s',
            $mode,
            $processAll ? 'yes' : 'no',
        ));

        $stats = $sync->syncBatch(
            mode: $mode,
            batchSize: $batchSize,
            resetCursor: $resetCursor,
            processAll: $processAll,
        );

        $this->table(
            ['Metric', 'Value'],
            [
                ['Processed', $stats['processed'] ?? 0],
                ['Succeeded', $stats['succeeded'] ?? 0],
                ['Failed', $stats['failed'] ?? 0],
                ['Rows stored', $stats['stored_rows'] ?? 0],
                ['Cursor after', $stats['cursor_after'] ?? '—'],
                ['Cycle completed', ! empty($stats['cycle_completed']) ? 'yes' : 'no'],
                ['Reason', $stats['reason'] ?? ''],
            ],
        );

        if (($stats['errors'] ?? []) !== []) {
            $this->warn('Sample errors:');
            foreach (array_slice($stats['errors'], 0, 10) as $error) {
                $this->line('  - '.$error);
            }
        }

        return (($stats['failed'] ?? 0) > 0 && ($stats['succeeded'] ?? 0) === 0)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
