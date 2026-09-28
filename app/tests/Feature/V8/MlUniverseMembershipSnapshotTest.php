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

    public function test_current_snapshot_is_dated_auditable_and_idempotent(): void
    {
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
        $stock = Stock::query()->create([
            'symbol' => 'MOVE', 'exchange' => 'NSE', 'name' => 'Move Co', 'sector' => 'Technology', 'is_active' => true,
        ]);
        $service = app(MlHistoricalUniverseMembershipService::class);
        $service->captureCurrentEligibleSnapshot(Carbon::parse('2025-01-01'), 'test_feed', 'feed-a');
        $stock->forceFill(['sector' => 'Industrials'])->save();
        $service->captureCurrentEligibleSnapshot(Carbon::parse('2025-07-01'), 'test_feed', 'feed-b');

        $this->assertSame('Technology', $service->sectorForDate($stock->id, '2025-06-30'));
        $this->assertSame('Industrials', $service->sectorForDate($stock->id, '2025-07-01'));
        $this->assertSame('2025-06-30', MlUniverseMembership::query()->where('snapshot_key', 'feed-a')->firstOrFail()->effective_to->toDateString());
    }
}
