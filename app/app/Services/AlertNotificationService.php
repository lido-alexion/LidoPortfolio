<?php

namespace App\Services;

use App\Models\User;

class AlertNotificationService
{
    public function __construct(
        protected SettingsService $settings,
        protected StoplossService $stoploss,
        protected TelegramNotificationService $telegram,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * @return array{sent: bool, skipped: bool, alert_count: int, message?: string}
     */
    public function sendScheduledNotifications(): array
    {
        if ($this->settings->get('notifications_enabled', 'true') !== 'true') {
            return [
                'sent' => false,
                'skipped' => true,
                'alert_count' => 0,
                'message' => 'Notifications disabled',
            ];
        }

        $alerts = $this->collectActiveAlerts();

        if ($alerts === []) {
            $this->logger->scheduler('debug', 'Scheduled alert notification skipped — no alerts', [
                'category' => 'AlertNotification',
            ]);

            return [
                'sent' => false,
                'skipped' => true,
                'alert_count' => 0,
            ];
        }

        $text = $this->formatAlertsMessage($alerts);
        $sent = $this->telegram->sendMessage($text);

        $this->logger->scheduler($sent ? 'info' : 'warning', 'Scheduled alert notification processed', [
            'category' => 'AlertNotification',
            'alert_count' => count($alerts),
            'sent' => $sent,
        ]);

        return [
            'sent' => $sent,
            'skipped' => false,
            'alert_count' => count($alerts),
        ];
    }

    /**
     * Manual test from Settings — always sends (bypasses notifications_enabled).
     *
     * @return array{sent: bool, alert_count: int, message: string}
     */
    public function sendTestNotification(string $token, string $chatId): array
    {
        $alerts = $this->collectActiveAlerts();
        $text = $alerts === []
            ? 'No active alerts at this time'
            : $this->formatAlertsMessage($alerts);

        $sent = $this->telegram->sendMessageWithCredentials($text, $token, $chatId);

        $this->logger->scheduler($sent ? 'info' : 'warning', 'Telegram test notification processed', [
            'category' => 'AlertNotification',
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
     * Same data as GET /api/alerts for each user with open holdings.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collectActiveAlerts(): array
    {
        $alerts = [];

        foreach (User::query()->orderBy('id')->get() as $user) {
            foreach ($this->stoploss->getActiveAlertsForUser($user) as $alert) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
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
