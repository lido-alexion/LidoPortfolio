<?php

namespace App\Services;

use App\Models\NotificationDelivery;
use App\Models\PortfolioProfile;
use App\Services\Notification\NotificationMessageComposer;
use App\Services\Notification\NotificationPublisher;
use App\Support\TradingCalendar;

class AlertNotificationService
{
    public function __construct(
        protected ProfileSettingsService $profileSettings,
        protected AlertService $alerts,
        protected PortfolioLoggerService $logger,
        protected SettingsService $settings,
        protected NotificationMessageComposer $composer,
        protected NotificationPublisher $publisher,
    ) {}

    /**
     * Dispatch notifications for profiles whose schedule includes the given time (HH:mm, cron timezone).
     * Skipped entirely on weekends and admin-defined trade holidays (markets closed).
     *
     * @return array{sent: bool, skipped: bool, alert_count: int, profiles_notified: int, message?: string, skip_reason?: string}
     */
    public function sendScheduledNotificationsAt(string $atTime): array
    {
        $scheduleService = app(NotificationScheduleService::class);
        $timezone = $scheduleService->timezone();
        $today = now()->timezone($timezone);

        if (! TradingCalendar::isEquitySessionDate($today)) {
            $reason = $today->isWeekend()
                ? 'weekend'
                : 'trade_holiday';

            $this->logger->scheduler('info', 'Scheduled alert notification skipped — markets closed', [
                'category' => 'AlertNotification',
                'at' => $atTime,
                'date' => $today->toDateString(),
                'timezone' => $timezone,
                'skip_reason' => $reason,
            ]);

            return [
                'sent' => false,
                'skipped' => true,
                'alert_count' => 0,
                'profiles_notified' => 0,
                'skip_reason' => $reason,
            ];
        }

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
     * @return array{sent: bool, skipped: bool, alert_count: int, profiles_notified: int, message?: string, skip_reason?: string}
     */
    public function sendScheduledNotifications(): array
    {
        $atTime = now()
            ->timezone(app(NotificationScheduleService::class)->timezone())
            ->format('H:i');

        return $this->sendScheduledNotificationsAt($atTime);
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
                $text = $this->composer->clearPingMessage($profile, $atTime);
                $source = $this->publisher->publishEvent([$profile->user], [
                    'notification_type' => 'portfolio.alert_digest_clear',
                    'audience' => 'both',
                    'severity' => 'info',
                    'title' => 'No active portfolio alerts',
                    'message' => $text,
                    'external_info_delivery' => true,
                    'context' => ['portfolio_id' => $profile->id, 'scheduled_at' => $atTime],
                    'primary_action' => ['label' => 'Open Alerts', 'route' => '/dashboard'],
                ]);
                $sent = NotificationDelivery::query()
                    ->whereHas('recipientNotification', fn ($query) => $query->where('source_id', $source->id))
                    ->exists();

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

        $text = $this->composer->alertsMessage($alerts);
        $this->publisher->publishEvent([$profile->user], [
            'notification_type' => 'portfolio.alert_digest',
            'audience' => 'investor',
            'severity' => 'action_required',
            'title' => 'Portfolio alerts',
            'message' => $text,
            'context' => [
                'portfolio_id' => $profile->id,
                'scheduled_at' => $atTime,
                'alert_count' => count($alerts),
                'alert_ids' => array_values(array_map(fn (array $alert) => (int) $alert['id'], $alerts)),
            ],
            'primary_action' => ['label' => 'Open Alerts', 'route' => '/dashboard'],
        ]);

        return [
            'sent' => true,
            'skipped' => false,
            'alert_count' => count($alerts),
        ];
    }
}
