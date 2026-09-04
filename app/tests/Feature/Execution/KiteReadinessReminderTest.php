<?php

namespace Tests\Feature\Execution;

use App\Models\PortfolioProfile;
use App\Models\User;
use App\Services\Broker\KiteReadinessReminderService;
use App\Services\ProfileSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KiteReadinessReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_unusable_automatic_portfolio_is_reminded_at_most_once_per_day(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $profile->forceFill(['execution_mode' => PortfolioProfile::EXECUTION_MODE_AUTOMATIC])->save();
        app(ProfileSettingsService::class)->update($profile, [
            'telegram_bot_token' => 'test-token',
            'telegram_chat_id' => '12345',
            'notifications_enabled' => 'true',
            'kite_readiness_reminder_time' => '08:30',
        ]);
        config(['broker.kite.api_key' => 'key', 'broker.kite.api_secret' => 'secret']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $now = Carbon::parse('2026-09-05 08:30:00', 'Asia/Kolkata');

        $first = app(KiteReadinessReminderService::class)->sendDue($now);
        $second = app(KiteReadinessReminderService::class)->sendDue($now->copy()->addSeconds(20));

        $this->assertSame(1, $first['sent']);
        $this->assertSame(0, $second['sent']);
        Http::assertSentCount(1);
    }

    public function test_wrong_time_and_non_automatic_portfolios_are_silent(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(ProfileSettingsService::class)->set($profile, 'kite_readiness_reminder_time', '08:30');
        Http::fake();

        $result = app(KiteReadinessReminderService::class)->sendDue(
            Carbon::parse('2026-09-05 09:00:00', 'Asia/Kolkata'),
        );

        $this->assertSame(0, $result['sent']);
        Http::assertNothingSent();
    }
}
