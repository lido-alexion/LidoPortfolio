<?php

namespace App\Engines\Notification;

use App\Models\PortfolioProfile;
use App\Models\NotificationDelivery;
use App\Models\NotificationSource;
use App\Models\TosNotification;
use App\Models\TradingRecommendation;
use App\Repositories\Tos\NotificationQueryRepository;
use App\Services\Notification\NotificationMessageComposer;
use App\Services\Notification\NotificationPublisher;
use App\Services\Notification\NotificationDeliveryPlanner;
use App\Services\PortfolioLoggerService;
use App\Services\ProfileSettingsService;
use App\Support\TradingOsConfig;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Notification Engine — delivery only; never mutates recommendation content.
 * Message composition is owned by NotificationMessageComposer (TD-005).
 */
class NotificationEngine
{
    public function __construct(
        protected ProfileSettingsService $profileSettings,
        protected PortfolioLoggerService $logger,
        protected NotificationMessageComposer $composer,
        protected NotificationQueryRepository $notifications,
        protected NotificationPublisher $publisher,
        protected NotificationDeliveryPlanner $deliveries,
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

    public function send(TosNotification $notification, bool $retryFailed = false): TosNotification
    {
        $maxRetries = TradingOsConfig::notificationMaxRetries();
        $notification->forceFill([
            'status' => 'sending',
            'attempt_count' => $notification->attempt_count + 1,
        ])->save();

        $message = (string) ($notification->payload['message'] ?? 'Trading recommendation update');
        $profile = $notification->profile;

        try {
            $source = $this->sourceFor($notification, $profile, $message);
            if ($retryFailed) {
                $this->deliveries->requeueFailedForSourceChannel($source, 'telegram');
            }
            $this->deliveries->planInitial($source);
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

        $hasTelegramDelivery = NotificationDelivery::query()
            ->whereHas('recipientNotification', fn ($query) => $query->where('source_id', $source->id))
            ->where('channel', 'telegram')
            ->exists();
        $notification->forceFill([
            'status' => $hasTelegramDelivery
                ? 'queued'
                : ($notification->attempt_count >= $maxRetries ? 'failed' : 'queued'),
            'last_error' => $hasTelegramDelivery ? null : 'Telegram delivery unavailable or disabled',
        ])->save();

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

        return $this->send($notification, true);
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

    private function sourceFor(TosNotification $notification, PortfolioProfile $profile, string $message): NotificationSource
    {
        $payload = $notification->payload ?? [];
        $sourceId = (int) ($payload['notification_source_id'] ?? 0);
        if ($sourceId > 0 && ($source = NotificationSource::query()->find($sourceId))) {
            return $source;
        }

        $source = $this->publisher->publishEvent([$profile->user], [
            'notification_type' => 'trading.'.Str::snake($notification->notification_type),
            'audience' => 'both',
            'severity' => 'action_required',
            'title' => Str::headline($notification->notification_type),
            'message' => $message,
            'context' => [
                'portfolio_id' => $profile->id,
                'recommendation_id' => $notification->recommendation_id,
                'legacy_tos_notification_id' => $notification->id,
                'legacy_idempotency_key' => $notification->idempotency_key,
            ],
            'primary_action' => ['label' => 'Open Recommendations', 'route' => '/recommendations'],
        ]);

        $payload['notification_source_id'] = $source->id;
        $notification->forceFill(['payload' => $payload])->save();

        return $source;
    }
}
