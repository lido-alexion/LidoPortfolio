<?php

namespace App\Console\Commands;

use App\Engines\Pipeline\DailyDecisionPipeline;
use App\Models\PortfolioProfile;
use Illuminate\Console\Command;
use Throwable;

class RunDecisionPipelineCommand extends Command
{
    protected $signature = 'portfolio:decision-pipeline
                            {--profile= : Portfolio profile ID (default: all active profiles)}
                            {--notify=1 : Send Telegram notifications (1/0)}
                            {--review=1 : Generate review report (1/0)}';

    protected $description = 'Run the Trading OS daily decision pipeline (discovery → evaluation → recommendations)';

    public function handle(DailyDecisionPipeline $pipeline): int
    {
        if (! config('trading_os.enabled', true)) {
            $this->warn('Trading OS is disabled (TRADING_OS_ENABLED=false).');

            return self::SUCCESS;
        }

        @set_time_limit(0);

        $profileId = $this->option('profile');
        $notify = (string) $this->option('notify') !== '0';
        $review = (string) $this->option('review') !== '0';

        $query = PortfolioProfile::query()->orderBy('id');
        if ($profileId) {
            $query->where('id', (int) $profileId);
        }

        $profiles = $query->get();
        if ($profiles->isEmpty()) {
            $this->warn('No portfolios found.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($profiles as $profile) {
            $this->info("Pipeline for profile #{$profile->id} ({$profile->name})...");
            try {
                $result = $pipeline->run($profile, [
                    'notify' => $notify,
                    'review' => $review,
                ]);
                $stages = $result['stages'];
                $this->line(sprintf(
                    '  OK run=%d candidates=%s eval=%s recs=%s',
                    $result['pipeline_run']->id,
                    $stages['discovery']['candidates'] ?? '?',
                    $stages['evaluation']['results'] ?? '?',
                    $stages['recommendation']['count'] ?? '?',
                ));
            } catch (Throwable $e) {
                $failed++;
                $this->error('  Failed: '.$e->getMessage());
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
