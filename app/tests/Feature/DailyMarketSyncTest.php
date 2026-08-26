<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\DailyMarketSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyMarketSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-29 14:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_daily_sync_endpoint_skips_when_already_synced_today(): void
    {
        $user = User::query()->create([
            'name' => 'Sync User',
            'email' => 'sync-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $user->is_admin = true;
        $user->save();

        app(DailyMarketSyncService::class)->markSuccessful();

        $response = $this->actingAs($user)->postJson('/api/sync/daily');

        $response->assertOk()
            ->assertJsonPath('skipped', true)
            ->assertJsonPath('synced_today', true);
    }

    public function test_force_daily_sync_runs_even_when_already_synced_today(): void
    {
        $user = User::query()->create([
            'name' => 'Force Sync User',
            'email' => 'force-sync-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $user->is_admin = true;
        $user->save();

        app(DailyMarketSyncService::class)->markSuccessful();

        $response = $this->actingAs($user)->postJson('/api/sync/daily', ['force' => true]);

        $response->assertOk()
            ->assertJsonPath('skipped', false);
    }

    public function test_dashboard_includes_daily_market_sync_status_for_admin(): void
    {
        $user = User::query()->create([
            'name' => 'Dash Sync',
            'email' => 'dash-sync-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $user->is_admin = true;
        $user->save();

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'daily_market_sync' => [
                    'synced_today',
                    'sync_date',
                    'synced_at',
                    'today',
                    'timezone',
                    'in_progress',
                ],
            ]);
    }

    public function test_dashboard_omits_daily_market_sync_for_non_admin(): void
    {
        $user = User::query()->create([
            'name' => 'Dash User',
            'email' => 'dash-user-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $this->actingAs($user)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonMissingPath('daily_market_sync');
    }

    public function test_mark_incomplete_preserves_last_successful_sync_timestamp(): void
    {
        $sync = app(DailyMarketSyncService::class);
        $sync->markSuccessful();
        $successfulAt = $sync->lastSuccessfulSyncAt();
        $this->assertNotNull($successfulAt);

        $sync->markIncomplete(3, 2);

        $this->assertFalse($sync->hasSyncedSuccessfullyToday());
        $preserved = $sync->lastSuccessfulSyncAt();
        $this->assertNotNull($preserved);
        $this->assertTrue($successfulAt->equalTo($preserved));
    }

    public function test_legacy_incomplete_timestamp_is_not_treated_as_successful_sync(): void
    {
        Setting::setValue(
            DailyMarketSyncService::KEY_SYNCED_AT,
            Carbon::now('Asia/Kolkata')->toIso8601String().';processed=3;failed=2',
        );

        $this->assertNull(app(DailyMarketSyncService::class)->lastSuccessfulSyncAt());
    }
}
