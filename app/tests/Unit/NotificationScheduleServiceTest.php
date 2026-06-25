<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\NotificationScheduleService;
use App\Services\UserSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Schedule User',
            'email' => 'schedule-'.Str::random(8).'@example.com',
            'password' => Hash::make('password123'),
        ]);
    }

    public function test_normalizes_and_persists_schedules_for_user(): void
    {
        $user = $this->makeUser();
        $service = app(NotificationScheduleService::class);

        $saved = $service->persistForUser($user, ['18:30', '9:00', 'invalid', '25:99']);

        $this->assertSame(['09:00', '18:30'], $saved);
        $this->assertSame(['09:00', '18:30'], $service->schedulesForUser($user));
    }

    public function test_distinct_schedules_across_users(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $service = app(NotificationScheduleService::class);

        $service->persistForUser($userA, ['09:00', '18:00']);
        $service->persistForUser($userB, ['18:00', '21:30']);

        $this->assertSame(['09:00', '18:00', '21:30'], $service->distinctSchedulesAcrossUsers());
    }

    public function test_settings_api_returns_user_notification_schedules(): void
    {
        $user = $this->makeUser();
        app(NotificationScheduleService::class)->persistForUser($user, ['10:15', '20:00']);

        $settings = app(UserSettingsService::class)->all($user);

        $this->assertSame(['10:15', '20:00'], $settings['notification_schedules']);
    }
}
