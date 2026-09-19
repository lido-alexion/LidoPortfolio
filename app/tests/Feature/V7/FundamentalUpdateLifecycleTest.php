<?php

namespace Tests\Feature\V7;

use App\Models\Stock;
use App\Models\V7\FundamentalUpdateJob;
use App\Models\V7\FundamentalUpdateRun;
use App\Services\Fundamentals\FundamentalDataService;
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

    public function test_duplicate_incremental_jobs_retain_newest_viable_job_and_preserve_evidence(): void
    {
        $stock = Stock::query()->create(['symbol' => 'DUP', 'exchange' => 'NSE', 'name' => 'Duplicate']);
        $olderRun = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running', 'created_at' => now()->subHour()]);
        $newerRun = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        $older = FundamentalUpdateJob::query()->create([
            'run_id' => $olderRun->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly',
            'status' => 'retry', 'attempts' => 2, 'last_error' => 'old provider failure', 'next_attempt_at' => now()->subMinute(),
        ]);
        $newer = FundamentalUpdateJob::query()->create([
            'run_id' => $newerRun->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly',
            'status' => 'queued', 'attempts' => 0,
        ]);

        $result = app(FundamentalUpdateService::class)->reconcileBacklog(3);

        $this->assertSame(1, $result['superseded']);
        $this->assertSame('superseded', $older->fresh()->status);
        $this->assertNull($older->fresh()->next_attempt_at);
        $this->assertStringContainsString('old provider failure', $older->fresh()->last_error);
        $this->assertStringContainsString('Superseded during duplicate incremental backlog reconciliation', $older->fresh()->last_error);
        $this->assertSame('queued', $newer->fresh()->status);
        $this->assertSame(2, FundamentalUpdateJob::query()->count());
    }

    public function test_completed_jobs_are_never_compacted(): void
    {
        $stock = Stock::query()->create(['symbol' => 'DONE', 'exchange' => 'NSE', 'name' => 'Done']);
        $completedRun = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'completed']);
        $activeRun = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        $completed = FundamentalUpdateJob::query()->create(['run_id' => $completedRun->id, 'stock_id' => $stock->id, 'cadence' => 'annual', 'status' => 'completed']);
        $active = FundamentalUpdateJob::query()->create(['run_id' => $activeRun->id, 'stock_id' => $stock->id, 'cadence' => 'annual', 'status' => 'queued']);

        app(FundamentalUpdateService::class)->reconcileBacklog(3);

        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertSame('queued', $active->fresh()->status);
    }

    public function test_manual_targeted_runs_are_not_compacted(): void
    {
        $stock = Stock::query()->create(['symbol' => 'MANUAL', 'exchange' => 'NSE', 'name' => 'Manual']);
        $manualRun = FundamentalUpdateRun::query()->create(['trigger' => 'manual', 'scope' => 'stock', 'status' => 'running']);
        $incrementalRun = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        $manual = FundamentalUpdateJob::query()->create(['run_id' => $manualRun->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly', 'status' => 'queued']);
        $incremental = FundamentalUpdateJob::query()->create(['run_id' => $incrementalRun->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly', 'status' => 'queued']);

        app(FundamentalUpdateService::class)->reconcileBacklog(3);

        $this->assertSame('queued', $manual->fresh()->status);
        $this->assertSame('queued', $incremental->fresh()->status);
    }

    public function test_superseding_redundant_job_finalizes_its_parent_run(): void
    {
        $stock = Stock::query()->create(['symbol' => 'FINAL', 'exchange' => 'NSE', 'name' => 'Finalize']);
        $olderRun = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        $newerRun = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        FundamentalUpdateJob::query()->create(['run_id' => $olderRun->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly', 'status' => 'queued']);
        FundamentalUpdateJob::query()->create(['run_id' => $newerRun->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly', 'status' => 'queued']);

        app(FundamentalUpdateService::class)->reconcileBacklog(3);

        $this->assertSame('completed', $olderRun->fresh()->status);
        $this->assertNotNull($olderRun->fresh()->completed_at);
        $this->assertSame('running', $newerRun->fresh()->status);
    }

    public function test_compaction_is_idempotent_and_unrelated_work_remains_active(): void
    {
        $duplicateStock = Stock::query()->create(['symbol' => 'DUP2', 'exchange' => 'NSE', 'name' => 'Duplicate 2']);
        $otherStock = Stock::query()->create(['symbol' => 'OTHER', 'exchange' => 'NSE', 'name' => 'Other']);
        $firstRun = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        $secondRun = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        FundamentalUpdateJob::query()->create(['run_id' => $firstRun->id, 'stock_id' => $duplicateStock->id, 'cadence' => 'annual', 'status' => 'queued']);
        FundamentalUpdateJob::query()->create(['run_id' => $secondRun->id, 'stock_id' => $duplicateStock->id, 'cadence' => 'annual', 'status' => 'queued']);
        $otherJob = FundamentalUpdateJob::query()->create(['run_id' => $secondRun->id, 'stock_id' => $otherStock->id, 'cadence' => 'annual', 'status' => 'queued']);

        $service = app(FundamentalUpdateService::class);
        $first = $service->reconcileBacklog(3);
        $second = $service->reconcileBacklog(3);

        $this->assertSame(1, $first['superseded']);
        $this->assertSame(0, $second['superseded']);
        $this->assertSame('queued', $otherJob->fresh()->status);
        $this->assertSame(1, FundamentalUpdateJob::query()->where('stock_id', $duplicateStock->id)->whereIn('status', ['queued', 'retry', 'running'])->count());
    }

    public function test_retained_job_can_complete_and_store_facts_normally(): void
    {
        $stock = Stock::query()->create(['symbol' => 'FACT', 'exchange' => 'NSE', 'name' => 'Fact']);
        $olderRun = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running', 'created_at' => now()->subHour()]);
        $newerRun = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        FundamentalUpdateJob::query()->create(['run_id' => $olderRun->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly', 'status' => 'queued']);
        $retained = FundamentalUpdateJob::query()->create(['run_id' => $newerRun->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly', 'status' => 'queued']);
        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn([[
            'provider' => 'yahoo', 'statement_type' => 'income_statement', 'cadence' => FundamentalDataService::CADENCE_QUARTERLY,
            'statement_basis' => 'consolidated', 'fact_key' => 'revenue', 'period_end' => '2026-06-30', 'value' => 100,
            'availability_date' => '2026-07-20',
        ]]);
        $this->app->instance(FundamentalDataProvider::class, $provider);

        $result = app(FundamentalUpdateService::class)->processScheduledIncremental(1);

        $this->assertSame($newerRun->id, $result['id']);
        $this->assertSame('completed', $retained->fresh()->status);
        $this->assertSame(1, \App\Models\V7\FundamentalFact::query()->count());
    }
}
