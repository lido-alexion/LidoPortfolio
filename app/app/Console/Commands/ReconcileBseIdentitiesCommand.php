<?php

namespace App\Console\Commands;

use App\Services\StockMasterSyncService;
use Illuminate\Console\Command;

class ReconcileBseIdentitiesCommand extends Command
{
    protected $signature = 'stocks:reconcile-bse-identities
        {--apply : Apply only the exact preview plan; otherwise remain read-only}
        {--expected-plan= : SHA-256 produced by a reviewed preview}
        {--json : Emit the complete deterministic plan and outcomes as JSON}';

    protected $description = 'Preview or auditably fill missing BSE ISINs without full master sync or price/lifecycle changes';

    public function handle(StockMasterSyncService $master): int
    {
        try {
            $result = $master->reconcileMissingBseIsins(
                (bool) $this->option('apply'),
                $this->option('expected-plan') ?: null,
            );
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf('%s BSE identities: proposed=%d applied=%d plan=%s',
                $result['dry_run'] ? 'Preview' : 'Reconciled',
                $result['candidate_count'], $result['applied_count'], $result['plan_sha256']));
            if (isset($result['audit_id'])) {
                $this->line('Durable audit: '.$result['audit_id']);
            }
            foreach ($result['reason_counts'] as $reason => $count) {
                $this->line($reason.': '.$count);
            }
        }

        return self::SUCCESS;
    }
}
