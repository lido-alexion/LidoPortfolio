<?php

namespace App\Engines\Notification;

use App\Models\PortfolioProfile;
use App\Models\TosNotification;
use App\Models\TradingRecommendation;
use App\Repositories\Tos\NotificationQueryRepository;
use App\Services\Notification\NotificationMessageComposer;
use App\Services\PortfolioLoggerService;
use App\Services\ProfileSettingsService;
use App\Services\TelegramNotificationService;
use App\Support\TradingOsConfig;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
        protected NotificationQueryRepository $notifications,
    ) {}

    /**
     * Queue + deliver notifications for actionable recommendations only
     * (OPEN / INCREASE / REDUCE / EXIT). HOLD / WATCH insights are skipped.
     * Idempotent per recommendation+channel.
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
            ->actionableTypes()
            ->orderByDesc('priority')
            ->limit(20)
            ->get()
            ->all();

        $delivered = [];
        foreach ($recommendations as $rec) {
            if (! $rec->isActionable()) {
                continue;
            }
            $delivered[] = $this->queueAndSend($profile, $rec);
        }

        return $delivered;
    }

    public function queueAndSend(PortfolioProfile $profile, TradingRecommendation $rec): TosNotification
    {
        $channel = 'telegram';
        $key = 'rec-'.$rec->id.'-'.$channel.'-v'.$rec->version;

        $existing = $this->notifications->findByIdempotencyKey($key);
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

    /**
     * Queue + deliver a domain notification (Telegram). Idempotent by key.
     * Skips silently when an in-flight/delivered row already exists for the key.
     *
     * @param  array<string, mixed>  $payloadExtras  merged into payload (must not omit message)
     */
    public function notifyDomain(
        PortfolioProfile $profile,
        string $notificationType,
        string $idempotencyKey,
        string $message,
        array $payloadExtras = [],
        ?int $recommendationId = null,
    ): ?TosNotification {
        $channel = 'telegram';
        $existing = $this->notifications->findByIdempotencyKey($idempotencyKey);
        if ($existing && in_array($existing->status, ['delivered', 'queued', 'sending'], true)) {
            return $existing;
        }

        $chatId = $this->profileSettings->get($profile, 'telegram_chat_id');
        $payload = array_merge($payloadExtras, [
            'message' => $message,
            'notification_type' => $notificationType,
        ]);

        $notification = $existing ?? TosNotification::query()->create([
            'profile_id' => $profile->id,
            'recommendation_id' => $recommendationId,
            'notification_type' => $notificationType,
            'channel' => $channel,
            'recipient' => $chatId,
            'payload' => $payload,
            'status' => 'queued',
            'idempotency_key' => $idempotencyKey,
            'attempt_count' => 0,
        ]);

        if ($existing) {
            $notification->forceFill([
                'payload' => $payload,
                'status' => 'queued',
                'recipient' => $chatId,
                'notification_type' => $notificationType,
                'recommendation_id' => $recommendationId,
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

            $this->logger->event('NotificationEngine', 'notification.delivery_failed', 'error', 'Delivery exception', [
                'notification_id' => $notification->id,
                'profile_id' => $notification->profile_id,
                'exception' => $e->getMessage(),
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
        $notification = $this->notifications->findForProfile($profile, $notificationId);

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
     * @return LengthAwarePaginator<int, TosNotification>
     */
    public function paginateHistory(PortfolioProfile $profile, int $page = 1, int $pageSize = 50): LengthAwarePaginator
    {
        return $this->notifications->paginateHistory($profile, $page, $pageSize);
    }

    public function history(PortfolioProfile $profile, int $limit = 50): array
    {
        return $this->paginateHistory($profile, 1, $limit)->items();
    }
}
