<?php

namespace Tests\Feature\V9;

use App\Models\ForwardCollectionWork;
use App\Services\ForwardDataPlanner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame('2026-09-30 12:00:00', $work->next_attempt_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
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
