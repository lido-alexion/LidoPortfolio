<?php

namespace App\Console\Commands;

use App\Services\Execution\RecommendationExecutionLifetime;
use Illuminate\Console\Command;

class ExpireRecommendationExecutionWindowsCommand extends Command
{
    protected $signature = 'tos:expire-execution-windows';

    protected $description = 'Expire unresolved FEAT-039 recommendation gaps after their second trading-session cutoff';

    public function handle(RecommendationExecutionLifetime $lifetime): int
    {
        $count = $lifetime->expireDue();
        $this->info("Recommendation execution windows expired: {$count}.");

        return self::SUCCESS;
    }
}
