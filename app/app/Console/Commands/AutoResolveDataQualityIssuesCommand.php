<?php

namespace App\Console\Commands;

use App\Services\DataQualityResolutionService;
use Illuminate\Console\Command;

class AutoResolveDataQualityIssuesCommand extends Command
{
    protected $signature = 'portfolio:auto-resolve-data-quality-issues {--days=15 : Pending-day threshold for auto acceptance}';

    protected $description = 'Auto resolve stale pending Data Quality issues';

    public function handle(DataQualityResolutionService $resolution): int
    {
        $days = max(1, (int) $this->option('days'));
        $count = $resolution->autoAcceptStaleIssues($days);

        $this->info("Auto-resolved {$count} issue(s).");

        return self::SUCCESS;
    }
}
