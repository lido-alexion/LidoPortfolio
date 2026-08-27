<?php

namespace App\Console\Commands;

use App\Engines\Execution\LiveBrokerExecutionService;
use App\Models\PortfolioProfile;
use Illuminate\Console\Command;

class SubmitAutomaticBrokerOrdersCommand extends Command
{
    protected $signature = 'tos:submit-automatic-orders {--profile= : Portfolio profile ID}';

    protected $description = 'Submit eligible recommendations for Automatic-mode portfolios';

    public function handle(LiveBrokerExecutionService $live): int
    {
        $query = PortfolioProfile::query()
            ->where('execution_mode', PortfolioProfile::EXECUTION_MODE_AUTOMATIC);
        if ($id = $this->option('profile')) {
            $query->where('id', (int) $id);
        }

        $submitted = 0;
        foreach ($query->with('user')->get() as $profile) {
            $result = $live->submitAutomaticForProfile($profile);
            $submitted += (int) ($result['submitted'] ?? 0);
        }

        $this->info("Automatic broker submissions: {$submitted}.");

        return self::SUCCESS;
    }
}
