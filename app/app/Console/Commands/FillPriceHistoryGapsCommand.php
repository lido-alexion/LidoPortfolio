<?php

namespace App\Console\Commands;

use App\Services\PriceHistoryGapService;
use App\Services\UniverseStockResolverService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class FillPriceHistoryGapsCommand extends Command
{
    protected $signature = 'portfolio:fill-price-history-gaps
        {--scan-only : Detect gaps without calling providers}
        {--scope= : all_nse or nifty500 (default from config)}
        {--batch= : Stocks per run}
        {--reset-cursor : Start from the first stock in the universe}';

    protected $description = 'Scan and fill OHLCV gaps in local price history (universe + NIFTY50 benchmark)';

    public function handle(
        PriceHistoryGapService $gaps,
        UniverseStockResolverService $resolver,
    ): int {
        if (! $gaps->isEnabled()) {
            $this->warn('Universe price sync is disabled (UNIVERSE_PRICE_SYNC_ENABLED=false).');

            return self::SUCCESS;
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
        $resetCursor = (bool) $this->option('reset-cursor');
        $scanOnly = (bool) $this->option('scan-only');

        $this->info(sprintf(
            'Price history gap %s: scope=%s window=%d days',
            $scanOnly ? 'scan' : 'fill',
            $scope,
            $gaps->historyWindowDays(),
        ));

        $stats = $scanOnly
            ? $gaps->scanBatch($scope, $batchSize, $resetCursor)
            : $gaps->fillBatch($scope, $batchSize, $resetCursor);

        $rows = [
            ['Scanned', $stats['scanned'] ?? 0],
            ['With gaps', $stats['with_gaps'] ?? 0],
        ];

        if (! $scanOnly) {
            $rows[] = ['Filled', $stats['filled'] ?? 0];
            $rows[] = ['Failed', $stats['failed'] ?? 0];
            $rows[] = ['Rows stored', $stats['stored_rows'] ?? 0];
        }

        $rows[] = ['Cursor stock id', $stats['cursor_stock_id'] ?? 0];
        $rows[] = ['Cycle completed', ! empty($stats['cycle_completed']) ? 'yes' : 'no'];

        $this->table(['Metric', 'Value'], $rows);

        if (! empty($stats['symbols_with_gaps'])) {
            $this->warn('Sample symbols with gaps:');
            foreach (array_slice($stats['symbols_with_gaps'], 0, 10) as $row) {
                $this->line(sprintf(
                    '  - %s (%d range(s))',
                    $row['symbol'],
                    $row['gap_count'] ?? 0,
                ));
            }
        }

        if (! empty($stats['errors'])) {
            $this->warn('Sample errors:');
            foreach (array_slice($stats['errors'], 0, 10) as $error) {
                $this->line('  - '.$error);
            }
        }

        return (! $scanOnly && ($stats['failed'] ?? 0) > 0 && ($stats['filled'] ?? 0) === 0)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
