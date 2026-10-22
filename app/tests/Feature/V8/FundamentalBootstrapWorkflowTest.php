<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use App\Models\V7\FundamentalFact;
use App\Models\V7\FundamentalSetting;
use App\Services\Fundamentals\FundamentalBootstrapService;
use App\Services\Fundamentals\FundamentalDataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class FundamentalBootstrapWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FundamentalSetting::query()->create([
            'quarterly_freshness_months' => 5,
            'annual_freshness_months' => 15,
            'request_delay_ms' => 0,
            'max_attempts' => 3,
            'provider' => 'yahoo',
            'paused' => false,
        ]);
    }

    public function test_dry_run_reports_active_universe_count(): void
    {
        Stock::query()->create(['symbol' => 'TCS', 'exchange' => 'NSE', 'name' => 'TCS']);
        Stock::query()->create(['symbol' => 'INFY', 'exchange' => 'NSE', 'name' => 'Infosys']);

        $preview = app(FundamentalBootstrapService::class)->createRun('all', dryRun: true);

        $this->assertTrue($preview['dry_run']);
        $this->assertSame(2, $preview['stock_count']);
    }

    public function test_unknown_explicit_symbol_does_not_create_universe_run(): void
    {
        Stock::query()->create(['symbol' => 'TCS', 'exchange' => 'NSE', 'name' => 'TCS']);

        try {
            app(FundamentalBootstrapService::class)->createRun('stock', [], dryRun: false);
            $this->fail('Expected an unknown targeted symbol to be rejected.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('No stock matched', $error->getMessage());
        }

        $this->assertDatabaseCount('stox_fundamental_bootstrap_runs', 0);
        $this->assertDatabaseCount('stox_fundamental_bootstrap_jobs', 0);
    }

    public function test_inactive_explicit_stock_does_not_create_run(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(FundamentalBootstrapService::class)->createRun('stocks', [999999], dryRun: true);
    }

    public function test_bootstrap_run_persists_facts_and_completes_job(): void
    {
        $stock = Stock::query()->create(['symbol' => 'TCS', 'exchange' => 'NSE', 'name' => 'TCS']);

        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->twice()->andReturn([
            [
                'statement_type' => 'income',
                'cadence' => 'quarterly',
                'fact_key' => 'revenue',
                'period_end' => '2024-03-31',
                'value' => 1000000,
                'currency' => 'INR',
                'provider' => 'yahoo',
            ],
        ], [
            [
                'statement_type' => 'income',
                'cadence' => 'annual',
                'fact_key' => 'revenue',
                'period_end' => '2024-03-31',
                'value' => 4000000,
                'currency' => 'INR',
                'provider' => 'yahoo',
            ],
        ]);
        $this->app->instance(FundamentalDataProvider::class, $provider);

        $service = app(FundamentalBootstrapService::class);
        $run = $service->createRun('stock', [$stock->id]);
        $service->process($run, 5);

        $job = FundamentalBootstrapJob::query()->where('run_id', $run->id)->first();
        $this->assertSame(FundamentalBootstrapJob::STATUS_COMPLETE_GOOD, $job->status);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('stox_fundamental_facts', 2);

        $service->process($run->fresh(), 5);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('stox_fundamental_facts', 2);
    }

    public function test_anomaly_rejection_marks_partial_completion(): void
    {
        $stock = Stock::query()->create(['symbol' => 'RELIANCE', 'exchange' => 'NSE', 'name' => 'Reliance']);

        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->twice()->andReturn([
            [
                'statement_type' => 'income',
                'cadence' => 'quarterly',
                'fact_key' => 'revenue',
                'period_end' => '2024-03-31',
                'value' => 'not-a-number',
                'provider' => 'yahoo',
            ],
        ], []);
        $this->app->instance(FundamentalDataProvider::class, $provider);

        $service = app(FundamentalBootstrapService::class);
        $run = $service->createRun('stock', [$stock->id]);
        $service->process($run, 5);

        $job = FundamentalBootstrapJob::query()->where('run_id', $run->id)->first();
        $this->assertSame(FundamentalBootstrapJob::STATUS_COMPLETE_PARTIAL, $job->status);
        $this->assertGreaterThan(0, (int) $job->facts_rejected);
    }

    public function test_rerun_failed_scope_uses_latest_run_failures(): void
    {
        $stock = Stock::query()->create(['symbol' => 'HDFC', 'exchange' => 'NSE', 'name' => 'HDFC']);
        $run = FundamentalBootstrapRun::query()->create([
            'trigger' => 'manual',
            'scope' => 'all',
            'status' => 'completed',
            'requested' => 1,
        ]);
        FundamentalBootstrapJob::query()->create([
            'run_id' => $run->id,
            'stock_id' => $stock->id,
            'status' => FundamentalBootstrapJob::STATUS_FAILED,
        ]);

        $preview = app(FundamentalBootstrapService::class)->createRun('rerun_failed', dryRun: true);

        $this->assertSame(1, $preview['stock_count']);
        $this->assertContains('HDFC', $preview['symbols']);
    }

    public function test_bootstrap_retry_backoff_keeps_unavailable_job_durable_and_not_due(): void
    {
        $stock = Stock::query()->create(['symbol' => 'BLOCKED', 'exchange' => 'NSE', 'name' => 'Blocked']);
        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('Yahoo unavailable'));
        $this->app->instance(FundamentalDataProvider::class, $provider);

        $service = app(FundamentalBootstrapService::class);
        $run = $service->createRun('stock', [$stock->id]);
        $service->process($run, 1);

        $job = FundamentalBootstrapJob::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertSame(FundamentalBootstrapJob::STATUS_RETRY, $job->status);
        $this->assertNotNull($job->next_attempt_at);

        $service->process($run, 1);
        $this->assertSame(1, $job->fresh()->attempts);
    }

    public function test_due_retry_is_claimed_and_finishes_without_replacing_its_run_history(): void
    {
        $stock = Stock::query()->create(['symbol' => 'DUE-RETRY', 'exchange' => 'NSE', 'name' => 'Due retry', 'is_active' => true, 'is_benchmark' => true]);
        $run = FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'running', 'requested' => 1]);
        $job = FundamentalBootstrapJob::query()->create([
            'run_id' => $run->id, 'stock_id' => $stock->id, 'status' => FundamentalBootstrapJob::STATUS_RETRY,
            'attempts' => 1, 'next_attempt_at' => now()->subSecond(),
        ]);

        app(FundamentalBootstrapService::class)->process($run, 1);

        $this->assertSame(FundamentalBootstrapJob::STATUS_COMPLETE_NO_DATA, $job->fresh()->status);
        $this->assertSame(2, $job->fresh()->attempts);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(1, FundamentalBootstrapRun::query()->count());
    }

    public function test_automatic_queue_worker_never_adopts_legacy_queued_or_running_runs(): void
    {
        $queuedStock = Stock::query()->create(['symbol' => 'LEGACY-Q', 'exchange' => 'NSE', 'name' => 'Legacy queued']);
        $runningStock = Stock::query()->create(['symbol' => 'LEGACY-R', 'exchange' => 'NSE', 'name' => 'Legacy running']);
        $runs = collect([
            FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'queued', 'requested' => 1]),
            FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'running', 'requested' => 1]),
            FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'queued', 'request_snapshot' => ['managed_version' => 1]]),
            FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'running', 'request_snapshot' => ['managed_version' => 1]]),
            FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'queued', 'request_snapshot' => ['managed_version' => 1, 'origin' => 'untrusted_origin']]),
            FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'running', 'request_snapshot' => ['managed_version' => 1, 'origin' => 'untrusted_origin']]),
        ]);
        FundamentalBootstrapJob::query()->create(['run_id' => $runs[0]->id, 'stock_id' => $queuedStock->id, 'status' => FundamentalBootstrapJob::STATUS_QUEUED]);
        FundamentalBootstrapJob::query()->create(['run_id' => $runs[1]->id, 'stock_id' => $runningStock->id, 'status' => FundamentalBootstrapJob::STATUS_RUNNING]);

        $ingest = \Mockery::mock(\App\Services\Fundamentals\FundamentalHistoricalIngestService::class);
        $ingest->shouldNotReceive('fetch');
        $this->app->instance(\App\Services\Fundamentals\FundamentalHistoricalIngestService::class, $ingest);
        Artisan::call('stox:fundamentals-bootstrap-work-once');

        $this->assertSame(['queued', 'running', 'queued', 'running', 'queued', 'running'], $runs->map(fn ($run) => $run->fresh()->status)->all());
        $this->assertSame(FundamentalBootstrapJob::STATUS_QUEUED, FundamentalBootstrapJob::query()->where('run_id', $runs[0]->id)->value('status'));
        $this->assertSame(FundamentalBootstrapJob::STATUS_RUNNING, FundamentalBootstrapJob::query()->where('run_id', $runs[1]->id)->value('status'));
    }

    public function test_version_only_queued_slot_is_not_classified_as_an_ops_owner(): void
    {
        $admin = \App\Models\User::factory()->create(['is_admin' => true]);
        $stock = Stock::query()->create(['symbol' => 'WRONG-ORIGIN', 'exchange' => 'NSE', 'name' => 'Wrong origin', 'is_active' => true, 'is_benchmark' => false]);
        $managed = app(\App\Services\Fundamentals\BootstrapOperationsService::class)->launch(
            ['scope' => 'stocks', 'stock_ids' => [$stock->id]], $admin->id, 'Admin', 'managed-right-origin-001',
        )->run;
        $untrusted = FundamentalBootstrapRun::query()->create([
            'trigger' => 'manual', 'scope' => 'stocks', 'status' => 'queued',
            'request_snapshot' => ['managed_version' => 1],
        ]);
        \Illuminate\Support\Facades\DB::table('stox_fund_bootstrap_control')->where('id', 1)->update(['active_run_id' => $untrusted->id]);
        $stock->forceFill(['is_benchmark' => true])->save();

        app(FundamentalBootstrapService::class)->process($managed, 1);

        $this->assertSame('queued', $untrusted->fresh()->status);
        $this->assertSame('completed', $managed->fresh()->status);
    }

    public function test_cancellation_waits_for_processing_lock_preserves_terminal_jobs_and_fences_resume(): void
    {
        $processingStock = Stock::query()->create(['symbol' => 'PROCESSING', 'exchange' => 'NSE', 'name' => 'Processing']);
        $cancelledStock = Stock::query()->create(['symbol' => 'CANCELLED', 'exchange' => 'NSE', 'name' => 'Cancelled']);
        $failedStock = Stock::query()->create(['symbol' => 'FAILED', 'exchange' => 'NSE', 'name' => 'Failed']);
        $completedStock = Stock::query()->create(['symbol' => 'COMPLETED', 'exchange' => 'NSE', 'name' => 'Completed']);
        $unrelatedStock = Stock::query()->create(['symbol' => 'OTHER', 'exchange' => 'NSE', 'name' => 'Other']);

        $run = FundamentalBootstrapRun::query()->create([
            'trigger' => 'manual', 'scope' => 'stocks', 'status' => 'running', 'requested' => 4, 'queued' => 2,
        ]);
        $processingJob = FundamentalBootstrapJob::query()->create([
            'run_id' => $run->id, 'stock_id' => $processingStock->id, 'status' => FundamentalBootstrapJob::STATUS_QUEUED,
        ]);
        $cancelledJob = FundamentalBootstrapJob::query()->create([
            'run_id' => $run->id, 'stock_id' => $cancelledStock->id, 'status' => FundamentalBootstrapJob::STATUS_QUEUED,
        ]);
        $failedJob = FundamentalBootstrapJob::query()->create([
            'run_id' => $run->id, 'stock_id' => $failedStock->id, 'status' => FundamentalBootstrapJob::STATUS_FAILED,
            'attempts' => 3, 'last_error' => 'retained failure evidence', 'completed_at' => now()->subMinute(),
        ]);
        $completedJob = FundamentalBootstrapJob::query()->create([
            'run_id' => $run->id, 'stock_id' => $completedStock->id, 'status' => FundamentalBootstrapJob::STATUS_COMPLETE_GOOD,
            'attempts' => 1, 'facts_inserted' => 7, 'completed_at' => now()->subMinute(),
        ]);
        $fact = FundamentalFact::query()->create([
            'stock_id' => $completedStock->id,
            'provider' => 'yahoo',
            'statement_type' => 'income',
            'cadence' => 'annual',
            'statement_basis' => 'consolidated',
            'fact_key' => 'revenue',
            'period_end' => '2025-03-31',
            'value' => 1250000,
            'currency' => 'INR',
            'availability_date' => '2025-05-15',
            'first_fetched_at' => now()->subMinute(),
            'revision_hash' => hash('sha256', 'retained fact'),
            'revision_number' => 1,
            'is_current' => true,
        ]);
        $otherRun = FundamentalBootstrapRun::query()->create([
            'trigger' => 'manual', 'scope' => 'stock', 'status' => 'queued', 'requested' => 1, 'queued' => 1,
        ]);
        $otherJob = FundamentalBootstrapJob::query()->create([
            'run_id' => $otherRun->id, 'stock_id' => $unrelatedStock->id, 'status' => FundamentalBootstrapJob::STATUS_QUEUED,
        ]);

        $cancellationAttemptsDuringFetch = [];
        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->twice()->andReturnUsing(function () use ($run, &$cancellationAttemptsDuringFetch): array {
            $cancellationAttemptsDuringFetch[] = app(FundamentalBootstrapService::class)->cancelRun(
                $run,
                'operator:test',
                'stop stale run',
            );

            return [];
        });
        $this->app->instance(FundamentalDataProvider::class, $provider);

        $service = app(FundamentalBootstrapService::class);
        $service->process($run, 1);

        $this->assertCount(2, $cancellationAttemptsDuringFetch);
        foreach ($cancellationAttemptsDuringFetch as $attempt) {
            $this->assertSame('another_bootstrap_slice_is_in_progress', $attempt['skipped_reason']);
        }
        $this->assertSame(FundamentalBootstrapJob::STATUS_COMPLETE_NO_DATA, $processingJob->fresh()->status);
        $this->assertSame('running', $run->fresh()->status);

        $cancelled = $service->cancelRun($run->fresh(), 'operator:test', 'stale recovery run');
        $cancelledJob->refresh();
        $failedJob->refresh();
        $completedJob->refresh();
        $audit = $cancelled['summary_json']['cancellation'];

        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame(FundamentalBootstrapJob::STATUS_CANCELLED, $cancelledJob->status);
        $this->assertNotNull($cancelledJob->completed_at);
        $this->assertNull($cancelledJob->next_attempt_at);
        $this->assertSame(FundamentalBootstrapJob::STATUS_FAILED, $failedJob->status);
        $this->assertSame('retained failure evidence', $failedJob->last_error);
        $this->assertSame(FundamentalBootstrapJob::STATUS_COMPLETE_GOOD, $completedJob->status);
        $this->assertSame(7, $completedJob->facts_inserted);
        $this->assertDatabaseHas('stox_fundamental_facts', ['id' => $fact->id, 'value' => '1250000.000000']);
        $this->assertSame(1, $audit['cancelled_jobs']);
        $this->assertSame(1, $audit['cancelled_by_previous_status']['queued']);
        $this->assertSame(1, $audit['preserved_terminal_jobs']['failed']);
        $this->assertSame(1, $audit['preserved_terminal_jobs']['complete_good']);
        $this->assertSame('operator:test', $audit['actor']);
        $this->assertSame('stale recovery run', $audit['reason']);
        $this->assertSame(FundamentalBootstrapJob::STATUS_QUEUED, $otherJob->fresh()->status);
        $this->assertSame('queued', $otherRun->fresh()->status);

        $again = $service->cancelRun($run->fresh(), 'different actor', 'different reason');
        $this->assertSame($audit, $again['summary_json']['cancellation']);
        $service->process($run->fresh(), 100);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(FundamentalBootstrapJob::STATUS_CANCELLED, $cancelledJob->fresh()->status);
    }

    public function test_operator_cli_requires_audited_cancel_arguments_and_cancels_only_requested_run(): void
    {
        $targetStock = Stock::query()->create(['symbol' => 'TARGET', 'exchange' => 'NSE', 'name' => 'Target']);
        $otherStock = Stock::query()->create(['symbol' => 'OTHER', 'exchange' => 'NSE', 'name' => 'Other']);
        $targetRun = FundamentalBootstrapRun::query()->create([
            'trigger' => 'manual', 'scope' => 'stock', 'status' => 'queued', 'requested' => 1, 'queued' => 1,
        ]);
        $targetJob = FundamentalBootstrapJob::query()->create([
            'run_id' => $targetRun->id, 'stock_id' => $targetStock->id, 'status' => FundamentalBootstrapJob::STATUS_QUEUED,
        ]);
        $otherRun = FundamentalBootstrapRun::query()->create([
            'trigger' => 'manual', 'scope' => 'stock', 'status' => 'queued', 'requested' => 1, 'queued' => 1,
        ]);
        $otherJob = FundamentalBootstrapJob::query()->create([
            'run_id' => $otherRun->id, 'stock_id' => $otherStock->id, 'status' => FundamentalBootstrapJob::STATUS_QUEUED,
        ]);

        $invalidExit = Artisan::call('stox:fundamentals-bootstrap', [
            '--cancel-run' => '0',
            '--actor' => 'operator:recovery',
            '--reason' => 'invalid run id',
        ]);
        $this->assertSame(2, $invalidExit);
        $this->assertSame(FundamentalBootstrapJob::STATUS_QUEUED, $targetJob->fresh()->status);

        $exit = Artisan::call('stox:fundamentals-bootstrap', [
            '--cancel-run' => $targetRun->id,
            '--actor' => 'operator:recovery',
            '--reason' => 'run is stale and superseded',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(FundamentalBootstrapJob::STATUS_CANCELLED, $targetJob->fresh()->status);
        $this->assertSame('operator:recovery', $targetRun->fresh()->summary_json['cancellation']['actor']);
        $this->assertSame(FundamentalBootstrapJob::STATUS_QUEUED, $otherJob->fresh()->status);
        $this->assertSame('queued', $otherRun->fresh()->status);
    }

    public function test_cancel_run_returns_busy_while_shared_process_lock_is_held(): void
    {
        $stock = Stock::query()->create(['symbol' => 'LOCKED', 'exchange' => 'NSE', 'name' => 'Locked']);
        $run = FundamentalBootstrapRun::query()->create([
            'trigger' => 'manual', 'scope' => 'stock', 'status' => 'running', 'requested' => 1, 'queued' => 1,
        ]);
        $job = FundamentalBootstrapJob::query()->create([
            'run_id' => $run->id, 'stock_id' => $stock->id, 'status' => FundamentalBootstrapJob::STATUS_QUEUED,
        ]);
        $lock = Cache::lock(FundamentalBootstrapService::PROCESS_LOCK_KEY, 900);
        $this->assertTrue($lock->get());

        try {
            $result = app(FundamentalBootstrapService::class)->cancelRun($run, 'operator:test', 'stale');
            $this->assertSame('another_bootstrap_slice_is_in_progress', $result['skipped_reason']);
            $this->assertSame(FundamentalBootstrapJob::STATUS_QUEUED, $job->fresh()->status);
            $this->assertSame('running', $run->fresh()->status);
        } finally {
            $lock->release();
        }
    }

    public function test_expired_process_lease_cannot_write_facts_or_resurrect_cancelled_job(): void
    {
        $stock = Stock::query()->create(['symbol' => 'LEASE', 'exchange' => 'NSE', 'name' => 'Lease']);
        $run = FundamentalBootstrapRun::query()->create([
            'trigger' => 'manual', 'scope' => 'stock', 'status' => 'queued', 'requested' => 1, 'queued' => 1,
        ]);
        $job = FundamentalBootstrapJob::query()->create([
            'run_id' => $run->id, 'stock_id' => $stock->id, 'status' => FundamentalBootstrapJob::STATUS_QUEUED,
        ]);

        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturnUsing(function () use ($run): array {
            // Simulate an expired cache lease while the old worker is still inside provider I/O.
            Cache::lock(FundamentalBootstrapService::PROCESS_LOCK_KEY, 900)->forceRelease();
            $cancelled = app(FundamentalBootstrapService::class)->cancelRun($run->fresh(), 'operator:test', 'lease expired during fetch');
            $this->assertSame('cancelled', $cancelled['status']);

            return [[
                'statement_type' => 'income',
                'cadence' => 'quarterly',
                'fact_key' => 'revenue',
                'period_end' => '2025-06-30',
                'value' => 1000000,
                'currency' => 'INR',
                'provider' => 'yahoo',
            ]];
        });
        $this->app->instance(FundamentalDataProvider::class, $provider);

        app(FundamentalBootstrapService::class)->process($run, 1);

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(FundamentalBootstrapJob::STATUS_CANCELLED, $job->fresh()->status);
        $this->assertSame(0, (int) FundamentalFact::query()->where('stock_id', $stock->id)->count());
        $this->assertSame(1, $job->fresh()->attempts);

        app(FundamentalBootstrapService::class)->process($run->fresh(), 1);
        $this->assertSame(FundamentalBootstrapJob::STATUS_CANCELLED, $job->fresh()->status);
        $this->assertSame(0, (int) FundamentalFact::query()->where('stock_id', $stock->id)->count());
    }

    public function test_resume_workflow_forwards_validated_run_and_slice_inputs_to_remote_worker(): void
    {
        $workflow = file_get_contents(base_path('../.github/workflows/run-fundamentals-bootstrap.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString('STOXLA_FUNDAMENTALS_RUN_ID: ${{ inputs.run_id }}', $workflow);
        $this->assertStringContainsString('STOXLA_FUNDAMENTALS_SLICE_SECONDS: ${{ inputs.slice_seconds }}', $workflow);
        $this->assertStringContainsString('[[ "$STOXLA_FUNDAMENTALS_RUN_ID" =~ ^[0-9]+$ ]]', $workflow);
        $this->assertStringContainsString('[[ "$STOXLA_FUNDAMENTALS_SLICE_SECONDS" =~ ^[0-9]+$ ]]', $workflow);
        $this->assertStringContainsString('printf -v remote_command', $workflow);
        $this->assertStringContainsString('STOXLA_FUNDAMENTALS_RUN_ID=%q', $workflow);
        $this->assertStringContainsString('STOXLA_FUNDAMENTALS_SLICE_SECONDS=%q', $workflow);
        $this->assertStringContainsString('bash %q', $workflow);
        $this->assertStringNotContainsString("STOXLA_FUNDAMENTALS_RUN_ID='\$STOXLA_FUNDAMENTALS_RUN_ID'", $workflow);
        $this->assertStringNotContainsString("STOXLA_FUNDAMENTALS_SLICE_SECONDS='\$STOXLA_FUNDAMENTALS_SLICE_SECONDS'", $workflow);
    }
}
