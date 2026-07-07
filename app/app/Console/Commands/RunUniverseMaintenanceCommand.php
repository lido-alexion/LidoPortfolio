<?php

namespace App\Console\Commands;

use App\Services\AdminOperationalAlertService;
use App\Services\PriceHistoryGapService;
use App\Services\SettingsService;
use App\Services\UniversePriceSyncService;
use App\Services\UniverseStockResolverService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RunUniverseMaintenanceCommand extends Command
{
    protected $signature = 'portfolio:run-universe-maintenance
        {--scope= : all_equities, all_nse (deprecated), or nifty500 (default from config)}
        {--batch= : Stocks per run}
        {--reset-cursor : Start from first stock in universe}
        {--skip-gap-fill : Skip nightly gap scan/fill batch}
        {--gap-retries= : Extra gap-fill batches after daily failures (default from config)}';

    protected $description = 'Run universe daily sync plus nightly gap-fill batch (cursor chains across the maintenance window)';

    public function handle(
        UniversePriceSyncService $sync,
        PriceHistoryGapService $gapService,
        UniverseStockResolverService $resolver,
        SettingsService $settings,
    ): int {
        if (! $sync->isEnabled()) {
            $this->warn('Universe price sync is disabled (UNIVERSE_PRICE_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }

        $cronTimezone = $settings->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';
        $nowInCronTz = now()->timezone($cronTimezone);
        $this->line(sprintf(
            'Scheduler timezone: %s | local time: %s',
            $cronTimezone,
            $nowInCronTz->format('Y-m-d H:i:s T'),
        ));

        $this->line(sprintf(
            'Maintenance window due: %s | window start: %s',
            $sync->isMaintenanceWindowDue() ? 'yes' : 'no',
            $sync->isMaintenanceWindowStart() ? 'yes' : 'no',
        ));

        if ($sync->isSyncInProgress()) {
            $this->warn('Skipping — a universe batch is already in progress.');

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

        $this->info(sprintf(
            'Universe maintenance: scope=%s batch=%s gap_fill=%s',
            $scope,
            $batchSize ?? 'default',
            $this->shouldRunGapFill() && ! $this->option('skip-gap-fill') ? 'yes' : 'no',
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
                ['Cycle completed', ! empty($daily['cycle_completed']) ? 'yes' : 'no'],
            ],
        );

        $exitCode = ($daily['failed'] ?? 0) > 0 && ($daily['succeeded'] ?? 0) === 0
            ? self::FAILURE
            : self::SUCCESS;

        if ($this->shouldRunGapFill() && ! $this->option('skip-gap-fill')) {
            $resetGapCursor = $sync->isMaintenanceWindowStart();
            $gapResult = $gapService->fillBatch(
                scope: $scope,
                batchSize: $batchSize,
                resetCursor: $resetGapCursor,
            );

            $this->table(
                ['Gap fill batch metric', 'Value'],
                [
                    ['Scanned', $gapResult['scanned'] ?? 0],
                    ['With gaps', $gapResult['with_gaps'] ?? 0],
                    ['Filled', $gapResult['filled'] ?? 0],
                    ['Failed', $gapResult['failed'] ?? 0],
                    ['Rows stored', $gapResult['stored_rows'] ?? 0],
                    ['Cursor stock id', $gapResult['cursor_stock_id'] ?? 0],
                    ['Cycle completed', ! empty($gapResult['cycle_completed']) ? 'yes' : 'no'],
                    ['Reset cursor', $resetGapCursor ? 'yes' : 'no'],
                ],
            );

            if (($gapResult['failed'] ?? 0) > 0 && ($gapResult['filled'] ?? 0) === 0) {
                $exitCode = self::FAILURE;
            }
        }

        if (($daily['failed'] ?? 0) > 0 && $gapRetries > 0) {
            $this->warn(sprintf(
                'Daily batch had %d failure(s). Running %d extra gap fill batch(es).',
                $daily['failed'],
                $gapRetries,
            ));

            $gapWaitSeconds = (int) config('portfolio.universe_price_sync.gap_fill_wait_seconds', 20);
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
                    'Extra gap fill #%d -> scanned=%d with_gaps=%d filled=%d failed=%d stored_rows=%d',
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
                $exitCode = self::FAILURE;
            }
        }

        app(AdminOperationalAlertService::class)->syncAndNotify();

        return $exitCode;
    }

    protected function shouldRunGapFill(): bool
    {
        return (bool) config('portfolio.universe_price_sync.maintenance_gap_fill_enabled', true);
    }
}
