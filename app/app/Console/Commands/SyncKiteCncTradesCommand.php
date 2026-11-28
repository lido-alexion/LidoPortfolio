<?php

namespace App\Console\Commands;

use App\Models\PortfolioProfile;
use App\Models\BrokerConnection;
use App\Services\Reconciliation\KiteCncTradeSyncService;
use App\Services\SettingsService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class SyncKiteCncTradesCommand extends Command
{
    private const AUTH_RETRY_COOLDOWN_MINUTES = 60;
    protected $signature = 'portfolio:sync-kite-cnc {--profile= : Reconcile one portfolio}';
    protected $description = 'Sync current-day executed Kite CNC trades after market close';

    public function handle(KiteCncTradeSyncService $sync, SettingsService $settings): int
    {
        $timezone = (string) $settings->get('cron_timezone', 'Asia/Kolkata');
        $now = Carbon::now($timezone);
        if (! TradingCalendar::isEquitySessionDate($now)) return self::SUCCESS;
        $close = Carbon::parse($now->toDateString().' '.(string) $settings->get('market_close_time', '15:30'), $timezone);
        $due = $close->addMinutes(max(0, (int) $settings->get('reconciliation_delay_minutes', '30')));
        if ($now->lt($due)) return self::SUCCESS;

        $query = PortfolioProfile::query()->where('portfolio_type', PortfolioProfile::TYPE_LIVE)
            ->whereIn('execution_mode', [PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC, PortfolioProfile::EXECUTION_MODE_AUTOMATIC]);
        if ($this->option('profile')) $query->whereKey((int) $this->option('profile'));
        $failed = 0;
        // Run timestamps are persisted as database timestamps (UTC). Compare
        // against the configured local cutoff as an instant, and only accept
        // evidence with a known start time. Missing/ambiguous legacy timing
        // must not suppress today's scheduled catch-up.
        $dueUtc = $due->copy()->utc();
        $tradingDate = $now->copy()->timezone('Asia/Kolkata')->toDateString();
        foreach ($query->get() as $profile) {
            $connection = BrokerConnection::query()->where('user_id', $profile->user_id)
                ->where('provider', BrokerConnection::PROVIDER_KITE)->first();
            if (! $connection?->isUsable()) continue;
            $brokerAccountId = trim((string) ($connection->broker_user_id ?? ''));
            if ($brokerAccountId === '') continue;
            $alreadyCompleted = \App\Models\KiteCncTradeSyncRun::query()->where('profile_id', $profile->id)
                ->where('broker_account_id', $brokerAccountId)
                ->whereDate('trading_date', $tradingDate)
                ->whereIn('status', ['completed', 'needs_review'])
                ->whereNotNull('started_at')->where('started_at', '>=', $dueUtc)
                ->exists();
            if ($alreadyCompleted) continue;
            $recentAuthRejection = \App\Models\KiteCncTradeSyncRun::query()->where('profile_id', $profile->id)
                ->where('broker_account_id', $brokerAccountId)->whereDate('trading_date', $tradingDate)
                ->where('trigger', 'scheduled')->where('status', 'sync_failed')
                ->where('failure', 'kite_trades_auth_rejected')
                ->where('completed_at', '>', now()->subMinutes(self::AUTH_RETRY_COOLDOWN_MINUTES))->exists();
            if ($recentAuthRejection) continue;
            try {
                $result = $sync->sync($profile, 'scheduled');
                if ($result['run']->status === 'sync_failed') $failed++;
            } catch (Throwable $error) {
                $failed++;
                $this->warn('Portfolio #'.$profile->id.': '.$error->getMessage());
            }
        }
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
