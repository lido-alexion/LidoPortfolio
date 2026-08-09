<?php

namespace App\Console\Commands;

use App\Services\DataQualityResolutionService;
use Illuminate\Console\Command;

class AutoResolveDataQualityIssuesCommand extends Command
{
    protected $signature = 'portfolio:auto-resolve-data-quality-issues {--days= : Pending-day threshold (defaults to config data_quality.auto_accept_days)}';

    protected $description = 'Auto resolve eligible exchange-feed Data Quality issues after the configured pending window';

    public function handle(DataQualityResolutionService $resolution): int
    {
        $daysOption = $this->option('days');
        $days = $daysOption !== null && $daysOption !== '' ? max(1, (int) $daysOption) : null;
        $count = $resolution->autoAcceptStaleIssues($days);

        $threshold = $days ?? (int) config('services.data_quality.auto_accept_days', 15);
        $this->info("Auto-resolved {$count} eligible exchange-feed issue(s) (threshold={$threshold} days).");

        return self::SUCCESS;
    }
}
