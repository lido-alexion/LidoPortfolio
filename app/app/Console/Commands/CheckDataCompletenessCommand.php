<?php

namespace App\Console\Commands;

use App\Services\AdminOperationalAlertService;
use App\Services\DataCompletenessService;
use App\Services\ForwardDataHealthService;
use Illuminate\Console\Command;

class CheckDataCompletenessCommand extends Command
{
    protected $signature = 'stox:check-data-completeness';
    protected $description = 'Report and alert on dataset freshness, coverage and backlog';

    public function handle(DataCompletenessService $completeness, ForwardDataHealthService $forwardHealth, AdminOperationalAlertService $alerts): int
    {
        $report = $completeness->report();
        $forward = $forwardHealth->report();
        $report['forward_data'] = $forward;
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        if (! $completeness->isComplete($report) || $forward['alerts'] !== []) {
            $alerts->recordUnattendedFailure(AdminOperationalAlertService::KEY_DATA_COMPLETENESS, 'StoX data completeness incomplete', 'One or more daily ML readiness datasets has a missing session or incomplete eligible-universe coverage.', $report);
            $alerts->syncAndNotify();
            return self::FAILURE;
        }
        if ($alerts->clearUnattendedFailure(AdminOperationalAlertService::KEY_DATA_COMPLETENESS)) $alerts->syncAndNotify();
        return self::SUCCESS;
    }
}
