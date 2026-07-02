<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\User;

class AlertNotificationService
{
    public function __construct(
        protected ProfileSettingsService $profileSettings,
        protected AlertService $alerts,
        protected TelegramNotificationService $telegram,
        protected PortfolioLoggerService $logger,
        protected SettingsService $settings,
    ) {}

    /**
     * Dispatch notifications for profiles whose schedule includes the given time (HH:mm, cron timezone).
     *
     * @return array{sent: bool, skipped: bool, alert_count: int, profiles_notified: int, message?: string}
     */
    public function sendScheduledNotificationsAt(string $atTime): array
    {
        $scheduleService = app(NotificationScheduleService::class);
        $profilesNotified = 0;
        $totalAlerts = 0;
        $anySent = false;
        $anyEligible = false;

        foreach (PortfolioProfile::query()->orderBy('id')->get() as $profile) {
            $schedules = $scheduleService->schedulesForProfile($profile);
            if (! in_array($atTime, $schedules, true)) {
                continue;
            }

            $result = $this->sendNotificationsForProfile($profile, $atTime);
            if ($result['skipped'] && ($result['alert_count'] ?? 0) === 0) {
                continue;
            }

            $anyEligible = true;
            $totalAlerts += $result['alert_count'];
            if ($result['sent']) {
                $anySent = true;
                $profilesNotified++;
            }
        }

        if (! $anyEligible) {
            $this->logger->scheduler('debug', 'Scheduled alert notification skipped — no profiles at time', [
                'category' => 'AlertNotification',
                'at' => $atTime,
            ]);

            return [
                'sent' => false,
                'skipped' => true,
                'alert_count' => 0,
                'profiles_notified' => 0,
            ];
        }

        $this->logger->scheduler($anySent ? 'info' : 'warning', 'Scheduled alert notification processed', [
            'category' => 'AlertNotification',
            'at' => $atTime,
            'alert_count' => $totalAlerts,
            'profiles_notified' => $profilesNotified,
            'sent' => $anySent,
        ]);

        return [
            'sent' => $anySent,
            'skipped' => false,
            'alert_count' => $totalAlerts,
            'profiles_notified' => $profilesNotified,
        ];
    }

    /**
     * @return array{sent: bool, skipped: bool, alert_count: int, profiles_notified: int, message?: string}
     */
    public function sendScheduledNotifications(): array
    {
        $atTime = now()
            ->timezone(app(NotificationScheduleService::class)->timezone())
            ->format('H:i');

        return $this->sendScheduledNotificationsAt($atTime);
    }

    /**
     * Manual test from Settings — sends only the active profile's alerts.
     *
     * @return array{sent: bool, alert_count: int, message: string}
     */
    public function sendTestNotification(PortfolioProfile $profile, string $token, string $chatId): array
    {
        $alerts = $this->alerts->getActiveForProfile($profile);
        $text = $alerts === []
            ? 'No active alerts at this time'
            : $this->formatAlertsMessage($alerts);

        $sent = $this->telegram->sendMessageWithCredentials($text, $token, $chatId);

        $this->logger->scheduler($sent ? 'info' : 'warning', 'Telegram test notification processed', [
            'category' => 'AlertNotification',
            'profile_id' => $profile->id,
            'alert_count' => count($alerts),
            'sent' => $sent,
            'test' => true,
        ]);

        return [
            'sent' => $sent,
            'alert_count' => count($alerts),
            'message' => $text,
        ];
    }

    /**
     * @return array{sent: bool, skipped: bool, alert_count: int}
     */
    protected function sendNotificationsForProfile(PortfolioProfile $profile, ?string $atTime = null): array
    {
        if ($this->profileSettings->get($profile, 'notifications_enabled', 'true') !== 'true') {
            return [
                'sent' => false,
                'skipped' => true,
                'alert_count' => 0,
            ];
        }

        $alerts = $this->alerts->getActiveForProfile($profile);

        if ($alerts === []) {
            if ($this->settings->isTelegramPingWhenClearEnabled()) {
                $text = $this->formatClearPingMessage($profile, $atTime);
                $sent = $this->telegram->sendMessageForProfile($profile, $text);

                return [
                    'sent' => $sent,
                    'skipped' => false,
                    'alert_count' => 0,
                ];
            }

            return [
                'sent' => false,
                'skipped' => true,
                'alert_count' => 0,
            ];
        }

        $text = $this->formatAlertsMessage($alerts);
        $sent = $this->telegram->sendMessageForProfile($profile, $text);

        return [
            'sent' => $sent,
            'skipped' => false,
            'alert_count' => count($alerts),
        ];
    }

    protected function formatClearPingMessage(PortfolioProfile $profile, ?string $atTime = null): string
    {
        $name = trim((string) $profile->name) !== '' ? $profile->name : 'Portfolio';
        $timeLabel = $atTime ? " (scheduled check at {$atTime})" : '';

        return "✅ Lido Portfolio — {$name}: No active alerts{$timeLabel}.\n\n"
            .'Scheduled notification check — cron is working. Disable “Ping Telegram when clear” in Settings → Global when done testing.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     */
    protected function formatAlertsMessage(array $alerts): string
    {
        $lines = ['Portfolio alerts ('.count($alerts).')'];

        foreach ($alerts as $alert) {
            $symbol = $alert['stock']['symbol'] ?? $alert['stock']['name'] ?? 'Unknown';
            $message = trim((string) ($alert['message'] ?? ''));
            $lines[] = $message !== '' ? "• {$symbol}: {$message}" : "• {$symbol}";
        }

        return implode("\n", $lines);
    }
}
