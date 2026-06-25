<?php

namespace Tests\Feature;

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
}
