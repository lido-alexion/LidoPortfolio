<?php

namespace App\Console\Commands;

use App\Engines\Pipeline\DailyDecisionPipeline;
use App\Models\PortfolioProfile;
use App\Services\DecisionPipelineScheduleService;
use App\Services\PortfolioLoggerService;
use App\Support\TradingOsConfig;
use Illuminate\Console\Command;
use Throwable;

class RunDecisionPipelineCommand extends Command
{
    protected $signature = 'portfolio:decision-pipeline
                            {--profile= : Portfolio profile ID (default: all active profiles)}
                            {--notify=1 : Send Telegram notifications (1/0)}
                            {--review=1 : Generate review report (1/0)}
                            {--trigger=manual : Trigger source: manual, scheduled, post-sync}
                            {--force : Run even if an automatic run already completed today}';

    protected $description = 'Run the Trading OS daily decision pipeline (discovery → evaluation → recommendations)';

    public function handle(
        DailyDecisionPipeline $pipeline,
        DecisionPipelineScheduleService $scheduleGuard,
        PortfolioLoggerService $logger,
    ): int {
        if (! TradingOsConfig::enabled()) {
            $this->warn('Trading OS is disabled (TRADING_OS_ENABLED=false).');

            return self::SUCCESS;
        }

        @set_time_limit(0);

        $trigger = (string) $this->option('trigger');
        if (! in_array($trigger, ['manual', 'scheduled', 'post-sync'], true)) {
            $trigger = 'manual';
        }
        $force = (bool) $this->option('force');
        $isAutomatic = in_array($trigger, ['scheduled', 'post-sync'], true);

        $lock = null;
        if ($isAutomatic) {
            $lock = $scheduleGuard->acquireAutomaticLock();
            if ($lock === null) {
                $this->info('Decision pipeline skipped (another automatic execution is in progress).');
                $logger->scheduler('info', 'Decision pipeline skipped (automatic lock held)', [
                    'event' => 'pipeline.command_skipped',
                    'trigger' => $trigger,
                    'reason' => 'automatic_lock_held',
                ]);

                return self::SUCCESS;
            }
        }

        try {
            return $this->runPipeline($pipeline, $scheduleGuard, $logger, $trigger, $force, $isAutomatic);
        } finally {
            $lock?->release();
        }
    }

    protected function runPipeline(
        DailyDecisionPipeline $pipeline,
        DecisionPipelineScheduleService $scheduleGuard,
        PortfolioLoggerService $logger,
        string $trigger,
        bool $force,
        bool $isAutomatic,
    ): int {
        if ($isAutomatic && ! $force && $scheduleGuard->shouldSkipAutomatic($trigger)) {
            $this->info(sprintf(
                'Decision pipeline already completed automatically today (last trigger: %s); skipping.',
                $scheduleGuard->lastAutomaticTrigger() ?? 'unknown',
            ));
            $logger->scheduler('info', 'Decision pipeline skipped (automatic already ran today)', [
                'event' => 'pipeline.command_skipped',
                'trigger' => $trigger,
                'reason' => 'already_ran_today',
                'last_trigger' => $scheduleGuard->lastAutomaticTrigger(),
            ]);

            return self::SUCCESS;
        }

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
        $skippedSuccessful = 0;
        $logger->scheduler('info', 'Decision pipeline command started', [
            'event' => 'pipeline.command_started',
            'trigger' => $trigger,
            'profile_count' => $profiles->count(),
            'forced' => $force,
        ]);

        foreach ($profiles as $profile) {
            if ($isAutomatic && ! $force && $scheduleGuard->shouldSkipProfileForAutomatic($profile->id, $trigger)) {
                $this->info("  Skipped profile #{$profile->id} ({$profile->name}) — already succeeded automatically today.");
                $skippedSuccessful++;

                continue;
            }

            $this->info("Pipeline for profile #{$profile->id} ({$profile->name})...");
            try {
                $result = $pipeline->run($profile, [
                    'notify' => $notify,
                    'review' => $review,
                    'trigger' => $trigger,
                ]);
                if ($isAutomatic) {
                    $scheduleGuard->markProfileSuccessfulToday($profile->id);
                }
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

        $profileIds = $profiles->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($isAutomatic && ! $force && $failed === 0 && $scheduleGuard->allProfilesSucceededToday($profileIds)) {
            $scheduleGuard->markAutomaticRunToday($trigger);
        }

        $logger->scheduler($failed > 0 ? 'error' : 'info', 'Decision pipeline command finished', [
            'event' => 'pipeline.command_finished',
            'trigger' => $trigger,
            'failed_profiles' => $failed,
            'skipped_successful_profiles' => $skippedSuccessful,
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
