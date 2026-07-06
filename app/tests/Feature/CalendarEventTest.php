<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalendarEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_manage_calendar_events_and_fetch_occurrences(): void
    {
        $user = User::query()->create([
            'name' => 'Calendar API User',
            'email' => 'cal-api-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $create = $this->postJson('/api/calendar/events', [
            'title' => 'Options expiry',
            'color' => '#2563eb',
            'anchor_date' => '2026-01-01',
            'recurrence_type' => 'monthly_weekday',
            'recurrence_config' => ['week_of_month' => -1, 'weekday' => 4],
            'reminder_enabled' => true,
            'reminder_days_before' => [0, 3],
        ]);
        $create->assertCreated();
        $eventId = $create->json('data.id');

        $this->getJson('/api/calendar/events')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $occurrences = $this->getJson('/api/calendar/occurrences?from=2026-01-01&to=2026-03-31');
        $occurrences->assertOk();
        $this->assertNotEmpty($occurrences->json('data'));

        $upcoming = $this->getJson('/api/calendar/upcoming');
        $upcoming->assertOk();

        $this->putJson("/api/calendar/events/{$eventId}", [
            'title' => 'F&O expiry',
        ])->assertOk()->assertJsonPath('data.title', 'F&O expiry');

        $this->deleteJson("/api/calendar/events/{$eventId}")
            ->assertOk();

        $this->assertDatabaseMissing('portfolio_calendar_events', ['id' => $eventId]);
    }

    public function test_calendar_event_scoped_to_active_portfolio(): void
    {
        $user = User::query()->create([
            'name' => 'Calendar Scope User',
            'email' => 'cal-scope-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $event = CalendarEvent::query()->create([
            'profile_id' => $profile->id,
            'title' => 'Private event',
            'color' => '#6366f1',
            'anchor_date' => '2026-07-01',
            'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'reminder_enabled' => false,
            'is_active' => true,
        ]);

        $other = User::query()->create([
            'name' => 'Other User',
            'email' => 'other-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($other);
        $this->actingAs($other);

        $this->putJson("/api/calendar/events/{$event->id}", [
            'title' => 'Hacked',
        ])->assertNotFound();
    }
}
