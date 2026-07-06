<?php

namespace Tests\Unit;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\CalendarRecurrenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalendarRecurrenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_last_thursday_occurrence(): void
    {
        $user = User::query()->create([
            'name' => 'Calendar User',
            'email' => 'cal-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $event = CalendarEvent::query()->create([
            'profile_id' => $profile->id,
            'title' => 'F&O expiry',
            'color' => '#ea580c',
            'anchor_date' => '2026-01-01',
            'recurrence_type' => CalendarEvent::RECURRENCE_MONTHLY_WEEKDAY,
            'recurrence_config' => ['week_of_month' => -1, 'weekday' => 4],
            'reminder_enabled' => false,
            'is_active' => true,
        ]);

        $service = app(CalendarRecurrenceService::class);
        $occurrences = $service->occurrencesForEvent(
            $event,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-03-31'),
        );

        $dates = array_column($occurrences, 'date');
        $this->assertContains('2026-01-29', $dates);
        $this->assertContains('2026-02-26', $dates);
        $this->assertContains('2026-03-26', $dates);
    }

    public function test_yearly_fixed_date_occurrence(): void
    {
        $user = User::query()->create([
            'name' => 'Calendar User 2',
            'email' => 'cal2-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $event = CalendarEvent::query()->create([
            'profile_id' => $profile->id,
            'title' => 'Budget day',
            'color' => '#2563eb',
            'anchor_date' => '2024-02-01',
            'recurrence_type' => CalendarEvent::RECURRENCE_YEARLY_DAY,
            'recurrence_config' => ['month' => 2, 'month_day' => 1],
            'reminder_enabled' => false,
            'is_active' => true,
        ]);

        $service = app(CalendarRecurrenceService::class);
        $occurrences = $service->occurrencesForEvent(
            $event,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2027-12-31'),
        );

        $dates = array_column($occurrences, 'date');
        $this->assertContains('2026-02-01', $dates);
        $this->assertContains('2027-02-01', $dates);
        $this->assertNotContains('2026-03-01', $dates);
    }
}
