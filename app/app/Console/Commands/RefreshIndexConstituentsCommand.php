<?php

namespace App\Console\Commands;

use App\Services\IndexConstituentService;
use App\Services\PortfolioLoggerService;
use Illuminate\Console\Command;

class RefreshIndexConstituentsCommand extends Command
{
    protected $signature = 'portfolio:refresh-index-constituents
        {--symbol= : Refresh a single NSE index symbol (e.g. NIFTYBANK)}';

    protected $description = 'Refresh cached NSE index constituent lists (broad + sector) from archives CSV / API';

    public function handle(
        IndexConstituentService $constituents,
        PortfolioLoggerService $logger,
    ): int {
        $symbol = strtoupper(trim((string) $this->option('symbol')));

        if ($symbol !== '') {
            $rows = $constituents->constituentsForSymbol($symbol, forceRefresh: true);
            $count = count($rows);
            if ($count === 0) {
                $this->error("Failed to refresh constituents for {$symbol}.");
                $logger->scheduler('warning', 'Index constituent refresh failed for symbol', [
                    'category' => 'IndexConstituents',
                    'index_symbol' => $symbol,
                ]);

                return self::FAILURE;
            }

            $this->info("Refreshed {$symbol}: {$count} symbols.");
            $logger->scheduler('info', 'Index constituent refresh (single)', [
                'category' => 'IndexConstituents',
                'index_symbol' => $symbol,
                'symbol_count' => $count,
            ]);

            return self::SUCCESS;
        }

        $result = $constituents->refreshSupportedCaches();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Refreshed', $result['refreshed']],
                ['Failed', $result['failed']],
            ],
        );

        $logger->scheduler(
            $result['failed'] > 0 ? 'warning' : 'info',
            'Index constituent refresh complete',
            [
                'category' => 'IndexConstituents',
                'refreshed' => $result['refreshed'],
                'failed' => $result['failed'],
            ],
        );

        if ($result['refreshed'] === 0 && $result['failed'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
