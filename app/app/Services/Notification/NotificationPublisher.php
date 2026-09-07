<?php

namespace App\Services\Notification;

use App\Models\NotificationSource;
use App\Models\RecipientNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class NotificationPublisher
{
    private const SEVERITIES = ['info', 'action_required', 'critical'];

    private const AUDIENCES = ['investor', 'admin', 'both'];

    /** @param iterable<User> $recipients */
    public function publishEvent(iterable $recipients, array $content): NotificationSource
    {
        return DB::transaction(function () use ($recipients, $content) {
            $now = now();
            $source = NotificationSource::query()->create([
                ...$this->validateContent($content, false),
                'condition_state' => 'na',
                'occurrence_count' => 1,
                'first_detected_at' => $now,
                'latest_detected_at' => $now,
            ]);
            $this->recordOccurrence($source, 'detected', $now);
            $this->fanOut($source, $recipients, $now);

            return $source->fresh(['recipients', 'occurrences']);
        });
    }

    /** @param iterable<User> $recipients */
    public function publishCondition(string $conditionKey, iterable $recipients, array $content): NotificationSource
    {
        if (trim($conditionKey) === '') {
            throw new InvalidArgumentException('A stable condition key is required.');
        }

        return DB::transaction(function () use ($conditionKey, $recipients, $content) {
            $now = now();
            $content = $this->validateContent($content, true);
            $source = NotificationSource::query()->where('active_condition_key', $conditionKey)->lockForUpdate()->first();

            if (! $source) {
                $source = NotificationSource::query()->create([
                    ...$content,
                    'condition_key' => $conditionKey,
                    'active_condition_key' => $conditionKey,
                    'condition_state' => 'active',
                    'occurrence_count' => 1,
                    'first_detected_at' => $now,
                    'latest_detected_at' => $now,
                ]);
                $activity = 'detected';
                $makeUnread = false;
            } else {
                $makeUnread = $source->severity !== 'critical' && $content['severity'] === 'critical';
                $activity = $makeUnread ? 'severity_escalated' : 'redetected';
                $source->fill([...$content, 'occurrence_count' => $source->occurrence_count + 1, 'latest_detected_at' => $now])->save();
            }

            $this->recordOccurrence($source, $activity, $now);
            $this->fanOut($source, $recipients, $now);
            if ($makeUnread) {
                $source->recipients()->update(['attention_state' => 'unread', 'read_at' => null, 'latest_activity_at' => $now]);
            }

            return $source->fresh(['recipients', 'occurrences']);
        });
    }

    public function resolveCondition(string $conditionKey): ?NotificationSource
    {
        return DB::transaction(function () use ($conditionKey) {
            $source = NotificationSource::query()->where('active_condition_key', $conditionKey)->lockForUpdate()->first();
            if (! $source) {
                return null;
            }

            $now = now();
            $source->update(['active_condition_key' => null, 'condition_state' => 'resolved', 'resolved_at' => $now]);
            $source->recipients()->update(['condition_state' => 'resolved', 'resolved_at' => $now, 'latest_activity_at' => $now]);
            $this->recordOccurrence($source, 'resolved', $now);

            return $source->fresh(['recipients', 'occurrences']);
        });
    }

    public function markRead(RecipientNotification $notification): RecipientNotification
    {
        if ($notification->attention_state !== 'read') {
            $notification->update(['attention_state' => 'read', 'read_at' => now()]);
        }

        return $notification->fresh();
    }

    private function validateContent(array $content, bool $condition): array
    {
        foreach (['notification_type', 'audience', 'severity', 'title', 'message'] as $required) {
            if (! isset($content[$required]) || trim((string) $content[$required]) === '') {
                throw new InvalidArgumentException("Notification {$required} is required.");
            }
        }
        if (! in_array($content['severity'], self::SEVERITIES, true)) {
            throw new InvalidArgumentException('Unsupported notification severity.');
        }
        if (! in_array($content['audience'], self::AUDIENCES, true)) {
            throw new InvalidArgumentException('Unsupported notification audience.');
        }

        return [
            'notification_type' => $content['notification_type'],
            'audience' => $content['audience'],
            'severity' => $content['severity'],
            'title' => $content['title'],
            'message' => $content['message'],
            'context' => $content['context'] ?? null,
            'primary_action' => $content['primary_action'] ?? null,
            'external_info_delivery' => ! $condition && (bool) ($content['external_info_delivery'] ?? false),
        ];
    }

    /** @param iterable<User> $recipients */
    private function fanOut(NotificationSource $source, iterable $recipients, mixed $now): void
    {
        Collection::make($recipients)->unique('id')
            ->filter(fn (User $user) => $source->audience === 'both'
                || ($source->audience === 'admin' && $user->is_admin)
                || ($source->audience === 'investor' && ! $user->is_admin))
            ->each(fn (User $user) => RecipientNotification::query()->firstOrCreate(
                ['source_id' => $source->id, 'user_id' => $user->id],
                ['attention_state' => 'unread', 'condition_state' => $source->condition_state, 'latest_activity_at' => $now],
            ));
    }

    private function recordOccurrence(NotificationSource $source, string $activity, mixed $now): void
    {
        $source->occurrences()->create([
            'activity_type' => $activity,
            'severity' => $source->severity,
            'snapshot' => ['title' => $source->title, 'message' => $source->message, 'context' => $source->context],
            'occurred_at' => $now,
        ]);
    }
}
