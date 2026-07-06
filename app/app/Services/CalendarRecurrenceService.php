<?php

namespace App\Services;

use App\Models\CalendarEvent;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class CalendarRecurrenceService
{
    /**
     * @return array<int, array{date: string, event_id: int, title: string, color: string, description: ?string}>
     */
    public function occurrencesForEvent(CalendarEvent $event, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        if (! $event->is_active) {
            return [];
        }

        $anchor = $event->anchor_date->copy()->startOfDay();
        $endDate = $event->recurrence_end_date?->copy()->endOfDay();

        if ($endDate !== null && $endDate->lt($from)) {
            return [];
        }

        $effectiveTo = $endDate !== null && $endDate->lt($to) ? $endDate : $to;

        if ($event->recurrence_type === CalendarEvent::RECURRENCE_NONE) {
            if ($anchor->betweenIncluded($from, $effectiveTo)) {
                return [$this->formatOccurrence($event, $anchor)];
            }

            return [];
        }

        $dates = match ($event->recurrence_type) {
            CalendarEvent::RECURRENCE_DAILY => $this->expandDaily($event, $anchor, $from, $effectiveTo),
            CalendarEvent::RECURRENCE_WEEKLY => $this->expandWeekly($event, $anchor, $from, $effectiveTo),
            CalendarEvent::RECURRENCE_MONTHLY_DAY => $this->expandMonthlyDay($event, $anchor, $from, $effectiveTo),
            CalendarEvent::RECURRENCE_MONTHLY_WEEKDAY => $this->expandMonthlyWeekday($event, $anchor, $from, $effectiveTo),
            CalendarEvent::RECURRENCE_YEARLY_DAY => $this->expandYearlyDay($event, $anchor, $from, $effectiveTo),
            CalendarEvent::RECURRENCE_YEARLY_WEEKDAY => $this->expandYearlyWeekday($event, $anchor, $from, $effectiveTo),
            default => [],
        };

        return array_values(array_map(
            fn (Carbon $date) => $this->formatOccurrence($event, $date),
            $dates,
        ));
    }

    /**
     * @param  iterable<CalendarEvent>  $events
     * @return array<int, array{date: string, event_id: int, title: string, color: string, description: ?string}>
     */
    public function occurrencesForEvents(iterable $events, Carbon $from, Carbon $to): array
    {
        $all = [];
        foreach ($events as $event) {
            foreach ($this->occurrencesForEvent($event, $from, $to) as $occurrence) {
                $all[] = $occurrence;
            }
        }

        usort($all, fn ($a, $b) => [$a['date'], $a['title']] <=> [$b['date'], $b['title']]);

        return $all;
    }

    /**
     * @return array<int, Carbon>
     */
    private function expandDaily(CalendarEvent $event, Carbon $anchor, Carbon $from, Carbon $to): array
    {
        $interval = max(1, (int) ($event->recurrence_config['interval'] ?? 1));
        $start = $anchor->lt($from) ? $from->copy() : $anchor->copy();
        if ($anchor->lt($from)) {
            $daysDiff = $anchor->diffInDays($from);
            $offset = $daysDiff % $interval;
            if ($offset > 0) {
                $start->addDays($interval - $offset);
            }
        }

        $dates = [];
        $period = CarbonPeriod::create($start, "{$interval} days", $to);
        foreach ($period as $date) {
            if ($date->gte($anchor)) {
                $dates[] = $date->copy()->startOfDay();
            }
        }

        return $dates;
    }

    /**
     * @return array<int, Carbon>
     */
    private function expandWeekly(CalendarEvent $event, Carbon $anchor, Carbon $from, Carbon $to): array
    {
        $interval = max(1, (int) ($event->recurrence_config['interval'] ?? 1));
        $weekday = $this->weekday($event, $anchor);
        $dates = [];
        $cursor = $from->copy()->startOfDay();
        $anchorWeekStart = $anchor->copy()->startOfWeek();

        while ($cursor->lte($to)) {
            if ($cursor->dayOfWeek === $weekday && $cursor->gte($anchor)) {
                $weekIndex = (int) floor($anchorWeekStart->diffInDays($cursor->copy()->startOfWeek()) / 7);
                if ($weekIndex % $interval === 0) {
                    $dates[] = $cursor->copy();
                }
            }
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * @return array<int, Carbon>
     */
    private function expandMonthlyDay(CalendarEvent $event, Carbon $anchor, Carbon $from, Carbon $to): array
    {
        $monthDay = (int) ($event->recurrence_config['month_day'] ?? $anchor->day);
        $interval = max(1, (int) ($event->recurrence_config['interval'] ?? 1));
        $cursor = $from->copy()->startOfMonth();
        $dates = [];

        while ($cursor->lte($to)) {
            $monthsSince = $this->monthsSince($anchor, $cursor);
            if ($monthsSince >= 0 && $monthsSince % $interval === 0) {
                $candidate = $this->clampDayOfMonth($cursor->year, $cursor->month, $monthDay);
                if ($candidate !== null
                    && $candidate->betweenIncluded($from, $to)
                    && $candidate->gte($anchor)) {
                    $dates[] = $candidate;
                }
            }
            $cursor->addMonth()->startOfMonth();
        }

        return $dates;
    }

    /**
     * @return array<int, Carbon>
     */
    private function expandMonthlyWeekday(CalendarEvent $event, Carbon $anchor, Carbon $from, Carbon $to): array
    {
        $weekday = $this->weekday($event, $anchor);
        $weekOfMonth = (int) ($event->recurrence_config['week_of_month'] ?? 1);
        $interval = max(1, (int) ($event->recurrence_config['interval'] ?? 1));
        $cursor = $from->copy()->startOfMonth();
        $dates = [];

        while ($cursor->lte($to)) {
            $monthsSince = $this->monthsSince($anchor, $cursor);
            if ($monthsSince >= 0 && $monthsSince % $interval === 0) {
                $candidate = $this->nthWeekdayOfMonth($cursor->year, $cursor->month, $weekday, $weekOfMonth);
                if ($candidate !== null
                    && $candidate->betweenIncluded($from, $to)
                    && $candidate->gte($anchor)) {
                    $dates[] = $candidate;
                }
            }
            $cursor->addMonth()->startOfMonth();
        }

        return $dates;
    }

    /**
     * @return array<int, Carbon>
     */
    private function expandYearlyDay(CalendarEvent $event, Carbon $anchor, Carbon $from, Carbon $to): array
    {
        $month = (int) ($event->recurrence_config['month'] ?? $anchor->month);
        $monthDay = (int) ($event->recurrence_config['month_day'] ?? $anchor->day);
        $interval = max(1, (int) ($event->recurrence_config['interval'] ?? 1));
        $dates = [];

        for ($year = $from->year; $year <= $to->year; $year++) {
            $yearsSince = $year - $anchor->year;
            if ($yearsSince < 0 || $yearsSince % $interval !== 0) {
                continue;
            }
            $candidate = $this->clampDayOfMonth($year, $month, $monthDay);
            if ($candidate !== null
                && $candidate->betweenIncluded($from, $to)
                && $candidate->gte($anchor)) {
                $dates[] = $candidate;
            }
        }

        return $dates;
    }

    /**
     * @return array<int, Carbon>
     */
    private function expandYearlyWeekday(CalendarEvent $event, Carbon $anchor, Carbon $from, Carbon $to): array
    {
        $month = (int) ($event->recurrence_config['month'] ?? $anchor->month);
        $weekday = $this->weekday($event, $anchor);
        $weekOfMonth = (int) ($event->recurrence_config['week_of_month'] ?? 1);
        $interval = max(1, (int) ($event->recurrence_config['interval'] ?? 1));
        $dates = [];

        for ($year = $from->year; $year <= $to->year; $year++) {
            $yearsSince = $year - $anchor->year;
            if ($yearsSince < 0 || $yearsSince % $interval !== 0) {
                continue;
            }
            $candidate = $this->nthWeekdayOfMonth($year, $month, $weekday, $weekOfMonth);
            if ($candidate !== null
                && $candidate->betweenIncluded($from, $to)
                && $candidate->gte($anchor)) {
                $dates[] = $candidate;
            }
        }

        return $dates;
    }

    private function weekday(CalendarEvent $event, Carbon $anchor): int
    {
        $weekday = $event->recurrence_config['weekday'] ?? null;
        if ($weekday !== null && is_numeric($weekday)) {
            return ((int) $weekday) % 7;
        }

        return $anchor->dayOfWeek;
    }

    private function nthWeekdayOfMonth(int $year, int $month, int $weekday, int $weekOfMonth): ?Carbon
    {
        if ($weekOfMonth === -1) {
            $date = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();
            while ($date->dayOfWeek !== $weekday) {
                $date->subDay();
            }

            return $date;
        }

        $date = Carbon::create($year, $month, 1)->startOfDay();
        while ($date->dayOfWeek !== $weekday) {
            $date->addDay();
        }
        if ($weekOfMonth > 1) {
            $date->addWeeks($weekOfMonth - 1);
        }
        if ($date->month !== $month) {
            return null;
        }

        return $date;
    }

    private function clampDayOfMonth(int $year, int $month, int $day): ?Carbon
    {
        $lastDay = Carbon::create($year, $month, 1)->endOfMonth()->day;
        $effectiveDay = min(max(1, $day), $lastDay);

        return Carbon::create($year, $month, $effectiveDay)->startOfDay();
    }

    private function monthsSince(Carbon $anchor, Carbon $date): int
    {
        return (($date->year - $anchor->year) * 12) + ($date->month - $anchor->month);
    }

    /**
     * @return array{date: string, event_id: int, title: string, color: string, description: ?string}
     */
    private function formatOccurrence(CalendarEvent $event, Carbon $date): array
    {
        return [
            'date' => $date->toDateString(),
            'event_id' => $event->id,
            'title' => $event->title,
            'color' => $event->color,
            'description' => $event->description,
            'recurrence_type' => $event->recurrence_type,
            'recurrence_config' => $event->recurrence_config ?? [],
        ];
    }
}
