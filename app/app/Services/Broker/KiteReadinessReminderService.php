<?php

namespace App\Services\Broker;

use App\Models\PortfolioProfile;
use App\Services\ProfileSettingsService;
use App\Services\SettingsService;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Carbon;

class KiteReadinessReminderService
{
    public function __construct(
        protected BrokerConnectionService $connections,
        protected ProfileSettingsService $settings,
        protected SettingsService $globalSettings,
        protected TelegramNotificationService $telegram,
    ) {}

    /** @return array{checked:int,sent:int,skipped:int} */
    public function sendDue(?Carbon $now = null): array
    {
        $timezone = (string) $this->globalSettings->get('cron_timezone', 'Asia/Kolkata');
        $instant = ($now ?? now())->copy()->timezone($timezone);
        $time = $instant->format('H:i');
        $date = $instant->toDateString();
        $stats = ['checked' => 0, 'sent' => 0, 'skipped' => 0];

        PortfolioProfile::query()
            ->where('execution_mode', PortfolioProfile::EXECUTION_MODE_AUTOMATIC)
            ->with('user')
            ->orderBy('id')
            ->each(function (PortfolioProfile $profile) use ($time, $date, &$stats): void {
                $configuredTime = trim((string) $this->settings->get($profile, 'kite_readiness_reminder_time', '08:30'));
                if ($configuredTime === '' || $configuredTime !== $time || ! $profile->user) {
                    $stats['skipped']++;
                    return;
                }
                $stats['checked']++;
                if ($this->connections->status($profile->user)['usable']
                    || $this->settings->get($profile, ProfileSettingsService::KITE_READINESS_LAST_REMINDER_KEY) === $date) {
                    $stats['skipped']++;
                    return;
                }
                if ($this->telegram->sendMessageForProfile(
                    $profile,
                    'StoX: Automatic execution is not ready because your daily Kite session is missing or expired. Open Dashboard and select Connect Kite.',
                )) {
                    $this->settings->set($profile, ProfileSettingsService::KITE_READINESS_LAST_REMINDER_KEY, $date);
                    $stats['sent']++;
                }
            });

        return $stats;
    }
}
