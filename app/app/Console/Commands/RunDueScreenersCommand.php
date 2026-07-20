<?php

namespace App\Console\Commands;

use App\Models\Screener;
use App\Services\Screener\ScreenerRunService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunDueScreenersCommand extends Command
{
    protected $signature = 'portfolio:run-due-screeners';

    protected $description = 'Run screeners whose schedule matches the current local minute';

    public function handle(ScreenerRunService $runs, SettingsService $settings): int
    {
        $timezone = $settings->get('cron_timezone', env('PORTFOLIO_CRON_TIMEZONE', 'Asia/Kolkata'))
            ?: 'Asia/Kolkata';
        $now = Carbon::now($timezone);
        $hhmm = $now->format('H:i');
        $weekday = (int) $now->dayOfWeek; // 0=Sun … 6=Sat

        $candidates = Screener::query()
            ->where('is_enabled', true)
            ->where('schedule_enabled', true)
            ->where('schedule_time', $hhmm)
            ->get();

        $ran = 0;
        foreach ($candidates as $screener) {
            $days = $screener->schedule_days;
            if (is_array($days) && $days !== [] && ! in_array($weekday, array_map('intval', $days), true)) {
                continue;
            }

            // Guard: already ran this local minute
            if ($screener->last_run_at !== null) {
                $lastLocal = $screener->last_run_at->copy()->timezone($timezone);
                if ($lastLocal->format('Y-m-d H:i') === $now->format('Y-m-d H:i')) {
                    continue;
                }
            }

            try {
                $this->info("Running screener #{$screener->id} ({$screener->name})…");
                $runs->runToCompletion($screener, 'schedule');
                $ran++;
            } catch (\Throwable $e) {
                $this->error("Screener #{$screener->id} failed: ".$e->getMessage());
            }
        }

        $this->info("Ran {$ran} due screener(s) at {$hhmm} {$timezone}.");

        return self::SUCCESS;
    }
}
