<?php

namespace Tests\Unit;

use App\Services\NotificationScheduleService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_and_persists_schedules(): void
    {
        $service = app(NotificationScheduleService::class);

        $saved = $service->persist(['18:30', '9:00', 'invalid', '25:99']);

        $this->assertSame(['09:00', '18:30'], $saved);
        $this->assertSame(['09:00', '18:30'], $service->schedules());
    }

    public function test_settings_api_returns_notification_schedules_array(): void
    {
        app(NotificationScheduleService::class)->persist(['10:15', '20:00']);

        $settings = app(SettingsService::class)->all();

        $this->assertSame(['10:15', '20:00'], $settings['notification_schedules']);
    }
}
