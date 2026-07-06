<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\PortfolioProfile;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CalendarEventService
{
    public function __construct(protected CalendarRecurrenceService $recurrence) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listForProfile(PortfolioProfile $profile): Collection
    {
        return CalendarEvent::query()
            ->where('profile_id', $profile->id)
            ->orderBy('title')
            ->get()
            ->map(fn (CalendarEvent $event) => $this->formatEvent($event));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function occurrencesForProfile(PortfolioProfile $profile, Carbon $from, Carbon $to): array
    {
        $events = CalendarEvent::query()
            ->where('profile_id', $profile->id)
            ->where('is_active', true)
            ->get();

        return $this->recurrence->occurrencesForEvents($events, $from, $to);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function upcomingForProfile(PortfolioProfile $profile, int $days = 31): array
    {
        $today = Carbon::today();
        $to = $today->copy()->addDays($days);
        $occurrences = $this->occurrencesForProfile($profile, $today, $to);

        return array_values(array_map(function (array $occurrence) use ($today) {
            $date = Carbon::parse($occurrence['date'])->startOfDay();
            $daysAhead = (int) $today->diffInDays($date);

            return array_merge($occurrence, [
                'days_ahead' => $daysAhead,
            ]);
        }, $occurrences));
    }

    public function create(PortfolioProfile $profile, array $data): array
    {
        $payload = $this->normalizePayload($data);
        $event = CalendarEvent::query()->create(array_merge($payload, [
            'profile_id' => $profile->id,
        ]));

        return $this->formatEvent($event);
    }

    public function update(CalendarEvent $event, PortfolioProfile $profile, array $data): array
    {
        $this->assertBelongsToProfile($event, $profile);
        $payload = $this->normalizePayload($data, $event);
        $event->fill($payload);
        $event->save();

        return $this->formatEvent($event->fresh());
    }

    public function delete(CalendarEvent $event, PortfolioProfile $profile): void
    {
        $this->assertBelongsToProfile($event, $profile);
        $event->delete();
    }

    public function assertBelongsToProfile(CalendarEvent $event, PortfolioProfile $profile): void
    {
        if ((int) $event->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'event' => ['Calendar event not found for this portfolio.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function formatEvent(CalendarEvent $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'color' => $event->color,
            'anchor_date' => $event->anchor_date?->toDateString(),
            'recurrence_type' => $event->recurrence_type,
            'recurrence_config' => $event->recurrence_config ?? [],
            'recurrence_end_date' => $event->recurrence_end_date?->toDateString(),
            'reminder_enabled' => (bool) $event->reminder_enabled,
            'reminder_days_before' => $event->reminder_days_before ?? [],
            'is_active' => (bool) $event->is_active,
            'created_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(array $data, ?CalendarEvent $existing = null): array
    {
        $recurrenceType = $data['recurrence_type'] ?? $existing?->recurrence_type ?? CalendarEvent::RECURRENCE_NONE;
        $config = $data['recurrence_config'] ?? $existing?->recurrence_config ?? [];
        if (! is_array($config)) {
            $config = [];
        }

        $reminderDays = $data['reminder_days_before'] ?? $existing?->reminder_days_before ?? [];
        if (! is_array($reminderDays)) {
            $reminderDays = [];
        }
        $reminderDays = array_values(array_unique(array_map(
            fn ($value) => max(0, (int) $value),
            $reminderDays,
        )));
        sort($reminderDays);

        $color = $data['color'] ?? $existing?->color ?? '#6366f1';
        if (! is_string($color) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            throw ValidationException::withMessages([
                'color' => ['Color must be a hex value like #6366f1.'],
            ]);
        }

        return [
            'title' => trim((string) ($data['title'] ?? $existing?->title ?? '')),
            'description' => isset($data['description'])
                ? (trim((string) $data['description']) ?: null)
                : $existing?->description,
            'color' => strtolower($color),
            'anchor_date' => $data['anchor_date'] ?? $existing?->anchor_date?->toDateString(),
            'recurrence_type' => $recurrenceType,
            'recurrence_config' => $config,
            'recurrence_end_date' => array_key_exists('recurrence_end_date', $data)
                ? ($data['recurrence_end_date'] ?: null)
                : $existing?->recurrence_end_date?->toDateString(),
            'reminder_enabled' => (bool) ($data['reminder_enabled'] ?? $existing?->reminder_enabled ?? false),
            'reminder_days_before' => $reminderDays,
            'is_active' => (bool) ($data['is_active'] ?? $existing?->is_active ?? true),
        ];
    }
}
