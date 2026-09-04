<?php

namespace App\Console\Commands;

use App\Services\Execution\RecommendationExecutionLifetime;
use App\Services\Execution\RecommendationExecutionNotificationService;
use Illuminate\Console\Command;

class ExpireRecommendationExecutionWindowsCommand extends Command
{
    protected $signature = 'tos:expire-execution-windows';

    protected $description = 'Expire unresolved FEAT-039 recommendation gaps after their second trading-session cutoff';

    public function handle(
        RecommendationExecutionLifetime $lifetime,
        RecommendationExecutionNotificationService $notifications,
    ): int {
        $warnings = $notifications->sendApproachingExpiry();
        $count = $lifetime->expireDue();
        $this->info("Recommendation execution warnings: {$warnings}; windows expired: {$count}.");

        return self::SUCCESS;
    }
}
