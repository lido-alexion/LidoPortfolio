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
        $refreshed = $lifetime->refreshFutureWindows();
        $warnings = $notifications->sendApproachingExpiry();
        $count = $lifetime->expireDue();
        $this->info("Execution windows refreshed: {$refreshed}; warnings: {$warnings}; expired: {$count}.");

        return self::SUCCESS;
    }
}
