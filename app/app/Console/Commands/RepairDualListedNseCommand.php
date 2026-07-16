<?php

namespace App\Console\Commands;

use App\Services\DualListedNseRepairService;
use Illuminate\Console\Command;

class RepairDualListedNseCommand extends Command
{
    protected $signature = 'portfolio:repair-dual-listed-nse
                            {--dry-run : Report actions without writing}
                            {--no-backfill : Purge BSE duplicates only; skip NSE history refill}
                            {--backfill-only : Refill NSE history for paired symbols only (batched cursor)}
                            {--reset-cursor : Reset NSE backfill cursor before --backfill-only}
                            {--max-backfill= : Cap NSE symbols to backfill after purge}';

    protected $description = 'Deactivate dual-listed BSE duplicates, purge BSE OHLCV, repoint references, and refill NSE prices';

    public function handle(DualListedNseRepairService $repair): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $backfill = ! (bool) $this->option('no-backfill');
        $backfillOnly = (bool) $this->option('backfill-only');
        $resetCursor = (bool) $this->option('reset-cursor');
        $maxBackfill = $this->option('max-backfill');
        $maxBackfill = is_numeric($maxBackfill) ? max(0, (int) $maxBackfill) : null;

        if ($backfillOnly) {
            $stats = $repair->backfillPairedNseHistory($maxBackfill ?? 25, $resetCursor);
            $this->info('Dual-listed NSE backfill only');
        } else {
            $stats = $repair->repair($dryRun, $backfill, $maxBackfill);
            $this->info(($dryRun ? 'Dry run — ' : '').'Dual-listed NSE repair');
        }
        $this->table(
            ['Metric', 'Value'],
            collect($stats)
                ->except('errors')
                ->map(fn ($value, $key) => [$key, (string) $value])
                ->values()
                ->all(),
        );

        if ($stats['errors'] !== []) {
            $this->warn('Errors:');
            foreach ($stats['errors'] as $error) {
                $this->line('  - '.$error);
            }
        }

        return self::SUCCESS;
    }
}
