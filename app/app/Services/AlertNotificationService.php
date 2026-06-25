<?php

namespace App\Services;

use App\Models\User;

class AlertNotificationService
{
    public function __construct(
        protected UserSettingsService $userSettings,
        protected StoplossService $stoploss,
        protected TelegramNotificationService $telegram,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * Dispatch notifications for users whose schedule includes the given time (HH:mm, cron timezone).
     *
     * @return array{sent: bool, skipped: bool, alert_count: int, users_notified: int, message?: string}
     */
    public function sendScheduledNotificationsAt(string $atTime): array
    {
        $scheduleService = app(NotificationScheduleService::class);
        $usersNotified = 0;
        $totalAlerts = 0;
        $anySent = false;
        $anyEligible = false;

        foreach (User::query()->orderBy('id')->get() as $user) {
            $schedules = $scheduleService->schedulesForUser($user);
            if (! in_array($atTime, $schedules, true)) {
                continue;
            }

            $result = $this->sendNotificationsForUser($user);
            if ($result['skipped'] && ($result['alert_count'] ?? 0) === 0) {
                continue;
            }

            $anyEligible = true;
            $totalAlerts += $result['alert_count'];
            if ($result['sent']) {
                $anySent = true;
                $usersNotified++;
            }
        }

        if (! $anyEligible) {
            $this->logger->scheduler('debug', 'Scheduled alert notification skipped — no users at time', [
                'category' => 'AlertNotification',
                'at' => $atTime,
            ]);

            return [
                'sent' => false,
                'skipped' => true,
                'alert_count' => 0,
                'users_notified' => 0,
            ];
        }

        $this->logger->scheduler($anySent ? 'info' : 'warning', 'Scheduled alert notification processed', [
            'category' => 'AlertNotification',
            'at' => $atTime,
            'alert_count' => $totalAlerts,
            'users_notified' => $usersNotified,
            'sent' => $anySent,
        ]);

        return [
            'sent' => $anySent,
            'skipped' => false,
            'alert_count' => $totalAlerts,
            'users_notified' => $usersNotified,
        ];
    }

    /**
     * @return array{sent: bool, skipped: bool, alert_count: int, users_notified: int, message?: string}
     */
    public function sendScheduledNotifications(): array
    {
        $atTime = now()
            ->timezone(app(NotificationScheduleService::class)->timezone())
            ->format('H:i');

        return $this->sendScheduledNotificationsAt($atTime);
    }

    /**
     * Manual test from Settings — sends only the requesting user's alerts.
     *
     * @return array{sent: bool, alert_count: int, message: string}
     */
    public function sendTestNotification(User $user, string $token, string $chatId): array
    {
        $alerts = $this->stoploss->getActiveAlertsForUser($user);
        $text = $alerts === []
            ? 'No active alerts at this time'
            : $this->formatAlertsMessage($alerts);

        $sent = $this->telegram->sendMessageWithCredentials($text, $token, $chatId);

        $this->logger->scheduler($sent ? 'info' : 'warning', 'Telegram test notification processed', [
            'category' => 'AlertNotification',
            'user_id' => $user->id,
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
    protected function sendNotificationsForUser(User $user): array
    {
        if ($this->userSettings->get($user, 'notifications_enabled', 'true') !== 'true') {
            return [
                'sent' => false,
                'skipped' => true,
                'alert_count' => 0,
            ];
        }

        $alerts = $this->stoploss->getActiveAlertsForUser($user);

        if ($alerts === []) {
            return [
                'sent' => false,
                'skipped' => true,
                'alert_count' => 0,
            ];
        }

        $text = $this->formatAlertsMessage($alerts);
        $sent = $this->telegram->sendMessageForUser($user, $text);

        return [
            'sent' => $sent,
            'skipped' => false,
            'alert_count' => count($alerts),
        ];
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
