<?php

namespace Tests\Feature\V8;

use App\Models\MicrostructureCollectorState;
use App\Services\AdminOperationalAlertService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MicrostructureCollectorHealthAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-07 11:00:00', 'Asia/Kolkata'));
        config([
            'microstructure_collector.enabled' => true,
            'microstructure_collector.heartbeat_file' => storage_path('framework/testing/microstructure-heartbeat-alert.json'),
            'microstructure_collector.data_root' => storage_path('framework/testing/microstructure-data-alert'),
            'microstructure_collector.heartbeat_stale_minutes' => 5,
        ]);
        File::ensureDirectoryExists(config('microstructure_collector.data_root'));
        MicrostructureCollectorState::current()->update(['manual_hold' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $heartbeat = config('microstructure_collector.heartbeat_file');
        if (is_string($heartbeat) && is_file($heartbeat)) {
            File::delete($heartbeat);
        }
        parent::tearDown();
    }

    public function test_stale_heartbeat_during_market_hours_creates_alert(): void
    {
        $path = config('microstructure_collector.heartbeat_file');
        File::put($path, json_encode([
            'last_packet_at' => now()->subMinutes(30)->toIso8601String(),
            'websocket_connected' => false,
            'collector_state' => 'running',
        ], JSON_THROW_ON_ERROR));

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        $this->assertContains(AdminOperationalAlertService::KEY_MICROSTRUCTURE_COLLECTOR_STALE, $keys);
    }

    public function test_manual_hold_suppresses_stale_alert(): void
    {
        MicrostructureCollectorState::current()->update(['manual_hold' => true]);

        $path = config('microstructure_collector.heartbeat_file');
        File::put($path, json_encode([
            'last_packet_at' => now()->subHours(2)->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        $this->assertNotContains(AdminOperationalAlertService::KEY_MICROSTRUCTURE_COLLECTOR_STALE, $keys);
    }

    public function test_finalization_and_backup_failures_are_actionable_alerts(): void
    {
        $path = config('microstructure_collector.heartbeat_file');
        File::put($path, json_encode([
            'finalization' => [
                'status' => 'finalization_failed',
                'trading_day' => '2026-07-07',
                'finalization_attempts' => 3,
                'last_error' => 'Parquet validation failed',
                'backup_status' => 'backup_failed',
                'backup_error' => 'Remote target unavailable',
            ],
        ], JSON_THROW_ON_ERROR));

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        $this->assertContains(AdminOperationalAlertService::KEY_MICROSTRUCTURE_COLLECTOR_FINALIZATION_FAILED, $keys);
        $this->assertContains(AdminOperationalAlertService::KEY_MICROSTRUCTURE_COLLECTOR_BACKUP_FAILED, $keys);
    }

    public function test_post_market_low_coverage_is_actionable_alert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 16:00:00', 'Asia/Kolkata'));
        config(['microstructure_collector.coverage_warning_percent' => 90]);
        $path = config('microstructure_collector.heartbeat_file');
        File::put($path, json_encode([
            'session_phase' => 'post_market',
            'coverage_summary' => [
                'trading_day' => '2026-07-07',
                'expected_instrument_minutes' => 1000,
                'coverage_percent' => 42.5,
                'quality_counts' => ['partial' => 425, 'outage' => 575],
            ],
        ], JSON_THROW_ON_ERROR));

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        $this->assertContains(AdminOperationalAlertService::KEY_MICROSTRUCTURE_COLLECTOR_COVERAGE_LOW, $keys);
    }
}
