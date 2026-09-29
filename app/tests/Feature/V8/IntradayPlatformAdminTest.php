<?php

namespace Tests\Feature\V8;

use App\Models\User;
use App\Models\V8\IntradayBackfillCheckpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntradayPlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_intraday_platform_status(): void
    {
        config(['intraday_ml_platform.enabled' => true]);

        IntradayBackfillCheckpoint::query()->create([
            'symbol' => 'RELIANCE',
            'exchange' => 'NSE',
            'window_start' => '2024-01-01',
            'window_end' => '2024-01-31',
            'status' => 'complete',
            'bars_written' => 12000,
            'completed_at' => now(),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/intraday-platform')
            ->assertOk()
            ->assertJsonPath('data.universe', 'nifty500')
            ->assertJsonPath('data.checkpoint_counts.complete', 1);
    }

    public function test_admin_pause_and_resume_are_durable(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($admin);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/intraday-platform/pause')
            ->assertOk()
            ->assertJsonPath('data.paused', true);

        $this->getJson('/api/v1/admin/intraday-platform')
            ->assertOk()
            ->assertJsonPath('data.paused', true);

        $this->postJson('/api/v1/admin/intraday-platform/resume')
            ->assertOk()
            ->assertJsonPath('data.paused', false);
    }

    public function test_investor_cannot_control_intraday_backfill(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)
            ->postJson('/api/v1/admin/intraday-platform/pause')
            ->assertForbidden();
    }
}
