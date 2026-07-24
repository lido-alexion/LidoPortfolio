<?php

namespace App\Console\Commands;

use App\Services\HistoryDepthBackfillService;
use Illuminate\Console\Command;

class BackfillHistoryDepthCommand extends Command
{
    protected $signature = 'portfolio:backfill-history-depth
        {--batch= : Stocks per run (default from config)}
        {--reset : Restart the campaign from the first stock}
        {--status : Print campaign status without running a batch}';

    protected $description = 'Deepen stored OHLCV history to the configured target (default ~18 months) so 1-year backtests have full indicator lookback';

    public function handle(HistoryDepthBackfillService $service): int
    {
        if ($this->option('status')) {
            $this->printStatus($service->status());

            return self::SUCCESS;
        }

        if (! $service->isEnabled()) {
            $this->warn('History depth backfill is disabled (HISTORY_DEPTH_BACKFILL_ENABLED=false).');

            return self::SUCCESS;
        }

        $batchOption = $this->option('batch');
        $batchSize = is_numeric($batchOption) ? max(1, (int) $batchOption) : null;

        $stats = $service->runBatch($batchSize, resetCampaign: (bool) $this->option('reset'));

        if (($stats['skipped'] ?? false) === true) {
            $this->info('Skipped: '.($stats['reason'] ?? 'unknown'));
            if (($stats['reason'] ?? '') === 'completed') {
                $this->comment('Campaign already complete for the configured target. Use --reset or raise HISTORY_DEPTH_TARGET_DAYS to re-arm.');
            }

            return self::SUCCESS;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Target history days', $stats['target_history_days']],
                ['Window', $stats['required_from'].' → '.$stats['required_to']],
                ['Indexes processed', $stats['indexes_processed']],
                ['Processed', $stats['processed']],
                ['Deepened', $stats['deepened']],
                ['Already deep', $stats['already_deep']],
                ['Failed', $stats['failed']],
                ['Rows stored', $stats['stored_rows']],
                ['Cursor stock id', $stats['cursor_stock_id']],
                ['Campaign complete', $stats['cycle_completed'] ? 'yes' : 'no'],
            ],
        );

        if ($stats['errors'] !== []) {
            $this->warn('Sample errors:');
            foreach (array_slice($stats['errors'], 0, 10) as $error) {
                $this->line('  - '.$error);
            }
        }

        return $stats['failed'] > 0 && $stats['deepened'] === 0 && $stats['already_deep'] === 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $status
     */
    protected function printStatus(array $status): void
    {
        $progress = $status['progress'] ?? [];
        $this->table(
            ['Field', 'Value'],
            [
                ['Enabled', $status['enabled'] ? 'yes' : 'no'],
                ['Target history days', $status['target_history_days']],
                ['Completed at', $status['completed_at'] ?? '—'],
                ['Completed target days', $status['completed_target_days'] ?: '—'],
                ['Indexes done at', $status['indexes_done_at'] ?? '—'],
                ['Cursor stock id', $status['cursor_stock_id']],
                ['Universe progress', $status['processed_through'].' / '.$status['universe_total']],
                ['Total processed', $progress['processed'] ?? 0],
                ['Total deepened', $progress['deepened'] ?? 0],
                ['Total already deep', $progress['already_deep'] ?? 0],
                ['Total failed', $progress['failed'] ?? 0],
                ['Total rows stored', $progress['stored_rows'] ?? 0],
                ['Run in progress', $status['in_progress'] ? 'yes' : 'no'],
                ['Due (scheduler gate)', $status['due'] ? 'yes' : 'no'],
            ],
        );
    }
}
