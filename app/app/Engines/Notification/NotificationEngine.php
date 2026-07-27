<?php

namespace App\Engines\Notification;

use App\Models\PortfolioProfile;
use App\Models\TosNotification;
use App\Models\TradingRecommendation;
use App\Services\Notification\NotificationMessageComposer;
use App\Services\PortfolioLoggerService;
use App\Services\ProfileSettingsService;
use App\Services\TelegramNotificationService;
use App\Support\TradingOsConfig;

/**
 * Notification Engine — delivery only; never mutates recommendation content.
 * Message composition is owned by NotificationMessageComposer (TD-005).
 */
class NotificationEngine
{
    public function __construct(
        protected TelegramNotificationService $telegram,
        protected ProfileSettingsService $profileSettings,
        protected PortfolioLoggerService $logger,
        protected NotificationMessageComposer $composer,
    ) {}

    /**
     * Queue + deliver notifications for active recommendations (idempotent per recommendation+channel).
     *
     * @param  list<TradingRecommendation>|null  $recommendations
     * @return list<TosNotification>
     */
    public function notifyRecommendations(PortfolioProfile $profile, ?array $recommendations = null): array
    {
        $recommendations ??= TradingRecommendation::query()
            ->with('security')
            ->forProfile($profile)
            ->where('status', 'active')
            ->orderByDesc('priority')
            ->limit(20)
            ->get()
            ->all();

        $delivered = [];
        foreach ($recommendations as $rec) {
            $delivered[] = $this->queueAndSend($profile, $rec);
        }

        return $delivered;
    }

    public function queueAndSend(PortfolioProfile $profile, TradingRecommendation $rec): TosNotification
    {
        $channel = 'telegram';
        $key = 'rec-'.$rec->id.'-'.$channel.'-v'.$rec->version;

        $existing = TosNotification::query()->where('idempotency_key', $key)->first();
        if ($existing && in_array($existing->status, ['delivered', 'queued', 'sending'], true)) {
            return $existing;
        }

        $chatId = $this->profileSettings->get($profile, 'telegram_chat_id');
        $payload = [
            'recommendation_id' => $rec->id,
            'type' => $rec->recommendation_type,
            'symbol' => $rec->security?->symbol,
            'priority' => $rec->priority,
            'confidence' => (float) $rec->confidence,
            'risk_level' => $rec->risk_level,
            'message' => $this->composer->recommendationMessage($rec),
        ];

        $notification = $existing ?? TosNotification::query()->create([
            'profile_id' => $profile->id,
            'recommendation_id' => $rec->id,
            'notification_type' => 'recommendation',
            'channel' => $channel,
            'recipient' => $chatId,
            'payload' => $payload,
            'status' => 'queued',
            'idempotency_key' => $key,
            'attempt_count' => 0,
        ]);

        if ($existing) {
            $notification->forceFill([
                'payload' => $payload,
                'status' => 'queued',
                'recipient' => $chatId,
            ])->save();
        }

        return $this->send($notification);
    }

    public function send(TosNotification $notification): TosNotification
    {
        $maxRetries = TradingOsConfig::notificationMaxRetries();
        $notification->forceFill([
            'status' => 'sending',
            'attempt_count' => $notification->attempt_count + 1,
        ])->save();

        $message = (string) ($notification->payload['message'] ?? 'Trading recommendation update');
        $profile = $notification->profile;

        $ok = false;
        try {
            $ok = $this->telegram->sendMessageForProfile($profile, $message);
        } catch (\Throwable $e) {
            $notification->forceFill([
                'status' => 'failed',
                'last_error' => $e->getMessage(),
            ])->save();

            $this->logger->log('daily', 'NotificationEngine', 'error', 'Delivery exception: '.$e->getMessage(), [
                'notification_id' => $notification->id,
            ]);

            return $notification->fresh();
        }

        if ($ok) {
            $notification->forceFill([
                'status' => 'delivered',
                'delivered_at' => now(),
                'last_error' => null,
            ])->save();
        } else {
            $status = $notification->attempt_count >= $maxRetries ? 'failed' : 'queued';
            $notification->forceFill([
                'status' => $status,
                'last_error' => 'Telegram delivery failed or disabled',
            ])->save();
        }

        return $notification->fresh();
    }

    public function retry(PortfolioProfile $profile, int $notificationId): ?TosNotification
    {
        $notification = TosNotification::query()
            ->where('profile_id', $profile->id)
            ->where('id', $notificationId)
            ->first();

        if (! $notification) {
            return null;
        }

        if ($notification->status === 'delivered') {
            return $notification;
        }

        // Allow a fresh attempt by clearing terminal failure.
        $notification->forceFill(['status' => 'queued'])->save();

        return $this->send($notification);
    }

    /**
     * @return list<TosNotification>
     */
    public function history(PortfolioProfile $profile, int $limit = 50): array
    {
        return TosNotification::query()
            ->where('profile_id', $profile->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }
}
