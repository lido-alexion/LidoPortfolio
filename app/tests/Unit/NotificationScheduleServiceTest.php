<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\NotificationScheduleService;
use App\Services\ProfileSettingsService;
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

    public function test_normalizes_and_persists_schedules_for_profile(): void
    {
        $user = $this->makeUser();
        $profile = $this->defaultPortfolioFor($user);
        $service = app(NotificationScheduleService::class);

        $saved = $service->persistForProfile($profile, ['18:30', '9:00', 'invalid', '25:99']);

        $this->assertSame(['09:00', '18:30'], $saved);
        $this->assertSame(['09:00', '18:30'], $service->schedulesForProfile($profile));
    }

    public function test_distinct_schedules_across_profiles(): void
    {
        $userA = $this->makeUser();
        $profileA = $this->defaultPortfolioFor($userA);
        $userB = $this->makeUser();
        $profileB = $this->defaultPortfolioFor($userB);
        $service = app(NotificationScheduleService::class);

        $service->persistForProfile($profileA, ['09:00', '18:00']);
        $service->persistForProfile($profileB, ['18:00', '21:30']);

        $this->assertSame(['09:00', '18:00', '21:30'], $service->distinctSchedulesAcrossProfiles());
    }

    public function test_settings_service_returns_profile_notification_schedules(): void
    {
        $user = $this->makeUser();
        $profile = $this->defaultPortfolioFor($user);
        app(NotificationScheduleService::class)->persistForProfile($profile, ['10:15', '20:00']);

        $settings = app(ProfileSettingsService::class)->all($profile);

        $this->assertSame(['10:15', '20:00'], $settings['notification_schedules']);
    }
}
