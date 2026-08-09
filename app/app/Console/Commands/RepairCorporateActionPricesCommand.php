<?php

namespace App\Console\Commands;

use App\Services\CorporateActionPriceRepairService;
use Illuminate\Console\Command;

class RepairCorporateActionPricesCommand extends Command
{
    protected $signature = 'portfolio:repair-corporate-action-prices
        {--profile= : Limit F020 corporate-action path to a portfolio profile id}
        {--stock= : Limit to a stock id}
        {--action= : Limit F020 path to a corporate action id}
        {--factor= : Limit F042 factor path to a price adjustment factor id}
        {--factors-only : Process only F042 pending PriceAdjustmentFactor repairs}
        {--ca-only : Process only F020 applied CorporateAction repairs}
        {--apply : Write repairs (default is dry-run scan only)}
        {--force : Repair ambiguous F020 cases when applying}';

    protected $description = 'Repair OHLCV via F042 pending PriceAdjustmentFactors and/or F020 applied corporate actions';

    public function handle(CorporateActionPriceRepairService $repair): int
    {
        $profileId = $this->nullableIntOption('profile');
        $stockId = $this->nullableIntOption('stock');
        $actionId = $this->nullableIntOption('action');
        $factorId = $this->nullableIntOption('factor');
        $factorsOnly = (bool) $this->option('factors-only');
        $caOnly = (bool) $this->option('ca-only');
        $dryRun = ! $this->option('apply');
        $force = (bool) $this->option('force');

        if ($factorsOnly && $caOnly) {
            $this->error('Use only one of --factors-only or --ca-only.');

            return self::FAILURE;
        }

        $runFactors = ! $caOnly;
        $runCa = ! $factorsOnly;

        if ($dryRun) {
            if ($runFactors) {
                $factorFindings = $repair->scanPendingFactors($stockId, $factorId);
                $this->info(sprintf('Scanned %d F042 price adjustment factor(s).', count($factorFindings)));
                if ($factorFindings !== []) {
                    $this->table(
                        ['Factor', 'Issue', 'Symbol', 'Type', 'Ex-date', 'Status', 'Rows', 'Message'],
                        collect($factorFindings)->map(fn (array $row) => [
                            $row['factor_id'],
                            $row['issue_id'] ?? '—',
                            $row['symbol'] ?? '—',
                            $row['action_type'] ?? '—',
                            $row['ex_date'] ?? '—',
                            $row['status'],
                            $row['rows_to_adjust'] ?? 0,
                            $row['message'],
                        ])->all(),
                    );
                }
            }

            if ($runCa) {
                $findings = $repair->scan($profileId, $stockId, $actionId);
                $this->info(sprintf('Scanned %d applied corporate action(s).', count($findings)));

                if ($findings !== []) {
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
                }

                $needsRepair = collect($findings)->whereIn('status', [
                    CorporateActionPriceRepairService::STATUS_MISSING_METADATA,
                    CorporateActionPriceRepairService::STATUS_SUSPECTED_UNADJUSTED,
                    CorporateActionPriceRepairService::STATUS_SUSPECTED_ALREADY_ADJUSTED,
                ])->count();

                if ($needsRepair > 0) {
                    $this->warn("{$needsRepair} F020 action(s) may need repair. Re-run with --apply to fix.");
                } elseif ($runCa) {
                    $this->info('No F020 repair candidates found.');
                }
            }

            return self::SUCCESS;
        }

        if ($runFactors) {
            $factorResult = $repair->repairPendingFactors(
                stockId: $stockId,
                factorId: $factorId,
                dryRun: false,
                repairSource: 'portfolio:repair-corporate-action-prices',
            );
            $this->info(sprintf(
                'F042 factor repair: scanned %d, repaired %d, skipped %d.',
                $factorResult['scanned'],
                $factorResult['repaired'],
                $factorResult['skipped'],
            ));
            foreach ($factorResult['details'] as $detail) {
                $this->line(sprintf(
                    '  factor #%d %s (%s)',
                    $detail['factor_id'],
                    $detail['action'],
                    $detail['status'] ?? '',
                ));
            }
        }

        if ($runCa) {
            $result = $repair->repair($profileId, $stockId, $actionId, dryRun: false, force: $force);
            $this->info(sprintf(
                'F020 CA repair: scanned %d, repaired %d, skipped %d.',
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
