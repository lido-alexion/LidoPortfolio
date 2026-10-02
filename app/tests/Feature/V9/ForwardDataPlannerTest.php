<?php

namespace Tests\Feature\V9;

use App\Exceptions\MlHistoricalUniverseProviderException;
use App\Models\ForwardCollectionWork;
use App\Models\Stock;
use App\Services\ForwardDataPlanner;
use App\Services\ForwardDataHealthService;
use App\Services\ML\NseHistoricalUniverseArchiveProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ForwardDataPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_forward_start_fails_closed_instead_of_inventing_a_recovery_floor(): void
    {
        config(['forward_data.start_date' => null]);
        $result = app(ForwardDataPlanner::class)->plan(Carbon::parse('2026-10-01'));

        $this->assertSame('blocked_configuration', $result['status']);
        $this->assertSame(0, ForwardCollectionWork::query()->count());
    }

    public function test_planner_persists_each_completed_session_obligation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 18:00:00', 'Asia/Kolkata'));
        config(['forward_data.start_date' => '2026-09-28']);
        config(['ml.historical_universe.mii_path' => '/tmp/stox-mii', 'ml.historical_universe.bhavcopy_path' => '/tmp/stox-bhavcopy']);

        $result = app(ForwardDataPlanner::class)->plan();

        $this->assertSame('planned', $result['status']);
        $this->assertGreaterThan(0, ForwardCollectionWork::query()->count());
        $this->assertTrue(ForwardCollectionWork::query()->where('state', ForwardDataPlanner::STATE_WAITING_PUBLICATION)->exists());
        Carbon::setTestNow();
    }

    public function test_publication_grace_uses_next_session_noon_not_planning_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'Asia/Kolkata'));
        config(['forward_data.start_date' => '2026-09-29']);
        config(['ml.historical_universe.mii_path' => '/tmp/stox-mii', 'ml.historical_universe.bhavcopy_path' => '/tmp/stox-bhavcopy']);

        app(ForwardDataPlanner::class)->plan();

        $work = ForwardCollectionWork::query()->whereDate('session_date', '2026-09-29')->firstOrFail();
        $this->assertSame('2026-09-29 12:30:00', $work->next_attempt_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 06:30:00', $work->publication_grace_until?->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    public function test_validated_source_is_claimable_after_close_before_publication_grace(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'Asia/Kolkata'));
        config(['forward_data.start_date' => '2026-09-30']);
        config(['ml.historical_universe.mii_path' => '/tmp/stox-mii', 'ml.historical_universe.bhavcopy_path' => '/tmp/stox-bhavcopy']);

        app(ForwardDataPlanner::class)->plan();
        $work = ForwardCollectionWork::query()->whereDate('session_date', '2026-09-30')->firstOrFail();

        $this->assertSame('2026-09-30', $work->session_date->toDateString());

        $this->assertSame('2026-09-30', $work->acquisition_eligible_at->format('Y-m-d'));
        $this->assertSame('2026-10-01', $work->publication_grace_until->format('Y-m-d'));
        $claimed = app(ForwardDataPlanner::class)->claim(1);

        $this->assertCount(1, $claimed);
        $this->assertSame('running', $claimed[0]->state);
        $this->assertSame(1, $claimed[0]->attempts);
        Carbon::setTestNow();
    }

    public function test_forward_command_ingests_valid_source_before_grace_and_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00:00', 'Asia/Kolkata'));
        $stock = Stock::query()->create([
            'symbol' => 'OCTOBER',
            'exchange' => 'NSE',
            'isin' => 'INE000000001',
            'name' => 'October Source Fixture',
            'is_active' => true,
        ]);
        $path = storage_path('framework/testing/nse-20261001.csv');
        File::put($path, "TradDt,FinInstrmId,TckrSymb,SctySrs,ISIN\n20261001,1,OCTOBER,EQ,{$stock->isin}\n");
        config([
            'forward_data.start_date' => '2026-10-01',
            'forward_data.official_source_enabled' => false,
            'ml.historical_universe.mii_path' => '',
            'ml.historical_universe.bhavcopy_path' => $path,
        ]);
        $firstStatus = Artisan::call('stox:forward-data', ['--batch' => 1]);
        $this->assertSame(0, $firstStatus, Artisan::output());
        $work = ForwardCollectionWork::query()->whereDate('session_date', '2026-10-01')->first();
        $this->assertNotNull($work);
        $work = $work->fresh();
        $this->assertSame('succeeded', $work->state);
        $this->assertSame(1, $work->attempts);
        $this->assertSame(1, (int) $work->owner_evidence['mapped_count']);
        $this->assertDatabaseCount('stox_ml_universe_snapshot_boundaries', 1);
        $this->assertTrue(
            \DB::table('stox_ml_universe_memberships')
                ->where('stock_id', $stock->id)
                ->whereDate('effective_from', '2026-10-01')
                ->where('source', 'forward_official_nse')
                ->exists()
        );

        $secondStatus = Artisan::call('stox:forward-data', ['--batch' => 1]);
        $this->assertSame(0, $secondStatus, Artisan::output());
        $this->assertDatabaseCount('stox_ml_universe_snapshot_boundaries', 1);
        $this->assertSame(1, ForwardCollectionWork::query()->whereDate('session_date', '2026-10-01')->firstOrFail()->attempts);

        File::delete($path);
        Carbon::setTestNow();
    }

    public function test_unavailable_source_remains_retryable_without_an_early_alert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 04:30:00', 'UTC'));
        config([
            'forward_data.start_date' => '2026-10-01',
            'forward_data.official_source_enabled' => false,
            'ml.historical_universe.mii_path' => '/tmp/stox-mii',
            'ml.historical_universe.bhavcopy_path' => '',
        ]);
        $provider = $this->mock(NseHistoricalUniverseArchiveProvider::class);
        $provider->shouldReceive('snapshotForDate')
            ->once()
            ->andThrow(new MlHistoricalUniverseProviderException('official publication not available', true));

        Artisan::call('stox:forward-data', ['--batch' => 1]);
        $work = ForwardCollectionWork::query()->whereDate('session_date', '2026-10-01')->firstOrFail();
        $this->assertSame(ForwardDataPlanner::STATE_WAITING_PUBLICATION, $work->state);
        $this->assertSame('missing_publication', $work->last_error_code);
        $this->assertSame(1, $work->attempts);
        $this->assertTrue($work->next_attempt_at->isFuture());
        $this->assertTrue($work->publication_grace_until->isFuture());

        $report = app(ForwardDataHealthService::class)->report();
        $this->assertSame('grace', $report['datasets']['official_nse_membership']['state']);
        $this->assertFalse(collect($report['alerts'])->contains(fn (array $alert) => ($alert['context']['dataset'] ?? null) === 'official_nse_membership'));
        Carbon::setTestNow();
    }

    public function test_same_calendar_session_is_planned_when_timezone_offsets_differ(): void
    {
        config(['forward_data.timezone' => 'Asia/Kolkata']);
        config(['forward_data.start_date' => '2026-10-01']);
        config(['ml.historical_universe.mii_path' => '/tmp/stox-mii', 'ml.historical_universe.bhavcopy_path' => '/tmp/stox-bhavcopy']);

        $result = app(ForwardDataPlanner::class)->plan(Carbon::parse('2026-10-02 10:00:00', 'Asia/Kolkata'));

        $this->assertSame(1, $result['created']);
        $this->assertTrue(ForwardCollectionWork::query()
            ->whereDate('session_date', '2026-10-01')
            ->where('state', 'waiting_publication')
            ->exists());
    }

    public function test_expired_claim_is_recoverable_and_new_claim_has_a_lease_token(): void
    {
        $work = ForwardCollectionWork::query()->create([
            'dataset_key' => ForwardDataPlanner::DATASET_NSE_MEMBERSHIP,
            'exchange' => 'NSE', 'session_date' => '2026-09-30', 'scope_key' => 'active_eligible_nse',
            'state' => 'running', 'attempts' => 1, 'lease_token' => 'stale', 'lease_expires_at' => now()->subMinute(),
        ]);

        $claimed = app(ForwardDataPlanner::class)->claim(1);

        $this->assertCount(1, $claimed);
        $this->assertNotSame('stale', $claimed[0]->lease_token);
        $this->assertSame('running', $claimed[0]->state);
        $this->assertSame(2, $claimed[0]->attempts);
        $this->assertNotNull($claimed[0]->lease_expires_at);
        $this->assertSame($work->id, $claimed[0]->id);
    }

    public function test_stale_worker_cannot_commit_after_lease_token_changes(): void
    {
        $work = ForwardCollectionWork::query()->create([
            'dataset_key' => ForwardDataPlanner::DATASET_NSE_MEMBERSHIP,
            'exchange' => 'NSE', 'session_date' => '2026-09-30', 'scope_key' => 'active_eligible_nse',
            'state' => 'pending',
        ]);
        $claimed = app(ForwardDataPlanner::class)->claim(1)[0];
        $work->forceFill(['lease_token' => 'new-worker-token'])->save();

        $this->assertFalse(app(ForwardDataPlanner::class)->succeed($claimed, (string) $claimed->lease_token));
        $this->assertSame('running', $work->fresh()->state);
    }
}
