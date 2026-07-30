<?php

namespace App\Console\Commands;

use App\Services\DataQualityCorporateActionHeuristicService;
use Illuminate\Console\Command;

class DetectCorporateActionAnomaliesCommand extends Command
{
    protected $signature = 'portfolio:detect-corporate-action-anomalies {--min-gap=25 : Minimum absolute overnight gap percent}';

    protected $description = 'Detect potential split/bonus anomalies from price discontinuities';

    public function handle(DataQualityCorporateActionHeuristicService $detector): int
    {
        $minGap = (float) $this->option('min-gap');
        $result = $detector->scanAllStocks($minGap);

        $this->info(sprintf(
            'Heuristic scan complete: scanned=%d, flagged=%d',
            $result['scanned'],
            $result['flagged'],
        ));

        return self::SUCCESS;
    }
}
