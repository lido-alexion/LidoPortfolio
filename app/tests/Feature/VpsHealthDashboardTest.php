<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VpsHealthSample;
use App\Services\VpsHealth\VpsHealthSampleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VpsHealthDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_sample_is_reduced_to_aggregate_fields_and_visible_to_admin(): void
    {
        app(VpsHealthSampleService::class)->record([
            'time' => now()->toIso8601String(),
            'status' => 'critical',
            'issues' => ['FPM listen queue is nonzero'],
            'metrics' => [
                'load1' => 2.1,
                'load_per_core' => 1.05,
                'ram_available_percent' => 22.4,
                'ram_available_bytes' => 2400000000,
                'root_used_percent' => 75.2,
                'swap_used_percent' => 0,
                'cpus' => 2,
                'fpm' => ['listen queue' => 3, 'active processes' => 5, 'idle processes' => 0, 'private' => 'discard this'],
                'nginx' => ['499' => 2, '502' => 3],
                'nginx_minute' => ['499' => 1],
                'private_token' => 'must not reach the database',
            ],
        ]);

        $storedMetrics = VpsHealthSample::query()->sole()->metrics;
        $this->assertArrayNotHasKey('private_token', $storedMetrics);
        $this->assertArrayNotHasKey('private', $storedMetrics['fpm']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson('/api/v1/admin/vps-health?hours=6')
            ->assertOk()
            ->assertJsonPath('data.range_hours', 6)
            ->assertJsonPath('data.sample_count', 1)
            ->assertJsonPath('data.latest.status', 'critical')
            ->assertJsonPath('data.latest.metrics.fpm.listen queue', 3)
            ->assertJsonPath('data.latest.metrics.private_token', null);
    }

    public function test_admin_can_request_three_day_health_history(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/v1/admin/vps-health?hours=72')
            ->assertOk()
            ->assertJsonPath('data.range_hours', 72);
    }

    public function test_health_samples_api_is_admin_only_and_rejects_unsupported_ranges(): void
    {
        $member = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->admin()->create();

        $this->getJson('/api/v1/admin/vps-health')->assertUnauthorized();
        $this->actingAs($member)->getJson('/api/v1/admin/vps-health')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/admin/vps-health?hours=7')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/v1/admin/vps-health?hours=96')->assertUnprocessable();
    }

    public function test_old_samples_are_pruned_after_96_hours(): void
    {
        $this->travelTo(now()->startOfHour());
        $expired = VpsHealthSample::query()->create([
            'sampled_at' => now()->subHours(97),
            'status' => 'ok',
            'issues' => [],
            'metrics' => [],
        ]);
        $boundary = VpsHealthSample::query()->create([
            'sampled_at' => now()->subHours(96),
            'status' => 'ok',
            'issues' => [],
            'metrics' => [],
        ]);

        app(VpsHealthSampleService::class)->record([
            'time' => now()->toIso8601String(),
            'status' => 'ok',
            'issues' => [],
            'metrics' => [],
        ]);

        $this->assertDatabaseMissing('portfolio_vps_health_samples', ['id' => $expired->id]);
        $this->assertDatabaseHas('portfolio_vps_health_samples', ['id' => $boundary->id]);
        $this->travelBack();
    }
}
