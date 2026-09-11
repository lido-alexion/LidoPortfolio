<?php

namespace App\Console\Commands;

use App\Models\PortfolioProfile;
use App\Models\PortfolioReconciliationRun;
use App\Services\Reconciliation\PortfolioReconciliationService;
use App\Services\SettingsService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePortfoliosCommand extends Command
{
    protected $signature = 'portfolio:reconcile {--profile= : Reconcile one eligible Portfolio}';

    protected $description = 'Run due Kite holdings/funds Portfolio reconciliations';

    public function handle(PortfolioReconciliationService $reconciliation, SettingsService $settings): int
    {
        $timezone = (string) $settings->get('cron_timezone', 'Asia/Kolkata');
        $now = Carbon::now($timezone);
        if (! TradingCalendar::isEquitySessionDate($now)) {
            $this->info('Portfolio reconciliation skipped: not an equity session.');

            return self::SUCCESS;
        }
        $close = Carbon::parse($now->toDateString().' '.(string) $settings->get('market_close_time', '15:30'), $timezone);
        $dueAt = $close->addMinutes(max(0, (int) $settings->get('reconciliation_delay_minutes', '30')));
        if ($now->lt($dueAt)) {
            $this->info('Portfolio reconciliation skipped: after-close delay has not elapsed.');

            return self::SUCCESS;
        }

        $query = PortfolioProfile::query()->where('portfolio_type', PortfolioProfile::TYPE_LIVE)
            ->whereIn('execution_mode', [PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC, PortfolioProfile::EXECUTION_MODE_AUTOMATIC]);
        if ($this->option('profile')) {
            $query->whereKey((int) $this->option('profile'));
        }
        $processed = 0;
        $failed = 0;
        foreach ($query->get() as $profile) {
            $alreadyDone = PortfolioReconciliationRun::query()->where('profile_id', $profile->id)
                ->where('trigger', 'scheduled')->where('status', 'completed')
                ->whereDate('completed_at', $now->toDateString())->exists();
            if ($alreadyDone) {
                continue;
            }
            try {
                $run = $reconciliation->run($profile, 'scheduled');
                $processed++;
                if ($run->status === 'sync_failed') {
                    $failed++;
                }
            } catch (Throwable $error) {
                $failed++;
                $this->warn('Portfolio #'.$profile->id.': '.$error->getMessage());
            }
        }
        $this->info("Portfolio reconciliations: {$processed} processed; {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
