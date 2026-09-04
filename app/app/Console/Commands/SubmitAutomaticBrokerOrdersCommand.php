<?php

namespace App\Console\Commands;

use App\Engines\Execution\LiveBrokerExecutionService;
use App\Models\PortfolioProfile;
use App\Services\AdminOperationalAlertService;
use Illuminate\Console\Command;
use Throwable;

class SubmitAutomaticBrokerOrdersCommand extends Command
{
    protected $signature = 'tos:submit-automatic-orders {--profile= : Portfolio profile ID}';

    protected $description = 'Submit eligible recommendations for Automatic-mode portfolios';

    public function handle(LiveBrokerExecutionService $live, AdminOperationalAlertService $opsAlerts): int
    {
        $query = PortfolioProfile::query()
            ->where('execution_mode', PortfolioProfile::EXECUTION_MODE_AUTOMATIC);
        if ($id = $this->option('profile')) {
            $query->where('id', (int) $id);
        }

        $submitted = 0;
        $errors = [];
        $profiles = $query->with('user')->get();
        foreach ($profiles->groupBy('user_id') as $userProfiles) {
            $user = $userProfiles->first()?->user;
            if (! $user) {
                continue;
            }
            try {
                $result = $live->submitAutomaticForUser($user, $userProfiles);
                $submitted += (int) ($result['submitted'] ?? 0);
            } catch (Throwable $e) {
                $errors[] = sprintf('user #%d: %s', $user->id, $e->getMessage());
                $this->error(sprintf('Automatic submit failed for user #%d: %s', $user->id, $e->getMessage()));
            }
        }

        $this->info("Automatic broker submissions: {$submitted}.");

        if ($errors !== []) {
            $opsAlerts->recordUnattendedFailure(
                AdminOperationalAlertService::KEY_AUTOMATIC_SUBMIT_FAILED,
                'Automatic order submission failed',
                'Unattended Automatic submit failed. '.implode('; ', $errors),
                ['failed_profiles' => count($errors), 'submitted' => $submitted],
            );
            $opsAlerts->syncAndNotify();

            return self::FAILURE;
        }

        if ($opsAlerts->clearUnattendedFailure(AdminOperationalAlertService::KEY_AUTOMATIC_SUBMIT_FAILED)) {
            $opsAlerts->syncAndNotify();
        }

        return self::SUCCESS;
    }
}
