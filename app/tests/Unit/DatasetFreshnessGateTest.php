<?php

namespace Tests\Unit;

use App\Engines\Pipeline\DatasetFreshnessGate;
use App\Services\DailyMarketSyncService;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class DatasetFreshnessGateTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_non_monday_age_under_24_hours_is_allowed(): void
    {
        $pipelineAt = Carbon::parse('2026-08-27 14:00:00', 'Asia/Kolkata');
        $result = $this->evaluate($pipelineAt, $pipelineAt->copy()->subHours(23));

        $this->assertTrue($result['allowed']);
        $this->assertSame(24, $result['max_age_hours']);
        $this->assertSame('Thursday', $result['weekday']);
        $this->assertNull($result['reason']);
    }

    public function test_non_monday_age_exactly_24_hours_is_allowed(): void
    {
        $pipelineAt = Carbon::parse('2026-08-27 14:00:00', 'Asia/Kolkata');
        $result = $this->evaluate($pipelineAt, $pipelineAt->copy()->subSeconds(24 * 3600));

        $this->assertTrue($result['allowed']);
        $this->assertSame(24 * 3600, $result['age_seconds']);
    }

    public function test_non_monday_age_one_second_over_24_hours_is_blocked(): void
    {
        $pipelineAt = Carbon::parse('2026-08-27 14:00:00', 'Asia/Kolkata');
        $result = $this->evaluate($pipelineAt, $pipelineAt->copy()->subSeconds(24 * 3600 + 1));

        $this->assertFalse($result['allowed']);
        $this->assertSame('dataset_not_fresh', $result['reason']);
        $this->assertSame(24, $result['max_age_hours']);
        $this->assertSame(24 * 3600 + 1, $result['age_seconds']);
    }

    public function test_monday_age_exactly_72_hours_is_allowed(): void
    {
        $pipelineAt = Carbon::parse('2026-08-24 10:00:00', 'Asia/Kolkata');
        $result = $this->evaluate($pipelineAt, Carbon::parse('2026-08-21 10:00:00', 'Asia/Kolkata'));

        $this->assertTrue($result['allowed']);
        $this->assertSame(72, $result['max_age_hours']);
        $this->assertSame('Monday', $result['weekday']);
        $this->assertSame(72 * 3600, $result['age_seconds']);
    }

    public function test_monday_age_one_second_over_72_hours_is_blocked(): void
    {
        $pipelineAt = Carbon::parse('2026-08-24 10:00:00', 'Asia/Kolkata');
        $result = $this->evaluate($pipelineAt, Carbon::parse('2026-08-21 09:59:59', 'Asia/Kolkata'));

        $this->assertFalse($result['allowed']);
        $this->assertSame('dataset_not_fresh', $result['reason']);
        $this->assertSame(72, $result['max_age_hours']);
        $this->assertSame(72 * 3600 + 1, $result['age_seconds']);
    }

    public function test_monday_window_uses_cron_timezone_not_utc(): void
    {
        // Monday 00:30 IST is still Sunday in UTC. 30h-old data would fail a 24h Sunday window.
        $pipelineAt = Carbon::parse('2026-08-24 00:30:00', 'Asia/Kolkata');
        $this->assertSame('Sunday', $pipelineAt->copy()->timezone('UTC')->englishDayOfWeek);

        $result = $this->evaluate($pipelineAt, $pipelineAt->copy()->subHours(30));

        $this->assertTrue($result['allowed']);
        $this->assertSame(72, $result['max_age_hours']);
        $this->assertSame('Monday', $result['weekday']);
    }

    public function test_sunday_does_not_receive_the_72_hour_window(): void
    {
        $pipelineAt = Carbon::parse('2026-08-23 10:00:00', 'Asia/Kolkata');
        $result = $this->evaluate($pipelineAt, $pipelineAt->copy()->subHours(48));

        $this->assertFalse($result['allowed']);
        $this->assertSame(24, $result['max_age_hours']);
        $this->assertSame('Sunday', $result['weekday']);
    }

    public function test_missing_successful_sync_timestamp_is_blocked(): void
    {
        $pipelineAt = Carbon::parse('2026-08-27 14:00:00', 'Asia/Kolkata');
        $result = $this->evaluate($pipelineAt, null);

        $this->assertFalse($result['allowed']);
        $this->assertSame('dataset_not_fresh', $result['reason']);
        $this->assertNull($result['age_seconds']);
        $this->assertNull($result['synced_at']);
    }

    public function test_future_sync_timestamp_is_treated_as_zero_age(): void
    {
        $pipelineAt = Carbon::parse('2026-08-27 14:00:00', 'Asia/Kolkata');
        $result = $this->evaluate($pipelineAt, $pipelineAt->copy()->addMinute());

        $this->assertTrue($result['allowed']);
        $this->assertSame(0, $result['age_seconds']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function evaluate(Carbon $pipelineAt, ?Carbon $syncedAt): array
    {
        $sync = Mockery::mock(DailyMarketSyncService::class);
        $sync->shouldReceive('syncTimezone')->andReturn('Asia/Kolkata');
        $sync->shouldReceive('lastSuccessfulSyncAt')->andReturn($syncedAt);

        return (new DatasetFreshnessGate($sync))->evaluate($pipelineAt);
    }
}
