<?php

namespace App\Console\Commands;

use App\Services\AdminOperationalAlertService;
use App\Services\StockMasterSyncService;
use Illuminate\Console\Command;

class SyncStockMasterCommand extends Command
{
    protected $signature = 'stocks:sync';

    protected $description = 'Download and sync NSE/BSE equity master lists into portfolio_stocks';

    public function handle(StockMasterSyncService $sync): int
    {
        try {
            $backfill = (bool) config('portfolio.stock_master.backfill_new_symbols_on_cli_sync', true);
            $stats = $sync->syncStockMaster(backfillNewSymbols: $backfill);
        } catch (\Throwable $e) {
            $this->error('Stock master sync failed: '.$e->getMessage());
            app(AdminOperationalAlertService::class)->syncAndNotify();

            return self::FAILURE;
        }

        app(AdminOperationalAlertService::class)->syncAndNotify();

        $this->info(sprintf(
            'Stock master sync complete (%s): added=%d updated=%d deactivated=%d skipped=%d',
            $stats['source'],
            $stats['added'],
            $stats['updated'],
            $stats['deactivated'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
