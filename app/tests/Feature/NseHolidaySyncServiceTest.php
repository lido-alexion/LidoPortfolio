<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\CalendarEventService;
use App\Services\NseHolidaySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NseHolidaySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_imports_cm_holidays_and_preserves_admin_override(): void
    {
        Http::fake([NseHolidaySyncService::ENDPOINT => Http::response(['CM' => [[
            'tradingDate' => '26-Jan-2027',
            'weekDay' => 'Tuesday',
            'description' => 'Republic Day',
        ]]])]);

        $first = app(NseHolidaySyncService::class)->sync();
        $event = CalendarEvent::query()->where('external_key', 'CM:2027-01-26')->firstOrFail();
        $this->assertSame(1, $first['created']);
        $this->assertTrue($event->isTradeHoliday());
        $this->assertSame('nse', $event->source);

        $profile = $this->defaultPortfolioFor(User::factory()->create());
        app(CalendarEventService::class)->update($event, $profile, ['title' => 'Republic Day — corrected'], true);
        $second = app(NseHolidaySyncService::class)->sync();

        $this->assertSame(1, $second['overridden']);
        $this->assertSame('Republic Day — corrected', $event->fresh()->title);
        $this->assertTrue($event->fresh()->sync_override);
    }

    public function test_successful_feed_deactivates_removed_non_overridden_rows(): void
    {
        CalendarEvent::query()->create([
            'profile_id' => null,
            'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'source' => 'nse',
            'external_key' => 'CM:2027-02-01',
            'title' => 'Withdrawn holiday',
            'anchor_date' => '2027-02-01',
            'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'is_active' => true,
        ]);
        Http::fake([NseHolidaySyncService::ENDPOINT => Http::response(['CM' => []])]);

        $result = app(NseHolidaySyncService::class)->sync();

        $this->assertSame(1, $result['deactivated']);
        $this->assertFalse(CalendarEvent::query()->where('external_key', 'CM:2027-02-01')->value('is_active'));
    }
}
