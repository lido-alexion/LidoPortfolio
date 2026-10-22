<?php

namespace App\Services\Fundamentals\Scheduling;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class BootstrapScheduleRecurrence
{
    /** Resolve one local calendar occurrence. DST gaps use PHP/Carbon wall-time normalization;
     * ambiguous fall-back times use PHP/Carbon's timezone resolution. Occurrence identity is stored in UTC.
     */
    public function next(array $rule, ?CarbonImmutable $afterLocal = null): ?CarbonImmutable
    {
        $zone = $rule['timezone'];
        $start = CarbonImmutable::parse($rule['starts_at_local'], $zone);
        $candidate = $afterLocal ? $this->advance($afterLocal, $rule) : $start;
        if ($candidate->lt($start)) $candidate = $start;
        if (! $afterLocal) $candidate = $this->align($candidate, $rule);
        if (isset($rule['ends_on_local']) && $candidate->toDateString() > $rule['ends_on_local']) return null;
        return $candidate;
    }

    /** Return the first recurrence strictly after a local wall-clock boundary. */
    public function nextAfter(array $rule, CarbonImmutable $afterLocal): ?CarbonImmutable
    {
        $first = $this->next($rule);
        if (! $first || $first->gt($afterLocal)) return $first;
        $step = max(1, (int) $rule['interval']);
        if ($rule['frequency'] === 'daily') {
            $days = $this->calendarDayDistance($first, $afterLocal);
            $periods = intdiv(max(0, $days), $step);
            $candidate = $first->addDays($periods * $step)->setTimeFromTimeString($rule['local_time']);
            if (! $candidate->gt($afterLocal)) $candidate = $candidate->addDays($step)->setTimeFromTimeString($rule['local_time']);
        } elseif ($rule['frequency'] === 'weekly') {
            $days = $this->calendarDayDistance($first, $afterLocal);
            $periods = intdiv(max(0, $days), 7 * $step);
            $candidate = $first->addWeeks($periods * $step)->setTimeFromTimeString($rule['local_time']);
            if (! $candidate->gt($afterLocal)) $candidate = $candidate->addWeeks($step)->setTimeFromTimeString($rule['local_time']);
        } else {
            $months = max(0, (((int) $afterLocal->format('Y') - (int) $first->format('Y')) * 12)
                + (int) $afterLocal->format('n') - (int) $first->format('n'));
            $periods = intdiv($months, $step);
            $candidateMonth = $first->startOfMonth()->addMonthsNoOverflow($periods * $step);
            $candidate = $candidateMonth->setDay(min((int) $rule['monthday'], $candidateMonth->daysInMonth))->setTimeFromTimeString($rule['local_time']);
            if (! $candidate->gt($afterLocal)) {
                $candidateMonth = $candidateMonth->addMonthsNoOverflow($step);
                $candidate = $candidateMonth->setDay(min((int) $rule['monthday'], $candidateMonth->daysInMonth))->setTimeFromTimeString($rule['local_time']);
            }
        }
        if (isset($rule['ends_on_local']) && $candidate->toDateString() > $rule['ends_on_local']) return null;
        return $candidate;
    }

    private function calendarDayDistance(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) CarbonImmutable::parse($from->toDateString(), 'UTC')->diffInDays(CarbonImmutable::parse($to->toDateString(), 'UTC'));
    }

    private function advance(CarbonImmutable $from, array $rule): CarbonImmutable
    {
        $n = max(1, (int) $rule['interval']);
        return match ($rule['frequency']) {
            'daily' => $from->addDays($n)->setTimeFromTimeString($rule['local_time']),
            'weekly' => $from->addWeeks($n)->setTimeFromTimeString($rule['local_time']),
            'monthly' => $from->startOfMonth()->addMonthsNoOverflow($n)
                ->setDay(min((int) ($rule['monthday'] ?? $from->day), $from->startOfMonth()->addMonthsNoOverflow($n)->daysInMonth))
                ->setTimeFromTimeString($rule['local_time']),
            default => throw ValidationException::withMessages(['frequency' => 'Frequency must be daily, weekly, or monthly.']),
        };
    }

    private function align(CarbonImmutable $date, array $rule): CarbonImmutable
    {
        if ($rule['frequency'] === 'weekly') {
            $days = ((int) $rule['weekday'] - (int) $date->dayOfWeekIso + 7) % 7;
            $candidate = $date->addDays($days)->setTimeFromTimeString($rule['local_time']);
            return $candidate->lt($date) ? $candidate->addWeeks(max(1, (int) $rule['interval'])) : $candidate;
        }
        if ($rule['frequency'] === 'monthly') {
            $day = min((int) $rule['monthday'], $date->daysInMonth);
            $candidate = $date->setDay($day)->setTimeFromTimeString($rule['local_time']);
            return $candidate->lt($date) ? $candidate->addMonthsNoOverflow(1)->setDay(min((int) $rule['monthday'], $candidate->addMonthsNoOverflow(1)->daysInMonth)) : $candidate;
        }
        $candidate = $date->setTimeFromTimeString($rule['local_time']);
        return $candidate->lt($date) ? $candidate->addDays(max(1, (int) $rule['interval'])) : $candidate;
    }
}
