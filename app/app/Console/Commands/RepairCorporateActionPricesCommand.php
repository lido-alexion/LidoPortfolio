<?php

namespace App\Console\Commands;

use App\Services\CorporateActionPriceRepairService;
use Illuminate\Console\Command;

class RepairCorporateActionPricesCommand extends Command
{
    protected $signature = 'portfolio:repair-corporate-action-prices
        {--profile= : Limit to a portfolio profile id}
        {--stock= : Limit to a stock id}
        {--action= : Limit to a corporate action id}
        {--apply : Write repairs (default is dry-run scan only)}
        {--force : Repair ambiguous cases when applying}';

    protected $description = 'Detect and repair OHLCV rows not restated after split/bonus corporate actions';

    public function handle(CorporateActionPriceRepairService $repair): int
    {
        $profileId = $this->nullableIntOption('profile');
        $stockId = $this->nullableIntOption('stock');
        $actionId = $this->nullableIntOption('action');
        $dryRun = ! $this->option('apply');
        $force = (bool) $this->option('force');

        if ($dryRun) {
            $findings = $repair->scan($profileId, $stockId, $actionId);
            $this->info(sprintf('Scanned %d applied corporate action(s).', count($findings)));

            if ($findings === []) {
                return self::SUCCESS;
            }

            $this->table(
                ['ID', 'Symbol', 'Type', 'Ex-date', 'Status', 'Message'],
                collect($findings)->map(fn (array $row) => [
                    $row['corporate_action_id'],
                    $row['symbol'] ?? '—',
                    $row['action_type'].' '.$row['ratio'],
                    $row['ex_date'],
                    $row['status'],
                    $row['message'],
                ])->all(),
            );

            $needsRepair = collect($findings)->whereIn('status', [
                CorporateActionPriceRepairService::STATUS_MISSING_METADATA,
                CorporateActionPriceRepairService::STATUS_SUSPECTED_UNADJUSTED,
                CorporateActionPriceRepairService::STATUS_SUSPECTED_ALREADY_ADJUSTED,
            ])->count();

            if ($needsRepair > 0) {
                $this->warn("{$needsRepair} action(s) may need repair. Re-run with --apply to fix.");
            } else {
                $this->info('No repair candidates found.');
            }

            return self::SUCCESS;
        }

        $result = $repair->repair($profileId, $stockId, $actionId, dryRun: false, force: $force);
        $this->info(sprintf(
            'Repair complete: scanned %d, repaired %d, skipped %d.',
            $result['scanned'],
            $result['repaired'],
            $result['skipped'],
        ));

        foreach ($result['details'] as $detail) {
            $this->line(sprintf(
                '  #%d %s (%s)',
                $detail['corporate_action_id'],
                $detail['action'],
                $detail['status'] ?? '',
            ));
        }

        return self::SUCCESS;
    }

    protected function nullableIntOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
