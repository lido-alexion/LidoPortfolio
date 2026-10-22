<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use App\Models\V7\FundamentalFact;
use App\Models\V7\FundamentalSetting;
use App\Services\Fundamentals\FundamentalBootstrapService;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\Fundamentals\FundamentalHistoricalIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class BootstrapOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_investor_is_denied_and_reads_do_not_create_runs(): void
    {
        $member = User::factory()->create(['is_admin' => false]);
        $this->actingAs($member)->getJson('/api/v1/admin/fundamentals/bootstrap-operations/runs')->assertForbidden();
        $this->assertDatabaseCount('stox_fundamental_bootstrap_runs', 0);
    }

    public function test_admin_queue_returns_allowlisted_projection_and_rejects_ineligible_ids(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = Stock::query()->create(['symbol' => 'OPS001', 'exchange' => 'NSE', 'name' => 'Ops test', 'is_active' => true, 'is_benchmark' => false]);
        $url = '/api/v1/admin/fundamentals/bootstrap-operations/runs';

        $this->actingAs($admin)->postJson($url, ['scope' => 'stocks', 'stock_ids' => [999999]], [
            'Idempotency-Key' => 'bad-stock-key-001',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stox_fundamental_bootstrap_runs', 0);

        $response = $this->actingAs($admin)->postJson($url, ['scope' => 'stocks', 'stock_ids' => [$stock->id]], [
            'Idempotency-Key' => 'valid-stock-key-001',
        ])->assertCreated();
        $response->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.request.stock_count', 1)
            ->assertJsonPath('data.actor.id', $admin->id)
            ->assertJsonPath('data.actor.name', $admin->name)
            ->assertJsonMissingPath('data.last_error')
            ->assertJsonMissingPath('data.request_snapshot')
            ->assertJsonMissingPath('data.created_by_user_id');
        $run = FundamentalBootstrapRun::query()->firstOrFail();
        $this->assertSame('queued', $run->status);
        $this->assertSame([$stock->id], $run->request_snapshot['stock_ids']);
        $this->assertSame(1, $run->request_snapshot['managed_version']);
        $this->assertSame('admin_fundamentals_bootstrap_operations', $run->request_snapshot['origin']);
    }

    public function test_same_idempotency_key_is_scoped_to_each_admin_identity(): void
    {
        $firstAdmin = User::factory()->create(['is_admin' => true]);
        $secondAdmin = User::factory()->create(['is_admin' => true]);
        $stock = Stock::query()->create(['symbol' => 'OPSKEY', 'exchange' => 'NSE', 'name' => 'Key scope', 'is_active' => true, 'is_benchmark' => false]);
        $body = ['scope' => 'stocks', 'stock_ids' => [$stock->id]];
        $firstResponse = $this->actingAs($firstAdmin)->postJson('/api/v1/admin/fundamentals/bootstrap-operations/runs', $body, ['Idempotency-Key' => 'same-admin-key-001'])->assertCreated();
        $this->actingAs($firstAdmin)->postJson('/api/v1/admin/fundamentals/bootstrap-operations/runs', $body, ['Idempotency-Key' => 'same-admin-key-001'])
            ->assertOk()->assertJsonPath('data.id', $firstResponse->json('data.id'));
        $this->actingAs($secondAdmin)->postJson('/api/v1/admin/fundamentals/bootstrap-operations/runs', $body, ['Idempotency-Key' => 'same-admin-key-001'])->assertCreated();
        $this->assertSame(2, FundamentalBootstrapRun::query()->count());
        $this->assertCount(2, FundamentalBootstrapRun::query()->pluck('created_by_user_id')->unique());
    }

    public function test_all_scope_with_stock_ids_is_a_validation_error_without_mutation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = Stock::query()->create(['symbol' => 'OPSALL', 'exchange' => 'NSE', 'name' => 'All scope', 'is_active' => true, 'is_benchmark' => false]);
        $this->actingAs($admin)->postJson('/api/v1/admin/fundamentals/bootstrap-operations/runs', [
            'scope' => 'all', 'stock_ids' => [$stock->id],
        ], ['Idempotency-Key' => 'all-with-stockids-001'])->assertUnprocessable();
        $this->assertDatabaseCount('stox_fundamental_bootstrap_runs', 0);
    }

    public function test_admin_history_is_read_only_and_never_returns_raw_provider_error(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $run = FundamentalBootstrapRun::query()->create([
            'trigger' => 'manual', 'scope' => 'all', 'status' => 'failed', 'requested' => 1,
            'last_error' => 'provider token=super-secret timeout',
        ]);
        $stock = Stock::query()->create(['symbol' => 'OPSCOVER', 'exchange' => 'NSE', 'name' => 'Coverage', 'is_active' => true, 'is_benchmark' => false]);
        $run->jobs()->create([
            'stock_id' => $stock->id, 'status' => FundamentalBootstrapJob::STATUS_COMPLETE_PARTIAL,
            'coverage_json' => [
                'period_count' => 2, 'cadences' => [
                    'quarterly' => ['provider_rows' => 5, 'accepted_rows' => 4, 'rejected_rows' => 1, 'providers' => ['yahoo', 'unknown', 'nse_official'], 'payload' => ['token' => 'coverage-secret']],
                    'annual' => ['provider_rows' => 1, 'accepted_rows' => 1, 'rejected_rows' => 0, 'providers' => ['bse_official']],
                ], 'raw_provider_payload' => ['authorization' => 'coverage-secret'],
            ],
        ]);
        $this->actingAs($admin)->getJson('/api/v1/admin/fundamentals/bootstrap-operations/runs')
            ->assertOk()->assertJsonPath('data.0.id', $run->id)
            ->assertJsonPath('data.0.failure_reason', 'Provider request timed out.')
            ->assertDontSee('super-secret');
        $this->actingAs($admin)->getJson("/api/v1/admin/fundamentals/bootstrap-operations/runs/{$run->id}")
            ->assertOk()->assertJsonPath('data.jobs.data.0.coverage.period_count', 2)
            ->assertJsonPath('data.jobs.data.0.coverage.cadences.quarterly.providers', ['yahoo', 'nse_official'])
            ->assertDontSee('coverage-secret')->assertDontSee('raw_provider_payload');
        $this->actingAs($admin)->getJson('/api/v1/admin/fundamentals/bootstrap')
            ->assertOk()->assertJsonMissingPath('data.latest_run.last_error')
            ->assertJsonMissingPath('data.latest_run.summary_json')
            ->assertDontSee('super-secret');
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($run->fresh()->started_at);
    }

    public function test_retry_failed_creates_a_new_run_and_preserves_source_history(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = Stock::query()->create(['symbol' => 'OPSRETRY', 'exchange' => 'NSE', 'name' => 'Retry test', 'is_active' => true, 'is_benchmark' => false]);
        $source = FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'completed', 'requested' => 1]);
        $sourceJob = FundamentalBootstrapJob::query()->create(['run_id' => $source->id, 'stock_id' => $stock->id, 'status' => FundamentalBootstrapJob::STATUS_FAILED]);

        $response = $this->actingAs($admin)->postJson("/api/v1/admin/fundamentals/bootstrap-operations/runs/{$source->id}/retry-failed", [], [
            'Idempotency-Key' => 'retry-failed-key-001',
        ])->assertCreated();
        $this->actingAs($admin)->postJson("/api/v1/admin/fundamentals/bootstrap-operations/runs/{$source->id}/retry-failed", [], [
            'Idempotency-Key' => 'retry-failed-key-001',
        ])->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
        $this->assertNotSame($source->id, $response->json('data.id'));
        $this->assertSame(FundamentalBootstrapJob::STATUS_FAILED, $sourceJob->fresh()->status);
        $this->assertSame(2, FundamentalBootstrapRun::query()->count());
        $this->assertSame(FundamentalBootstrapJob::STATUS_QUEUED, FundamentalBootstrapJob::query()->where('run_id', $response->json('data.id'))->value('status'));
    }

    public function test_forced_rerun_reaches_existing_governed_ingest_and_keeps_prior_job_history(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = Stock::query()->create(['symbol' => 'OPSFORCE', 'exchange' => 'NSE', 'name' => 'Force test', 'is_active' => true, 'is_benchmark' => false]);
        FundamentalSetting::query()->create([
            'quarterly_freshness_months' => 5, 'annual_freshness_months' => 15,
            'request_delay_ms' => 0, 'max_attempts' => 3, 'provider' => 'yahoo', 'paused' => false,
        ]);
        $priorRun = FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'completed', 'requested' => 1]);
        $priorJob = FundamentalBootstrapJob::query()->create(['run_id' => $priorRun->id, 'stock_id' => $stock->id, 'status' => FundamentalBootstrapJob::STATUS_COMPLETE_GOOD]);
        $originalRow = [
            'statement_type' => 'income', 'cadence' => 'quarterly', 'fact_key' => 'revenue',
            'period_end' => '2024-03-31', 'value' => 100, 'currency' => 'INR', 'provider' => 'yahoo',
            'availability_date' => '2024-05-01', 'source_meta' => ['record' => 'prior-provenance'],
        ];
        app(FundamentalDataService::class)->storeFacts($stock, [$originalRow], now()->subDays(5));
        $originalFact = FundamentalFact::query()->where('stock_id', $stock->id)->where('fact_key', 'revenue')->firstOrFail();

        $ingest = Mockery::mock(FundamentalHistoricalIngestService::class);
        $ingest->shouldReceive('fetch')->twice()->withArgs(fn ($target, $cadence, $manual) => $target->id === $stock->id && $manual === true)
            ->andReturn([
                [
                    'statement_type' => 'income', 'cadence' => 'quarterly', 'fact_key' => 'revenue',
                    'period_end' => '2024-03-31', 'value' => 120, 'currency' => 'INR', 'provider' => 'nse_official',
                    'availability_date' => '2024-05-02', 'source_meta' => ['record' => 'accepted-restatement'],
                ],
                [
                    'statement_type' => 'income', 'cadence' => 'quarterly', 'fact_key' => 'profit_margin_pct',
                    'period_end' => '2024-03-31', 'value' => 900, 'currency' => 'INR', 'provider' => 'nse_official',
                ],
            ], []);
        $this->app->instance(FundamentalHistoricalIngestService::class, $ingest);

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/fundamentals/bootstrap-operations/runs', [
            'scope' => 'stocks', 'stock_ids' => [$stock->id], 'force' => true, 'reason' => 'Recheck a prior bootstrap result',
        ], ['Idempotency-Key' => 'force-rerun-key-001'])->assertCreated();
        $newRun = FundamentalBootstrapRun::query()->findOrFail($response->json('data.id'));
        app(FundamentalBootstrapService::class)->process($newRun, 1);

        $this->assertSame(FundamentalBootstrapJob::STATUS_COMPLETE_GOOD, $priorJob->fresh()->status);
        $rerunJob = FundamentalBootstrapJob::query()->where('run_id', $newRun->id)->firstOrFail();
        $this->assertSame(FundamentalBootstrapJob::STATUS_COMPLETE_PARTIAL, $rerunJob->status);
        $this->assertSame(1, (int) $rerunJob->facts_upgraded);
        $this->assertSame(1, (int) $rerunJob->facts_rejected);
        $revisions = FundamentalFact::query()->where('stock_id', $stock->id)->where('fact_key', 'revenue')->orderBy('revision_number')->get();
        $this->assertCount(2, $revisions);
        $this->assertFalse((bool) $revisions[0]->is_current);
        $this->assertSame($originalFact->revision_hash, $revisions[0]->revision_hash);
        $this->assertSame($originalFact->first_fetched_at->toIso8601String(), $revisions[0]->first_fetched_at->toIso8601String());
        $this->assertSame(['record' => 'prior-provenance'], $revisions[0]->source_meta);
        $this->assertTrue((bool) $revisions[1]->is_current);
        $this->assertEquals(120.0, (float) $revisions[1]->value);
        $this->assertSame('nse_official', $revisions[1]->provider);
        $this->assertSame(['record' => 'accepted-restatement'], $revisions[1]->source_meta);
        $this->assertDatabaseMissing('stox_fundamental_facts', ['stock_id' => $stock->id, 'fact_key' => 'profit_margin_pct']);
        $this->assertSame(2, FundamentalBootstrapRun::query()->count());
    }

    public function test_cancel_returns_conflict_when_slice_lock_is_busy_and_is_idempotent_after_success(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = Stock::query()->create(['symbol' => 'OPSCANCEL', 'exchange' => 'NSE', 'name' => 'Cancel test', 'is_active' => true, 'is_benchmark' => false]);
        $run = FundamentalBootstrapRun::query()->create(['trigger' => 'manual', 'scope' => 'stocks', 'status' => 'queued', 'requested' => 1]);
        $job = FundamentalBootstrapJob::query()->create(['run_id' => $run->id, 'stock_id' => $stock->id, 'status' => FundamentalBootstrapJob::STATUS_QUEUED]);
        $lock = Cache::lock(FundamentalBootstrapService::PROCESS_LOCK_KEY, 900);
        $this->assertTrue($lock->get());
        try {
            $this->actingAs($admin)->postJson("/api/v1/admin/fundamentals/bootstrap-operations/runs/{$run->id}/cancel", ['reason' => 'Stop this run'])
                ->assertStatus(409)->assertJsonPath('error.code', 'bootstrap_cancellation_busy');
            $this->assertSame('queued', $run->fresh()->status);
            $this->assertSame(FundamentalBootstrapJob::STATUS_QUEUED, $job->fresh()->status);
        } finally {
            $lock->release();
        }

        $this->actingAs($admin)->postJson("/api/v1/admin/fundamentals/bootstrap-operations/runs/{$run->id}/cancel", ['reason' => 'Stop this run'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancelled_jobs', 1);
        $this->actingAs($admin)->postJson("/api/v1/admin/fundamentals/bootstrap-operations/runs/{$run->id}/cancel", ['reason' => 'Repeat cancellation'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(FundamentalBootstrapJob::STATUS_CANCELLED, $job->fresh()->status);
        $this->actingAs($admin)->getJson("/api/v1/admin/fundamentals/bootstrap-operations/runs/{$run->id}")
            ->assertOk()->assertJsonPath('data.progress.pending', 0);
    }
}
