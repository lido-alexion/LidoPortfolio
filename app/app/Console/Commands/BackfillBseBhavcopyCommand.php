<?php

namespace App\Console\Commands;

use App\Services\BseBhavcopyBackfillService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackfillBseBhavcopyCommand extends Command
{
    protected $signature = 'portfolio:backfill-bse-bhavcopy
        {--from= : Start date YYYY-MM-DD (default: history_days before --to)}
        {--to= : End date YYYY-MM-DD (default: last required session)}
        {--days= : Max trading days to process in this run}
        {--sync-scrip-codes : Refresh bse_scrip_code from BSE master before backfill}
        {--dry-run : Download and count matches without writing OHLCV}';

    protected $description = 'Backfill OHLCV for BSE-only stocks from BSE UDiFF bhavcopy (one file per session day)';

    public function handle(BseBhavcopyBackfillService $backfill): int
    {
        $fromOption = $this->option('from');
        $toOption = $this->option('to');
        $daysOption = $this->option('days');

        $from = ($fromOption !== null && $fromOption !== '')
            ? Carbon::parse((string) $fromOption)->startOfDay()
            : null;
        $to = ($toOption !== null && $toOption !== '')
            ? Carbon::parse((string) $toOption)->startOfDay()
            : null;
        $maxDays = is_numeric($daysOption) ? max(1, (int) $daysOption) : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('sync-scrip-codes')) {
            $this->info('Syncing BSE scrip codes from master…');
            $scripStats = $backfill->syncScripCodesFromMaster();
            $this->table(['Metric', 'Value'], [
                ['Updated rows', $scripStats['updated']],
                ['Master rows missing symbol', $scripStats['missing_symbol']],
                ['Master rows missing scrip code', $scripStats['missing_code']],
            ]);
        }

        $this->info(sprintf(
            'BSE bhavcopy backfill (%s)%s',
            $dryRun ? 'dry run' : 'apply',
            $maxDays !== null ? " max_days={$maxDays}" : '',
        ));

        try {
            $stats = $backfill->backfill($from, $to, $maxDays, $dryRun);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Metric', 'Value'], [
            ['BSE-only stocks', $stats['stocks']],
            ['From', $stats['from_date']],
            ['To', $stats['to_date']],
            ['Trading days processed', $stats['days_processed']],
            ['Trading days skipped', $stats['days_skipped']],
            ['Rows matched', $stats['rows_matched']],
            ['Rows stored', $stats['rows_stored']],
        ]);

        if ($stats['errors'] !== []) {
            $this->warn('Sample errors:');
            foreach (array_slice($stats['errors'], 0, 10) as $error) {
                $this->line('  - '.$error);
            }
        }

        return self::SUCCESS;
    }
}
