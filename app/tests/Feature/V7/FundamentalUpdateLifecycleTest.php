<?php

namespace Tests\Feature\V7;

use App\Models\Stock;
use App\Models\V7\FundamentalUpdateJob;
use App\Models\V7\FundamentalUpdateRun;
use App\Services\Fundamentals\FundamentalDataProvider;
use App\Services\Fundamentals\FundamentalUpdateService;
use App\Services\AdminOperationalAlertService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FundamentalUpdateLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fundamentals' => null]);
        \App\Models\V7\FundamentalSetting::query()->create([
            'quarterly_freshness_months' => 5,
            'annual_freshness_months' => 15,
            'request_delay_ms' => 0,
            'max_attempts' => 3,
            'provider' => 'yahoo',
            'paused' => false,
        ]);
    }

    public function test_scheduled_slice_resumes_existing_retry_work_without_creating_a_run(): void
    {
        $stock = Stock::query()->create(['symbol' => 'TCS', 'exchange' => 'NSE', 'name' => 'TCS']);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        $job = FundamentalUpdateJob::query()->create([
            'run_id' => $run->id,
            'stock_id' => $stock->id,
            'cadence' => 'quarterly',
            'status' => 'retry',
            'attempts' => 1,
            'next_attempt_at' => now()->subMinute(),
        ]);
        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn([]);
        $this->app->instance(FundamentalDataProvider::class, $provider);

        $result = app(FundamentalUpdateService::class)->processScheduledIncremental(1);

        $this->assertSame($run->id, $result['id']);
        $this->assertSame(1, FundamentalUpdateRun::query()->count());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('completed', $job->fresh()->status);
    }

    public function test_retry_not_due_is_not_processed_or_multiplied(): void
    {
        $stock = Stock::query()->create(['symbol' => 'INFY', 'exchange' => 'NSE', 'name' => 'Infosys']);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        FundamentalUpdateJob::query()->create([
            'run_id' => $run->id,
            'stock_id' => $stock->id,
            'cadence' => 'annual',
            'status' => 'retry',
            'attempts' => 1,
            'next_attempt_at' => now()->addHour(),
        ]);
        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->never();
        $this->app->instance(FundamentalDataProvider::class, $provider);

        app(FundamentalUpdateService::class)->processScheduledIncremental(1);

        $this->assertSame(1, FundamentalUpdateRun::query()->count());
        $this->assertSame('retry', FundamentalUpdateJob::query()->first()->status);
    }

    public function test_exhausted_retry_is_failed_and_parent_run_finalized(): void
    {
        $stock = Stock::query()->create(['symbol' => 'HDFC', 'exchange' => 'NSE', 'name' => 'HDFC']);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        $job = FundamentalUpdateJob::query()->create([
            'run_id' => $run->id,
            'stock_id' => $stock->id,
            'cadence' => 'quarterly',
            'status' => 'retry',
            'attempts' => 3,
            'next_attempt_at' => now()->subMinute(),
        ]);

        $result = app(FundamentalUpdateService::class)->reconcileBacklog(3);

        $this->assertSame(1, $result['exhausted']);
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('completed_with_errors', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->failed);
    }

    public function test_new_incremental_run_is_created_only_after_prior_work_is_terminal(): void
    {
        $stock = Stock::query()->create(['symbol' => 'RELIANCE', 'exchange' => 'NSE', 'name' => 'Reliance']);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'completed']);
        FundamentalUpdateJob::query()->create([
            'run_id' => $run->id,
            'stock_id' => $stock->id,
            'cadence' => 'quarterly',
            'status' => 'completed',
        ]);
        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn([]);
        $this->app->instance(FundamentalDataProvider::class, $provider);

        $result = app(FundamentalUpdateService::class)->processScheduledIncremental(1);

        $this->assertSame(2, FundamentalUpdateRun::query()->count());
        $this->assertSame('running', $result['status']);
    }

    public function test_provider_failure_retries_without_writing_zero_valued_facts(): void
    {
        $stock = Stock::query()->create(['symbol' => 'SBIN', 'exchange' => 'NSE', 'name' => 'State Bank']);
        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('Yahoo fundamentals authentication failed after session refresh.'));
        $this->app->instance(FundamentalDataProvider::class, $provider);
        $alerts = Mockery::mock(AdminOperationalAlertService::class);
        $alerts->shouldReceive('recordUnattendedFailure')->once();
        $alerts->shouldReceive('syncAndNotify')->once();
        $this->app->instance(AdminOperationalAlertService::class, $alerts);

        $result = app(FundamentalUpdateService::class)->processScheduledIncremental(1);

        $this->assertSame('running', $result['status']);
        $this->assertSame(0, \App\Models\V7\FundamentalFact::query()->count());
        $this->assertSame('retry', FundamentalUpdateJob::query()->first()->status);
    }
}
