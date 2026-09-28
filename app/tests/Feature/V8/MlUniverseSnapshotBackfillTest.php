<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\V8\MlUniverseSnapshotBackfillRun;
use App\Models\V8\MlUniverseMembership;
use App\Services\ML\MlHistoricalUniverseMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlUniverseSnapshotBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_idempotent_and_preserves_entries_and_exits(): void
    {
        $enters = Stock::query()->create(['symbol' => 'ENTER', 'exchange' => 'NSE', 'name' => 'Enter', 'sector' => 'Technology', 'is_active' => true]);
        $exits = Stock::query()->create(['symbol' => 'EXIT', 'exchange' => 'NSE', 'name' => 'Exit', 'sector' => 'Technology', 'is_active' => false]);
        $service = app(MlHistoricalUniverseMembershipService::class);
        $snapshots = [
            ['effective_from' => '2020-01-01', 'memberships' => [['stock_id' => $exits->id, 'sector_snapshot' => 'Technology']]],
            ['effective_from' => '2020-07-01', 'memberships' => [['stock_id' => $enters->id, 'sector_snapshot' => 'Technology']]],
        ];

        $first = $service->backfillHistoricalSnapshots($snapshots, 'official_history');
        $second = $service->backfillHistoricalSnapshots($snapshots, 'official_history');

        $this->assertSame('completed', $first['status']);
        $this->assertSame(2, count($first['processed_dates']));
        $this->assertSame(2, count($second['skipped_dates']));
        $this->assertSame([$exits->id], $service->stockIdsForDate('2020-03-01'));
        $this->assertSame([$enters->id], $service->stockIdsForDate('2020-08-01'));
        $this->assertSame('2020-06-30', MlUniverseMembership::query()->where('stock_id', $exits->id)->firstOrFail()->effective_to->toDateString());
        $this->assertSame(2, MlUniverseSnapshotBackfillRun::query()->count());
    }

    public function test_failed_snapshot_is_durable_and_can_resume_without_current_universe_fallback(): void
    {
        $stock = Stock::query()->create(['symbol' => 'KNOWN', 'exchange' => 'NSE', 'name' => 'Known', 'sector' => 'Technology', 'is_active' => true]);
        $service = app(MlHistoricalUniverseMembershipService::class);
        try {
            $service->backfillHistoricalSnapshots([
                ['effective_from' => '2021-01-01', 'memberships' => [['stock_id' => $stock->id]]],
                ['effective_from' => '2021-07-01', 'memberships' => [['stock_id' => 999999]]],
            ], 'official_history');
            $this->fail('Expected invalid historical identity to fail the run.');
        } catch (\InvalidArgumentException) {
            $run = MlUniverseSnapshotBackfillRun::query()->latest('id')->firstOrFail();
            $this->assertSame('failed', $run->status);
            $this->assertSame(['2021-07-01'], $run->failed_dates);
            $this->assertSame([$stock->id], $service->stockIdsForDate('2021-02-01'));
            $this->assertSame([], $service->stockIdsForDate('2020-12-01'));
        }
    }

    public function test_empty_authoritative_snapshot_is_covered_but_has_no_members(): void
    {
        $service = app(MlHistoricalUniverseMembershipService::class);
        $result = $service->backfillHistoricalSnapshots([
            ['effective_from' => '2022-01-01', 'memberships' => []],
        ], 'official_history');

        $coverage = $service->coverageForDates(['2022-01-01', '2022-01-02']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(['2022-01-01'], $coverage['covered_dates']);
        $this->assertSame(['2022-01-02'], $coverage['missing_dates']);
        $this->assertSame([], $service->stockIdsForDate('2022-01-01'));
    }
}
