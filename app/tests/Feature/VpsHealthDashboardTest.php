<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VpsHealthSample;
use App\Models\VpsHealthDiskHourlyRollup;
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
                'root_disk_critical_percent' => 85,
                'root_total_bytes' => 1000,
                'root_used_bytes' => 752,
                'root_available_bytes' => 248,
                'root_available_percent' => 24.8,
                'root_inode' => ['total' => 100, 'used' => 76, 'available' => 24, 'used_percent' => 76],
                'disk_io' => ['status' => 'available', 'read_bytes_per_second' => 12.5, 'write_bytes_per_second' => 3],
                'directory_breakdown' => ['status' => 'partial', 'scanned_at' => '2026-11-27T10:00:00Z', 'paths' => [
                    ['path' => '/var/log', 'direct_file_bytes' => null, 'status' => 'unavailable'],
                ], 'reclaimable_candidates' => [
                    ['kind' => 'rotated_log_files', 'bytes' => 456, 'status' => 'partial', 'guaranteed_safe_to_delete' => true],
                ], 'visibility' => ['home' => 'unavailable: ProtectHome=yes', 'tmp' => 'unavailable: PrivateTmp=yes']],
                'swap_used_percent' => 0,
                'cpus' => 2,
                'fpm' => ['listen queue' => 3, 'active processes' => 5, 'idle processes' => 0, 'private' => 'discard this'],
                'nginx' => ['499' => 2, '502' => 3],
                'nginx_minute' => ['499' => 1],
                'private_token' => 'must not reach the database',
                '_disk_io_counters' => ['read_bytes' => 99],
            ],
        ]);

        $storedMetrics = VpsHealthSample::query()->sole()->metrics;
        $this->assertArrayNotHasKey('private_token', $storedMetrics);
        $this->assertArrayNotHasKey('private', $storedMetrics['fpm']);
        $this->assertSame(1000, $storedMetrics['root_total_bytes']);
        $this->assertSame(85, $storedMetrics['root_disk_critical_percent']);
        $this->assertSame(24.8, $storedMetrics['root_available_percent']);
        $this->assertSame(76, $storedMetrics['root_inode']['used_percent']);
        $this->assertSame(12.5, $storedMetrics['disk_io']['read_bytes_per_second']);
        $this->assertSame('2026-11-27T10:00:00Z', $storedMetrics['directory_breakdown']['scanned_at']);
        $this->assertSame('unavailable', $storedMetrics['directory_breakdown']['paths'][0]['status']);
        $this->assertNull($storedMetrics['directory_breakdown']['paths'][0]['shallow_file_bytes']);
        $this->assertFalse($storedMetrics['directory_breakdown']['reclaimable_candidates'][0]['guaranteed_safe_to_delete']);
        $this->assertSame('unavailable: ProtectHome=yes', $storedMetrics['directory_breakdown']['visibility']['home']);
        $this->assertArrayNotHasKey('_disk_io_counters', $storedMetrics);

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

    public function test_seven_and_thirty_day_ranges_use_disk_only_hourly_rollups(): void
    {
        $admin = User::factory()->admin()->create();
        $time = now()->subDays(5)->startOfHour();
        VpsHealthDiskHourlyRollup::query()->create([
            'hour_start' => $time,
            'sampled_at' => $time->copy()->addMinutes(59),
            'metrics' => ['root_used_percent' => 81, 'root_available_bytes' => 1234],
        ]);

        $this->actingAs($admin)->getJson('/api/v1/admin/vps-health?hours=168')
            ->assertOk()->assertJsonPath('data.history_granularity', 'hour')
            ->assertJsonPath('data.samples.0.metrics.root_used_percent', 81);
        $this->actingAs($admin)->getJson('/api/v1/admin/vps-health?hours=720')
            ->assertOk()->assertJsonPath('data.range_hours', 720);
    }

    public function test_long_range_includes_exactly_the_requested_hour_buckets_and_keeps_sparse_history(): void
    {
        $this->travelTo(now()->startOfHour()->addMinutes(30));
        $admin = User::factory()->admin()->create();
        $outside = now()->startOfHour()->subHours(720);
        $inside = now()->startOfHour()->subHours(719);
        foreach ([[$outside, 11], [$inside, 22]] as [$hour, $percent]) {
            VpsHealthDiskHourlyRollup::query()->create([
                'hour_start' => $hour,
                'sampled_at' => $hour->copy()->addMinutes(59),
                'metrics' => ['root_used_percent' => $percent],
            ]);
        }

        $this->actingAs($admin)->getJson('/api/v1/admin/vps-health?hours=720')
            ->assertOk()
            ->assertJsonPath('data.sample_count', 1)
            ->assertJsonPath('data.samples.0.metrics.root_used_percent', 22);
    }

    public function test_directory_normalization_preserves_measured_zero_and_unavailable_null(): void
    {
        app(VpsHealthSampleService::class)->record([
            'time' => now()->toIso8601String(), 'status' => 'ok', 'issues' => [],
            'metrics' => ['directory_breakdown' => [
                'status' => 'partial',
                'paths' => [
                    ['path' => '/var/tmp', 'shallow_file_bytes' => 0, 'status' => 'available'],
                    ['path' => '/var/cache', 'shallow_file_bytes' => 0, 'status' => 'unavailable'],
                ],
                'reclaimable_candidates' => [
                    ['kind' => 'cache_files', 'bytes' => 0, 'status' => 'unavailable'],
                ],
            ]],
        ]);

        $breakdown = VpsHealthSample::query()->sole()->metrics['directory_breakdown'];
        $this->assertSame(0, $breakdown['paths'][0]['shallow_file_bytes']);
        $this->assertSame('available', $breakdown['paths'][0]['status']);
        $this->assertNull($breakdown['paths'][1]['shallow_file_bytes']);
        $this->assertSame('unavailable', $breakdown['paths'][1]['status']);
        $this->assertNull($breakdown['reclaimable_candidates'][0]['bytes']);
        $this->assertSame('unavailable', $breakdown['reclaimable_candidates'][0]['status']);
    }

    public function test_record_updates_one_disk_only_rollup_for_each_hour(): void
    {
        $time = now()->startOfHour()->addMinutes(2);
        foreach ([70, 72] as $percent) {
            app(VpsHealthSampleService::class)->record([
                'time' => $time->copy()->addMinutes($percent - 70)->toIso8601String(),
                'status' => 'ok', 'issues' => [],
                'metrics' => ['root_used_percent' => $percent, 'load1' => 4, 'private_value' => 'excluded'],
            ]);
        }

        $rollup = VpsHealthDiskHourlyRollup::query()->sole();
        $this->assertSame(72, $rollup->metrics['root_used_percent']);
        $this->assertArrayNotHasKey('load1', $rollup->metrics);
        $this->assertArrayNotHasKey('private_value', $rollup->metrics);
    }

    public function test_disk_threshold_warning_is_stored_as_warning(): void
    {
        app(VpsHealthSampleService::class)->record([
            'time' => now()->toIso8601String(),
            'status' => 'warning',
            'issues' => ['root filesystem usage reached 75% threshold'],
            'metrics' => ['root_used_percent' => 75],
        ]);

        $this->assertSame('warning', VpsHealthSample::query()->sole()->status);
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
            'metrics' => ['load1' => 0],
        ]);

        $this->assertDatabaseMissing('portfolio_vps_health_samples', ['id' => $expired->id]);
        $this->assertDatabaseHas('portfolio_vps_health_samples', ['id' => $boundary->id]);
        $this->travelBack();
    }
}
