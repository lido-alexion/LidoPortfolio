<?php

namespace Tests\Feature\Backtest;

use App\Models\BacktestRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class V5BacktestLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_progress_run_can_be_cancelled_at_checkpoint_then_deleted_with_tombstone(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $run = BacktestRun::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'name' => 'V5 lifecycle',
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
            'initial_capital' => 100000,
            'status' => BacktestRun::STATUS_RUNNING,
            'stage' => BacktestRun::STAGE_SIMULATING_DAYS,
            'processed_days' => 4,
            'total_days' => 20,
            'session_token' => (string) Str::uuid(),
            'context_json' => ['day_cursor' => 4, 'cash' => 90000],
        ]);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/backtests/'.$run->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', BacktestRun::STATUS_CANCELLED)
            ->assertJsonPath('data.stage', BacktestRun::STAGE_CANCELLED);

        $run->refresh();
        $this->assertNotNull($run->cancelled_at);
        $this->assertNull($run->context_json);
        $this->artisan('portfolio:process-backtests')
            ->expectsOutput('Backtest slices: 0 runs; 0 completed.')
            ->assertSuccessful();
        $this->postJson('/api/v1/backtests/'.$run->id.'/continue')
            ->assertOk()->assertJsonPath('data.run.status', BacktestRun::STATUS_CANCELLED);
        $this->postJson('/api/v1/backtests/'.$run->id.'/cancel')->assertUnprocessable();

        $this->deleteJson('/api/v1/backtests/'.$run->id)->assertOk();
        $this->assertDatabaseMissing('portfolio_backtest_runs', ['id' => $run->id]);
        $this->assertDatabaseHas('portfolio_backtest_run_tombstones', [
            'backtest_run_id' => $run->id,
            'profile_id' => $profile->id,
            'final_status' => BacktestRun::STATUS_CANCELLED,
            'deleted_by_user_id' => $user->id,
        ]);
        $this->assertSame(1, DB::table('portfolio_backtest_run_tombstones')->count());
    }
}
