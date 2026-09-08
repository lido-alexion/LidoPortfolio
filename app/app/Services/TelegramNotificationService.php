<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\NotificationChannelSetting;
use App\Models\NotificationDelivery;
use App\Models\NotificationSource;
use App\Models\User;
use App\Services\Notification\LegacyTelegramChannelMigrator;
use App\Services\Notification\NotificationPublisher;

class TelegramNotificationService
{
    public function __construct(
        protected ProfileSettingsService $profileSettings,
        protected SystemLogService $logger,
        protected NotificationPublisher $publisher,
        protected LegacyTelegramChannelMigrator $legacyTelegram,
    ) {}

    public function sendMessageForProfile(PortfolioProfile $profile, string $message): bool
    {
        if ($this->profileSettings->get($profile, 'notifications_enabled', 'true') !== 'true') {
            return false;
        }

        $token = $this->profileSettings->get($profile, 'telegram_bot_token');
        $chatId = $this->profileSettings->get($profile, 'telegram_chat_id');

        if (! $token || ! $chatId) {
            $this->logger->log('telegram', 'Telegram credentials not configured for profile', [
                'profile_id' => $profile->id,
            ], 'warning');

            return false;
        }

        return $this->sendMessageWithCredentials($message, $token, $chatId);
    }

    public function sendMessageWithCredentials(string $message, string $token, string $chatId): bool
    {
        $token = trim($token);
        $chatId = trim($chatId);

        if ($token === '' || $chatId === '') {
            $this->logger->log('telegram', 'Telegram credentials not configured', [], 'warning');

            return false;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
            ]);

            if (! $response->successful()) {
                $this->logger->log('telegram', 'Telegram API failure', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->log('telegram', 'Telegram request exception: '.$e->getMessage());

            return false;
        }
    }

    public function sendSyncFailureAlert(string $details): bool
    {
        $admins = User::query()->where('is_admin', true)->orderBy('id')->get();
        if ($admins->isEmpty()) {
            return false;
        }

        $source = $this->publisher->publishEvent($admins, [
            'notification_type' => 'operations.price_sync_failure',
            'audience' => 'admin',
            'severity' => 'critical',
            'title' => 'Portfolio price sync failed',
            'message' => 'Portfolio sync failure: '.$details,
            'primary_action' => ['label' => 'Review Price Sync', 'route' => '/settings/universe-price-sync'],
        ]);

        return $this->deliveryStats($source)['sent'];
    }

    /**
     * @return array{sent: bool, recipients: int}
     */
    public function sendAdminOperationalAlert(string $message): array
    {
        $admins = User::query()->where('is_admin', true)->orderBy('id')->get();
        if ($admins->isEmpty()) {
            return ['sent' => false, 'recipients' => 0];
        }

        $severity = str_contains($message, '[CRITICAL]') ? 'critical'
            : (str_contains($message, '[WARNING]') ? 'action_required' : 'info');
        $title = collect(preg_split('/\R/', $message) ?: [])
            ->first(fn (string $line) => preg_match('/^\[(CRITICAL|WARNING)\]\s+/', $line) === 1);
        $title = $title
            ? (preg_replace('/^\[(CRITICAL|WARNING)\]\s+/', '', $title) ?? 'Operational status')
            : 'Operational status';

        $source = $this->publisher->publishEvent($admins, [
            'notification_type' => $severity === 'info' ? 'operations.all_clear' : 'operations.alert',
            'audience' => 'admin',
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'external_info_delivery' => $severity === 'info',
            'primary_action' => ['label' => 'Review Operational Alerts', 'route' => '/settings/admin-alerts'],
        ]);

        return $this->deliveryStats($source);
    }

    public function countAdminTelegramRecipients(): int
    {
        $admins = User::query()->where('is_admin', true)->get();
        foreach ($admins as $admin) {
            $this->legacyTelegram->migrateIfUnambiguous($admin);
        }

        return NotificationChannelSetting::query()
            ->whereIn('user_id', $admins->pluck('id'))
            ->where('channel', 'telegram')
            ->where('enabled', true)
            ->whereNotNull('verified_at')
            ->get()
            ->map(fn (NotificationChannelSetting $setting) => hash('sha256',
                (string) ($setting->configuration['bot_token'] ?? '')."\0".(string) ($setting->configuration['chat_id'] ?? ''),
            ))
            ->unique()
            ->count();
    }

    /** @return array{sent: bool, recipients: int} */
    private function deliveryStats(NotificationSource $source): array
    {
        $recipientIds = $source->recipients->pluck('id');
        $deliveredRecipientCount = NotificationDelivery::query()
            ->whereIn('recipient_notification_id', $recipientIds)
            ->pluck('recipient_notification_id')
            ->unique()
            ->count();

        return [
            'sent' => $deliveredRecipientCount > 0,
            'recipients' => $deliveredRecipientCount,
        ];
    }
}
