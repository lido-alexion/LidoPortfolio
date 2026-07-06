<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\CalendarReminderSend;
use Carbon\Carbon;

class CalendarReminderService
{
    public function __construct(
        protected CalendarRecurrenceService $recurrence,
        protected TelegramNotificationService $telegram,
    ) {}

    /**
     * @return array{sent: int, skipped: int, profiles_notified: int}
     */
    public function sendDueReminders(?Carbon $onDate = null): array
    {
        $today = ($onDate ?? Carbon::today())->copy()->startOfDay();
        $sent = 0;
        $skipped = 0;
        $profilesNotified = [];

        $events = CalendarEvent::query()
            ->where('is_active', true)
            ->where('reminder_enabled', true)
            ->with('profile')
            ->get();

        foreach ($events as $event) {
            $daysBeforeList = $this->normalizeReminderDays($event);
            if ($daysBeforeList === []) {
                $skipped++;
                continue;
            }

            foreach ($daysBeforeList as $daysBefore) {
                $occurrenceDate = $today->copy()->addDays($daysBefore);
                $occurrences = $this->recurrence->occurrencesForEvent(
                    $event,
                    $occurrenceDate,
                    $occurrenceDate,
                );

                if ($occurrences === []) {
                    continue;
                }

                $occurrence = $occurrences[0];
                if ($this->alreadySent($event->id, $occurrence['date'], $daysBefore)) {
                    $skipped++;

                    continue;
                }

                $profile = $event->profile;
                if ($profile === null) {
                    $skipped++;

                    continue;
                }

                $message = $this->buildMessage($event, $occurrence['date'], $daysBefore);
                if ($this->telegram->sendMessageForProfile($profile, $message)) {
                    CalendarReminderSend::query()->create([
                        'event_id' => $event->id,
                        'occurrence_date' => $occurrence['date'],
                        'days_before' => $daysBefore,
                        'sent_at' => now(),
                    ]);
                    $sent++;
                    $profilesNotified[$profile->id] = true;
                } else {
                    $skipped++;
                }
            }
        }

        return [
            'sent' => $sent,
            'skipped' => $skipped,
            'profiles_notified' => count($profilesNotified),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function normalizeReminderDays(CalendarEvent $event): array
    {
        $days = $event->reminder_days_before ?? [];
        if (! is_array($days) || $days === []) {
            return [0];
        }

        return array_values(array_unique(array_map(
            fn ($value) => max(0, (int) $value),
            $days,
        )));
    }

    private function alreadySent(int $eventId, string $occurrenceDate, int $daysBefore): bool
    {
        return CalendarReminderSend::query()
            ->where('event_id', $eventId)
            ->whereDate('occurrence_date', $occurrenceDate)
            ->where('days_before', $daysBefore)
            ->exists();
    }

    private function buildMessage(CalendarEvent $event, string $occurrenceDate, int $daysBefore): string
    {
        $dateLabel = Carbon::parse($occurrenceDate)->format('d M Y');
        if ($daysBefore === 0) {
            return "Calendar reminder: {$event->title} is today ({$dateLabel}).";
        }

        return "Calendar reminder: {$event->title} is in {$daysBefore} day(s) on {$dateLabel}.";
    }
}
