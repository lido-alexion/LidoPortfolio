<?php

namespace App\Console\Commands;

use App\Services\StockMasterSyncService;
use Illuminate\Console\Command;

class SyncStockMasterCommand extends Command
{
    protected $signature = 'stocks:sync';

    protected $description = 'Download and sync NSE/BSE equity master lists into portfolio_stocks';

    public function handle(StockMasterSyncService $sync): int
    {
        try {
            $stats = $sync->syncStockMaster();
        } catch (\Throwable $e) {
            $this->error('Stock master sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

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
