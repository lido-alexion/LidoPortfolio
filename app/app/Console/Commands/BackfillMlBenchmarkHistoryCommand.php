<?php

namespace App\Console\Commands;

use App\Services\ML\PrimaryMlBenchmarkHistoryBackfillService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackfillMlBenchmarkHistoryCommand extends Command
{
    protected $signature = 'portfolio:backfill-ml-benchmark-history
        {--from= : Requested start date YYYY-MM-DD (default: policy-derived)}
        {--to= : Requested end date YYYY-MM-DD (default: last required session)}
        {--dry-run : Print the policy/range without fetching}
        {--status : Print the last stored campaign report without fetching}';

    protected $description = 'Deepen only the primary ML benchmark history to the V7 horizon-aware readiness window.';

    public function handle(PrimaryMlBenchmarkHistoryBackfillService $service): int
    {
        if ($this->option('status')) {
            $this->line((string) (\App\Models\Setting::getValue(PrimaryMlBenchmarkHistoryBackfillService::KEY_LAST_RUN_JSON, 'No run recorded.')));

            return self::SUCCESS;
        }

        try {
            $from = $this->dateOption('from');
            $to = $this->dateOption('to');
            $report = $service->run($to, $from, (bool) $this->option('dry-run'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Symbol', $report['symbol']],
                ['Requested range', $report['requested_range']['from'].' → '.$report['requested_range']['to']],
                ['Policy', $report['policy']['version']],
                ['Required months', $report['policy']['required_calendar_months']],
                ['Stored range', ($report['stored_range']['from'] ?? '—').' → '.($report['stored_range']['to'] ?? '—')],
                ['Stored rows', $report['stored_range']['rows']],
                ['Dry run', $report['dry_run'] ? 'yes' : 'no'],
                ['Success', array_key_exists('success', $report) ? ($report['success'] ? 'yes' : 'no') : 'not-run'],
            ],
        );

        if (($report['success'] ?? true) === false) {
            $this->error('Provider did not satisfy the requested primary benchmark range. No synthetic prices were created.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function dateOption(string $name): ?Carbon
    {
        $value = trim((string) ($this->option($name) ?? ''));
        if ($value === '') {
            return null;
        }

        return Carbon::createFromFormat('!Y-m-d', $value)->startOfDay();
    }
}
