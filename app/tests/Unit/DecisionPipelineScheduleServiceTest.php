<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\DecisionPipelineScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionPipelineScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-08 19:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_automatic_run_not_recorded_initially(): void
    {
        $service = app(DecisionPipelineScheduleService::class);

        $this->assertFalse($service->hasAutomaticRunToday());
        $this->assertFalse($service->shouldSkipAutomatic('scheduled'));
        $this->assertFalse($service->shouldSkipAutomatic('post-sync'));
    }

    public function test_mark_automatic_run_blocks_subsequent_automatic_triggers(): void
    {
        $service = app(DecisionPipelineScheduleService::class);
        $service->markAutomaticRunToday('post-sync');

        $this->assertTrue($service->hasAutomaticRunToday());
        $this->assertSame('post-sync', $service->lastAutomaticTrigger());
        $this->assertTrue($service->shouldSkipAutomatic('scheduled'));
        $this->assertTrue($service->shouldSkipAutomatic('post-sync'));
        $this->assertFalse($service->shouldSkipAutomatic('manual'));
    }

    public function test_automatic_guard_resets_on_next_calendar_day(): void
    {
        $service = app(DecisionPipelineScheduleService::class);
        $service->markAutomaticRunToday('scheduled');

        Carbon::setTestNow(Carbon::parse('2026-08-09 19:00:00', 'Asia/Kolkata'));

        $this->assertFalse($service->hasAutomaticRunToday());
    }

    public function test_persists_settings_keys(): void
    {
        app(DecisionPipelineScheduleService::class)->markAutomaticRunToday('scheduled');

        $this->assertSame('2026-08-08', Setting::getValue(DecisionPipelineScheduleService::KEY_AUTO_DATE));
        $this->assertSame('scheduled', Setting::getValue(DecisionPipelineScheduleService::KEY_AUTO_TRIGGER));
        $this->assertNotEmpty(Setting::getValue(DecisionPipelineScheduleService::KEY_AUTO_AT));
    }

    public function test_mark_profile_successful_tracks_ids_for_current_day(): void
    {
        $service = app(DecisionPipelineScheduleService::class);
        $service->markProfileSuccessfulToday(10);
        $service->markProfileSuccessfulToday(20);

        $this->assertSame([10, 20], $service->successfulProfileIdsToday());
        $this->assertTrue($service->hasProfileSucceededToday(10));
        $this->assertTrue($service->shouldSkipProfileForAutomatic(10, 'scheduled'));
        $this->assertFalse($service->shouldSkipProfileForAutomatic(10, 'manual'));
    }

    public function test_successful_profile_markers_reset_on_next_calendar_day(): void
    {
        $service = app(DecisionPipelineScheduleService::class);
        $service->markProfileSuccessfulToday(5);

        Carbon::setTestNow(Carbon::parse('2026-08-09 19:00:00', 'Asia/Kolkata'));

        $this->assertFalse($service->hasProfileSucceededToday(5));
        $this->assertSame('2026-08-09', $service->todayDateString());
    }

    public function test_all_profiles_succeeded_requires_every_requested_profile(): void
    {
        $service = app(DecisionPipelineScheduleService::class);
        $service->markProfileSuccessfulToday(1);
        $service->markProfileSuccessfulToday(2);

        $this->assertTrue($service->allProfilesSucceededToday([1, 2]));
        $this->assertFalse($service->allProfilesSucceededToday([1, 2, 3]));
    }
}
