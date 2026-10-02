<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\V8\MlUniverseMembership;
use App\Services\ML\MlHistoricalUniverseMembershipService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlUniverseMembershipSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_master_cannot_be_stamped_with_a_historical_or_future_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02'));
        Stock::query()->create(['symbol' => 'NOW', 'exchange' => 'NSE', 'name' => 'Now', 'sector' => 'Current sector']);
        foreach (['2020-01-01', '2026-10-03'] as $date) {
            try {
                app(MlHistoricalUniverseMembershipService::class)->captureCurrentEligibleSnapshot(Carbon::parse($date), 'current_master');
                $this->fail('A current snapshot must not become historical evidence.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('historical dates require dated NSE evidence', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('stox_ml_universe_memberships', 0);
        $this->assertDatabaseCount('stox_ml_universe_snapshot_boundaries', 0);
    }

    public function test_current_snapshot_is_dated_auditable_and_idempotent(): void
    {
        $this->travelTo(Carbon::parse('2025-01-01'));
        $active = Stock::query()->create([
            'symbol' => 'PIT', 'exchange' => 'NSE', 'name' => 'PIT Co', 'sector' => 'Technology', 'is_active' => true,
        ]);
        Stock::query()->create([
            'symbol' => 'OLD', 'exchange' => 'NSE', 'name' => 'Old Co', 'sector' => 'Technology', 'is_active' => false,
        ]);
        Stock::query()->create([
            'symbol' => 'INDEX', 'exchange' => 'NSE', 'name' => 'Index', 'is_benchmark' => true, 'is_active' => true,
        ]);

        $service = app(MlHistoricalUniverseMembershipService::class);
        $first = $service->captureCurrentEligibleSnapshot(Carbon::parse('2025-01-01'), 'test_feed', 'feed-2025-01-01');
        $second = $service->captureCurrentEligibleSnapshot(Carbon::parse('2025-01-01'), 'test_feed', 'feed-2025-01-01');

        $this->assertSame(1, $first['active_count']);
        $this->assertSame(1, $first['inserted_count']);
        $this->assertSame(0, $second['inserted_count']);
        $this->assertSame([$active->id], $service->stockIdsForDate('2025-06-01'));
        $this->assertSame('Technology', $service->sectorForDate($active->id, '2025-06-01'));
        $this->assertSame('feed-2025-01-01', MlUniverseMembership::query()->first()->snapshot_key);
    }

    public function test_later_snapshot_closes_prior_membership_period(): void
    {
        $this->travelTo(Carbon::parse('2025-01-01'));
        $stock = Stock::query()->create([
            'symbol' => 'MOVE', 'exchange' => 'NSE', 'name' => 'Move Co', 'sector' => 'Technology', 'is_active' => true,
        ]);
        $service = app(MlHistoricalUniverseMembershipService::class);
        $service->captureCurrentEligibleSnapshot(Carbon::parse('2025-01-01'), 'test_feed', 'feed-a');
        $stock->forceFill(['sector' => 'Industrials'])->save();
        $this->travelTo(Carbon::parse('2025-07-01'));
        $service->captureCurrentEligibleSnapshot(Carbon::parse('2025-07-01'), 'test_feed', 'feed-b');

        $this->assertSame('Technology', $service->sectorForDate($stock->id, '2025-06-30'));
        $this->assertSame('Industrials', $service->sectorForDate($stock->id, '2025-07-01'));
        $this->assertSame('2025-06-30', MlUniverseMembership::query()->where('snapshot_key', 'feed-a')->firstOrFail()->effective_to->toDateString());
    }

    public function test_coverage_reports_missing_historical_snapshot_dates_without_current_fallback(): void
    {
        $this->travelTo(Carbon::parse('2025-01-01'));
        $stock = Stock::query()->create([
            'symbol' => 'GAP', 'exchange' => 'NSE', 'name' => 'Gap Co', 'sector' => 'Technology', 'is_active' => true,
        ]);
        $service = app(MlHistoricalUniverseMembershipService::class);
        $service->captureCurrentEligibleSnapshot(Carbon::parse('2025-01-01'), 'test_feed', 'feed-a');
        $this->travelTo(Carbon::parse('2025-01-04'));
        $service->captureCurrentEligibleSnapshot(Carbon::parse('2025-01-04'), 'test_feed', 'feed-b');

        $coverage = $service->coverageForDates(['2025-01-01', '2025-01-02', '2025-01-03', '2025-01-04']);

        $this->assertSame(['2025-01-01', '2025-01-02', '2025-01-03', '2025-01-04'], $coverage['covered_dates']);
        $this->assertSame([], $coverage['missing_dates']);
        $this->assertSame(100.0, $coverage['coverage_percentage']);
        $this->assertSame([$stock->id], $service->stockIdsForDate('2025-01-03'));
    }

    public function test_coverage_identifies_a_gap_before_the_first_snapshot(): void
    {
        $this->travelTo(Carbon::parse('2025-01-04'));
        Stock::query()->create([
            'symbol' => 'LATE', 'exchange' => 'NSE', 'name' => 'Late Co', 'sector' => 'Technology', 'is_active' => true,
        ]);
        $service = app(MlHistoricalUniverseMembershipService::class);
        $service->captureCurrentEligibleSnapshot(Carbon::parse('2025-01-04'), 'test_feed', 'feed-b');

        $coverage = $service->coverageForDates(['2025-01-01', '2025-01-02', '2025-01-03', '2025-01-04']);

        $this->assertSame(['2025-01-01', '2025-01-02', '2025-01-03'], $coverage['missing_dates']);
        $this->assertSame([['start' => '2025-01-01', 'end' => '2025-01-03', 'days' => 3]], $coverage['gaps']);
        $this->assertSame(25.0, $coverage['coverage_percentage']);
    }
}
