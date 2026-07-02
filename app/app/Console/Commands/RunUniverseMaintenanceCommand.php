<?php

namespace App\Console\Commands;

use App\Services\AdminOperationalAlertService;
use App\Services\PriceHistoryGapService;
use App\Services\UniversePriceSyncService;
use App\Services\UniverseStockResolverService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RunUniverseMaintenanceCommand extends Command
{
    protected $signature = 'portfolio:run-universe-maintenance
        {--scope= : all_nse or nifty500 (default from config)}
        {--batch= : Stocks per run}
        {--reset-cursor : Start from first stock in universe}
        {--gap-retries=2 : Number of gap-fill retries when daily batch has failures}
        {--gap-wait-seconds= : Wait between gap-fill retries (default from config)}';

    protected $description = 'Run universe daily sync and auto-trigger gap fill retries on failures';

    public function handle(
        UniversePriceSyncService $sync,
        PriceHistoryGapService $gapService,
        UniverseStockResolverService $resolver,
    ): int {
        if (! $sync->isEnabled()) {
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
        $gapRetriesOption = $this->option('gap-retries');
        $gapRetries = is_numeric($gapRetriesOption)
            ? max(0, (int) $gapRetriesOption)
            : (int) config('portfolio.universe_price_sync.maintenance_gap_fill_retries', 2);
        $gapWaitSeconds = $this->option('gap-wait-seconds');
        $gapWaitSeconds = is_numeric($gapWaitSeconds)
            ? max(0, (int) $gapWaitSeconds)
            : (int) config('portfolio.universe_price_sync.gap_fill_wait_seconds', 20);

        $this->info(sprintf(
            'Universe maintenance: scope=%s batch=%s gap_retries=%d gap_wait=%ds',
            $scope,
            $batchSize ?? 'default',
            $gapRetries,
            $gapWaitSeconds,
        ));

        $daily = $sync->sync(
            mode: 'daily',
            scope: $scope,
            batchSize: $batchSize,
            processAll: false,
            resetCursor: $resetCursor,
        );

        $this->table(
            ['Daily batch metric', 'Value'],
            [
                ['Processed', $daily['processed']],
                ['Succeeded', $daily['succeeded']],
                ['Failed', $daily['failed']],
                ['Rows stored', $daily['stored_rows']],
                ['Cursor stock id', $daily['cursor_stock_id']],
            ],
        );

        if (($daily['failed'] ?? 0) <= 0 || $gapRetries === 0) {
            app(AdminOperationalAlertService::class)->syncAndNotify();

            return $daily['failed'] > 0 && $daily['succeeded'] === 0
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->warn(sprintf(
            'Daily batch had %d failure(s). Running gap fill %d time(s).',
            $daily['failed'],
            $gapRetries,
        ));

        $filledAny = false;
        for ($i = 1; $i <= $gapRetries; $i++) {
            if ($i > 1 && $gapWaitSeconds > 0) {
                sleep($gapWaitSeconds);
            }

            $result = $gapService->fillBatch(
                scope: $scope,
                batchSize: $batchSize,
                resetCursor: false,
            );

            $this->line(sprintf(
                'Gap fill #%d -> scanned=%d with_gaps=%d filled=%d failed=%d stored_rows=%d',
                $i,
                $result['scanned'] ?? 0,
                $result['with_gaps'] ?? 0,
                $result['filled'] ?? 0,
                $result['failed'] ?? 0,
                $result['stored_rows'] ?? 0,
            ));

            if (($result['filled'] ?? 0) > 0) {
                $filledAny = true;
            }
        }

        if ($daily['failed'] > 0 && $daily['succeeded'] === 0 && ! $filledAny) {
            app(AdminOperationalAlertService::class)->syncAndNotify();

            return self::FAILURE;
        }

        app(AdminOperationalAlertService::class)->syncAndNotify();

        return self::SUCCESS;
    }
}
