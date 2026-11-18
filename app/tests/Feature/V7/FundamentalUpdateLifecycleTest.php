<?php

namespace Tests\Feature\V7;

use App\Models\Stock;
use App\Models\V7\FundamentalUpdateJob;
use App\Models\V7\FundamentalUpdateRun;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\Fundamentals\FundamentalDataProvider;
use App\Services\Fundamentals\FundamentalUpdateService;
use App\Services\Fundamentals\FundamentalHistoricalIngestService;
use App\Services\Fundamentals\YahooNoDataOutcome;
use App\Services\AdminOperationalAlertService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_successive_due_batches_advance_to_different_stocks(): void
    {
        $stocks = collect(['AAA', 'BBB', 'CCC', 'DDD'])->map(fn (string $symbol) => Stock::query()->create([
            'symbol' => $symbol,
            'exchange' => 'NSE',
            'name' => $symbol,
            'is_benchmark' => false,
        ]));

        $service = app(FundamentalUpdateService::class);
        $first = $service->createRun('scheduled', 'incremental', null, 2);
        $firstIds = FundamentalUpdateJob::query()->where('run_id', $first->id)->pluck('stock_id')->unique()->sort()->values()->all();

        foreach ($stocks->whereIn('id', $firstIds) as $stock) {
            foreach ([FundamentalDataService::CADENCE_QUARTERLY, FundamentalDataService::CADENCE_ANNUAL] as $cadence) {
                app(FundamentalDataService::class)->recordSuccessfulProviderCheck($stock, $cadence, now());
            }
        }

        $second = $service->createRun('scheduled', 'incremental', null, 2);
        $secondIds = FundamentalUpdateJob::query()->where('run_id', $second->id)->pluck('stock_id')->unique()->sort()->values()->all();

        $this->assertCount(2, $firstIds);
        $this->assertCount(2, $secondIds);
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
    }

    public function test_missing_annual_check_is_not_hidden_by_a_fresh_quarterly_check(): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'HALF',
            'exchange' => 'NSE',
            'name' => 'Half Checked',
            'is_benchmark' => false,
        ]);
        app(FundamentalDataService::class)->recordSuccessfulProviderCheck(
            $stock,
            FundamentalDataService::CADENCE_QUARTERLY,
            now(),
        );

        $run = app(FundamentalUpdateService::class)->createRun('scheduled', 'incremental', null, 10);

        $this->assertTrue(FundamentalUpdateJob::query()
            ->where('run_id', $run->id)
            ->where('stock_id', $stock->id)
            ->where('cadence', FundamentalDataService::CADENCE_ANNUAL)
            ->exists());
    }

    public function test_overdue_quarter_with_recent_unchanged_check_waits_seven_days(): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'STALEPERIOD', 'exchange' => 'NSE', 'name' => 'Stale Period', 'is_benchmark' => false,
        ]);
        $fundamentals = app(FundamentalDataService::class);
        $fundamentals->storeFacts($stock, [[
            'provider' => 'yahoo', 'statement_type' => 'income_statement', 'cadence' => 'quarterly',
            'statement_basis' => 'consolidated', 'fact_key' => 'revenue',
            'period_end' => now()->subDays(150)->toDateString(),
            'availability_date' => now()->subDays(130)->toDateString(), 'value' => 100,
        ]], now()->subDay());
        $fundamentals->recordSuccessfulProviderCheck($stock, 'quarterly', now());
        $fundamentals->recordSuccessfulProviderCheck($stock, 'annual', now());

        $fresh = app(FundamentalUpdateService::class)->createRun('scheduled', 'incremental', null, 10);
        $this->assertSame(0, FundamentalUpdateJob::query()->where('run_id', $fresh->id)->count());

        $fundamentals->recordSuccessfulProviderCheck($stock, 'quarterly', now()->subDays(8));
        $due = app(FundamentalUpdateService::class)->createRun('scheduled', 'incremental', null, 10);
        $this->assertGreaterThan(0, FundamentalUpdateJob::query()
            ->where('run_id', $due->id)->where('stock_id', $stock->id)->count());
    }

    public function test_future_provider_check_is_not_due_from_signed_elapsed_time(): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'FUTURE',
            'exchange' => 'NSE',
            'name' => 'Future Check',
            'is_benchmark' => false,
        ]);
        app(FundamentalDataService::class)->recordSuccessfulProviderCheck(
            $stock,
            FundamentalDataService::CADENCE_QUARTERLY,
            now()->addDay(),
        );
        app(FundamentalDataService::class)->recordSuccessfulProviderCheck(
            $stock,
            FundamentalDataService::CADENCE_ANNUAL,
            now()->addDay(),
        );

        $run = app(FundamentalUpdateService::class)->createRun('scheduled', 'incremental', null, 10);

        $this->assertFalse(FundamentalUpdateJob::query()->where('run_id', $run->id)->where('stock_id', $stock->id)->exists());
    }

    public function test_provider_failure_retries_without_writing_zero_valued_facts(): void
    {
        $stock = Stock::query()->create(['symbol' => 'SBIN', 'exchange' => 'NSE', 'name' => 'State Bank']);
        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('Yahoo fundamentals authentication failed after session refresh.'));
        $this->app->instance(FundamentalDataProvider::class, $provider);
        $alerts = Mockery::mock(AdminOperationalAlertService::class);
        $alerts->shouldNotReceive('recordUnattendedFailure');
        $alerts->shouldNotReceive('syncAndNotify');
        $this->app->instance(AdminOperationalAlertService::class, $alerts);

        $result = app(FundamentalUpdateService::class)->processScheduledIncremental(1);

        $this->assertSame('running', $result['status']);
        $this->assertSame(0, \App\Models\V7\FundamentalFact::query()->count());
        $job = FundamentalUpdateJob::query()->firstOrFail();
        $this->assertSame('retry', $job->status);
        $this->assertSame(86400.0, abs($job->next_attempt_at->diffInSeconds($job->last_attempted_at)));
    }

    public function test_third_failed_attempt_is_actionable(): void
    {
        $stock = Stock::query()->create(['symbol' => 'RETRY3', 'exchange' => 'NSE', 'name' => 'Retry Three']);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        $job = FundamentalUpdateJob::query()->create(['run_id' => $run->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly', 'status' => 'retry', 'attempts' => 2, 'next_attempt_at' => now()->subMinute()]);
        $ingest = Mockery::mock(FundamentalHistoricalIngestService::class);
        $ingest->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('Yahoo rate limit 429'));
        $this->app->instance(FundamentalHistoricalIngestService::class, $ingest);
        $alerts = Mockery::mock(AdminOperationalAlertService::class);
        $alerts->shouldReceive('recordUnattendedFailure')->once()->with(AdminOperationalAlertService::KEY_FUNDAMENTALS_UPDATE_FAILED, Mockery::type('string'), Mockery::type('string'), Mockery::type('array'));
        $alerts->shouldReceive('syncAndNotify')->once();
        $this->app->instance(AdminOperationalAlertService::class, $alerts);

        app(FundamentalUpdateService::class)->process($run, 1);

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame(3, $job->fresh()->attempts);
    }

    public function test_new_fundamentals_runs_exclude_benchmark_stocks(): void
    {
        $benchmark = Stock::query()->create([
            'symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'Nifty 50', 'is_benchmark' => true,
        ]);
        $issuer = Stock::query()->create([
            'symbol' => 'AARNAV', 'exchange' => 'NSE', 'name' => 'Aarnav', 'is_benchmark' => false,
        ]);

        $run = app(FundamentalUpdateService::class)->createRun('scheduled', 'incremental', null, 10);

        $this->assertSame([$issuer->id], FundamentalUpdateJob::query()->where('run_id', $run->id)->pluck('stock_id')->unique()->all());
        $this->assertSame(2, FundamentalUpdateJob::query()->where('run_id', $run->id)->count());
        $this->assertFalse(FundamentalUpdateJob::query()->where('run_id', $run->id)->where('stock_id', $benchmark->id)->exists());
    }

    public function test_existing_benchmark_job_is_skipped_without_provider_or_failure_alert(): void
    {
        $benchmark = Stock::query()->create([
            'symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'Nifty 50', 'is_benchmark' => true,
        ]);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        $job = FundamentalUpdateJob::query()->create([
            'run_id' => $run->id,
            'stock_id' => $benchmark->id,
            'cadence' => 'quarterly',
            'status' => 'retry',
            'attempts' => 2,
            'next_attempt_at' => now()->subMinute(),
        ]);
        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->never();
        $this->app->instance(FundamentalDataProvider::class, $provider);
        $alerts = Mockery::mock(AdminOperationalAlertService::class);
        $alerts->shouldNotReceive('clearUnattendedFailure');
        $this->app->instance(AdminOperationalAlertService::class, $alerts);

        $result = app(FundamentalUpdateService::class)->process($run, 1);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('skipped', $job->fresh()->status);
        $this->assertSame('benchmark instruments are not eligible for issuer fundamentals', $job->fresh()->last_error);
        $this->assertNull($job->fresh()->next_attempt_at);
    }

    public function test_ordinary_equity_no_data_error_still_retries(): void
    {
        $stock = Stock::query()->create(['symbol' => 'AARNAV', 'exchange' => 'NSE', 'name' => 'Aarnav', 'is_benchmark' => false]);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        $job = FundamentalUpdateJob::query()->create([
            'run_id' => $run->id,
            'stock_id' => $stock->id,
            'cadence' => 'quarterly',
            'status' => 'queued',
        ]);
        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('yfinance returned no fundamental statements'));
        $this->app->instance(FundamentalDataProvider::class, $provider);
        $alerts = Mockery::mock(AdminOperationalAlertService::class);
        $alerts->shouldNotReceive('recordUnattendedFailure');
        $alerts->shouldNotReceive('syncAndNotify');
        $this->app->instance(AdminOperationalAlertService::class, $alerts);

        app(FundamentalUpdateService::class)->process($run, 1);

        $this->assertSame('retry', $job->fresh()->status);
        $this->assertSame('yfinance returned no fundamental statements', $job->fresh()->last_error);
    }

    public function test_outcomes_across_slices_merge_once_per_symbol_and_real_error_resets_no_data(): void
    {
        $stock = Stock::query()->create(['symbol' => 'MIXEDCO', 'exchange' => 'NSE', 'name' => 'Mixed Company']);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'queued']);
        $quarterly = FundamentalUpdateJob::query()->create(['run_id' => $run->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly', 'status' => 'queued']);
        FundamentalUpdateJob::query()->create(['run_id' => $run->id, 'stock_id' => $stock->id, 'cadence' => 'annual', 'status' => 'queued']);
        $ingest = Mockery::mock(FundamentalHistoricalIngestService::class);
        $ingest->shouldReceive('fetch')->once()->andThrow(new YahooNoDataOutcome('valid empty statements'));
        $ingest->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('Yahoo connection timeout'));
        $this->app->instance(FundamentalHistoricalIngestService::class, $ingest);
        $alerts = Mockery::mock(AdminOperationalAlertService::class);
        $alerts->shouldNotReceive('recordUnattendedFailure');
        $alerts->shouldNotReceive('syncAndNotify');
        $this->app->instance(AdminOperationalAlertService::class, $alerts);

        app(FundamentalUpdateService::class)->process($run, 1);
        $this->assertSame(0, (int) DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stock->id)->value('consecutive_no_data'));
        app(FundamentalUpdateService::class)->process($run, 1);

        $state = DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stock->id)->first();
        $observation = DB::table('stox_yahoo_fundamental_observations')->where('stock_id', $stock->id)->where('run_id', $run->id)->first();
        $details = json_decode($observation->details, true);
        $this->assertSame(0, (int) ($state?->consecutive_no_data ?? 0));
        $this->assertSame('provider_error', $observation->outcome);
        $this->assertArrayHasKey('saw_no_data', $details, json_encode($details));
        $this->assertTrue($details['saw_no_data']);
        $this->assertTrue($details['retry_pending']);
        $this->assertSame(['quarterly'], $details['no_data_cadences']);
        $this->assertSame(['quarterly', 'annual'], $details['observed_cadences']);
    }

    public function test_nineteenth_no_data_count_does_not_skip_second_cadence_before_it_can_return_data(): void
    {
        $stock = Stock::query()->create(['symbol' => 'PARTIALCOUNT', 'exchange' => 'NSE', 'name' => 'Partial Count']);
        DB::table('stox_yahoo_fundamental_symbol_state')->insert([
            'stock_id' => $stock->id, 'consecutive_no_data' => 19, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'queued']);
        FundamentalUpdateJob::query()->create(['run_id' => $run->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly', 'status' => 'queued']);
        FundamentalUpdateJob::query()->create(['run_id' => $run->id, 'stock_id' => $stock->id, 'cadence' => 'annual', 'status' => 'queued']);
        $ingest = Mockery::mock(FundamentalHistoricalIngestService::class);
        $ingest->shouldReceive('fetch')->once()->andThrow(new YahooNoDataOutcome('valid empty quarterly'))->ordered();
        $ingest->shouldReceive('fetch')->once()->andReturn([[
            'provider' => 'yahoo', 'statement_type' => 'income_statement', 'cadence' => 'annual',
            'statement_basis' => 'consolidated', 'fact_key' => 'revenue', 'period_end' => '2025-12-31',
            'availability_date' => '2026-01-20', 'value' => 100,
        ]])->ordered();
        $ingest->shouldReceive('lastProviderChecked')->andReturn('yahoo');
        $this->app->instance(FundamentalHistoricalIngestService::class, $ingest);

        $service = app(FundamentalUpdateService::class);
        $service->process($run, 1);
        $state = DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stock->id)->first();
        $this->assertSame(19, (int) $state->consecutive_no_data);
        $this->assertNull($state->skipped_at);
        $this->assertFalse((bool) (json_decode((string) DB::table('stox_yahoo_fundamental_observations')->where('run_id', $run->id)->value('details'), true)['counted'] ?? false));

        $service->process($run, 1);
        $state = DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stock->id)->first();
        $this->assertSame(0, (int) $state->consecutive_no_data);
        $this->assertNull($state->skipped_at);
        $this->assertDatabaseHas('stox_yahoo_fundamental_observations', ['run_id' => $run->id, 'stock_id' => $stock->id, 'outcome' => 'data']);
        $this->assertSame('completed', FundamentalUpdateJob::query()->where('run_id', $run->id)->where('cadence', 'annual')->value('status'));
    }

    public function test_twentieth_no_data_count_is_applied_once_when_second_cadence_finishes(): void
    {
        $stock = Stock::query()->create(['symbol' => 'TWOCADENCES', 'exchange' => 'NSE', 'name' => 'Two Cadences']);
        DB::table('stox_yahoo_fundamental_symbol_state')->insert([
            'stock_id' => $stock->id, 'consecutive_no_data' => 19, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'queued']);
        foreach (['quarterly', 'annual'] as $cadence) {
            FundamentalUpdateJob::query()->create(['run_id' => $run->id, 'stock_id' => $stock->id, 'cadence' => $cadence, 'status' => 'queued']);
        }
        $ingest = Mockery::mock(FundamentalHistoricalIngestService::class);
        $ingest->shouldReceive('fetch')->twice()->andThrow(new YahooNoDataOutcome('valid empty statements'));
        $this->app->instance(FundamentalHistoricalIngestService::class, $ingest);

        $service = app(FundamentalUpdateService::class);
        $service->process($run, 1);
        $this->assertSame(19, (int) DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stock->id)->value('consecutive_no_data'));
        $service->process($run, 1);
        $state = DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stock->id)->first();
        $this->assertSame(20, (int) $state->consecutive_no_data);
        $this->assertNotNull($state->skipped_at);
        $this->assertSame(1, DB::table('stox_yahoo_fundamental_observations')->where('stock_id', $stock->id)->where('run_id', $run->id)->count());
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_unchanged_provider_response_updates_check_time_but_preserves_first_fetch_and_revision(): void
    {
        $stock = Stock::query()->create(['symbol' => 'UNCH', 'exchange' => 'NSE', 'name' => 'Unchanged']);
        $service = app(FundamentalDataService::class);
        $first = Carbon::parse('2026-01-01 10:00:00');
        $second = Carbon::parse('2026-02-01 10:00:00');
        $row = [
            'provider' => 'yahoo', 'statement_type' => 'income', 'cadence' => 'quarterly',
            'fact_key' => 'revenue', 'period_end' => '2025-12-31', 'availability_date' => '2026-01-01', 'value' => 100,
        ];

        $service->storeFacts($stock, [$row], $first);
        $service->storeFacts($stock, [$row], $second);
        $fact = \App\Models\V7\FundamentalFact::query()->firstOrFail();

        $this->assertSame($first->toDateTimeString(), $fact->first_fetched_at->toDateTimeString());
        $this->assertSame($second->toDateTimeString(), $fact->last_provider_checked_at->toDateTimeString());
        $this->assertSame(1, $fact->revision_number);
        $this->assertSame(1, \App\Models\V7\FundamentalFact::query()->count());
    }

    public function test_empty_successful_response_is_recorded_as_a_provider_check(): void
    {
        $stock = Stock::query()->create(['symbol' => 'EMPTY', 'exchange' => 'NSE', 'name' => 'Empty']);
        $checkedAt = Carbon::parse('2026-02-01 10:00:00');
        app(FundamentalDataService::class)->recordSuccessfulProviderCheck($stock, 'annual', $checkedAt);

        $this->assertDatabaseHas('stox_fundamental_provider_checks', [
            'stock_id' => $stock->id,
            'cadence' => 'annual',
            'last_successful_check_at' => $checkedAt->toDateTimeString(),
        ]);
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

    public function test_scheduled_slice_skips_without_mutating_when_process_lock_is_held(): void
    {
        $stock = Stock::query()->create(['symbol' => 'LOCKED', 'exchange' => 'NSE', 'name' => 'Locked']);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        $job = FundamentalUpdateJob::query()->create([
            'run_id' => $run->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly', 'status' => 'queued',
        ]);
        $processLock = Cache::lock(FundamentalUpdateService::PROCESS_LOCK_KEY, 900);
        $this->assertTrue($processLock->get());

        try {
            $result = app(FundamentalUpdateService::class)->processScheduledIncremental(1);
        } finally {
            $processLock->release();
        }

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('another_fundamentals_slice_is_in_progress', $result['reason']);
        $this->assertSame('queued', $job->fresh()->status);
        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_scheduled_reconciliation_and_processing_share_one_process_lock(): void
    {
        $stock = Stock::query()->create(['symbol' => 'BOUNDARY', 'exchange' => 'NSE', 'name' => 'Boundary']);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'running']);
        FundamentalUpdateJob::query()->create([
            'run_id' => $run->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly', 'status' => 'queued',
        ]);
        $lockWasHeldDuringProviderCall = false;
        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturnUsing(function () use (&$lockWasHeldDuringProviderCall): array {
            $probe = Cache::lock(FundamentalUpdateService::PROCESS_LOCK_KEY, 900);
            $lockWasHeldDuringProviderCall = ! $probe->get();
            if ($lockWasHeldDuringProviderCall) {
                return [];
            }

            $probe->release();

            return [];
        });
        $this->app->instance(FundamentalDataProvider::class, $provider);

        $result = app(FundamentalUpdateService::class)->processScheduledIncremental(1);

        $this->assertTrue($lockWasHeldDuringProviderCall);
        $this->assertSame('completed', $result['status']);
    }

    public function test_manual_process_path_still_runs_under_its_own_process_lock(): void
    {
        $stock = Stock::query()->create(['symbol' => 'MANUAL2', 'exchange' => 'NSE', 'name' => 'Manual 2']);
        $run = FundamentalUpdateRun::query()->create(['trigger' => 'manual', 'scope' => 'stock', 'status' => 'queued']);
        $job = FundamentalUpdateJob::query()->create([
            'run_id' => $run->id, 'stock_id' => $stock->id, 'cadence' => 'quarterly', 'status' => 'queued',
        ]);
        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn([]);
        $this->app->instance(FundamentalDataProvider::class, $provider);

        $result = app(FundamentalUpdateService::class)->process($run, 1);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('completed', $job->fresh()->status);
    }

    public function test_twenty_no_data_runs_skip_once_per_company_not_once_per_cadence(): void
    {
        $stock = Stock::query()->create(['symbol' => 'EMPTYCO', 'exchange' => 'NSE', 'name' => 'Empty Company', 'is_benchmark' => false]);
        $alerts = Mockery::mock(AdminOperationalAlertService::class);
        $alerts->shouldReceive('recordUnattendedFailure')->once()->with(AdminOperationalAlertService::KEY_FUNDAMENTALS_NO_DATA_SKIP, Mockery::type('string'), Mockery::type('string'), Mockery::type('array'), 'info');
        $alerts->shouldReceive('syncAndNotify')->once();
        $alerts->shouldNotReceive('clearUnattendedFailure');
        $this->app->instance(AdminOperationalAlertService::class, $alerts);

        $time = Carbon::parse('2026-01-01 00:00:00');
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            Carbon::setTestNow($time);
            $time = $time->copy()->addDays(31);
            $run = FundamentalUpdateRun::query()->create(['trigger' => 'scheduled', 'scope' => 'incremental', 'status' => 'queued']);
            foreach ([FundamentalDataService::CADENCE_QUARTERLY, FundamentalDataService::CADENCE_ANNUAL] as $cadence) {
                FundamentalUpdateJob::query()->create(['run_id' => $run->id, 'stock_id' => $stock->id, 'cadence' => $cadence, 'status' => 'queued']);
            }
            $ingest = Mockery::mock(FundamentalHistoricalIngestService::class);
            $ingest->shouldReceive('fetch')->twice()->andThrow(new YahooNoDataOutcome('valid empty statements'));
            $this->app->instance(FundamentalHistoricalIngestService::class, $ingest);
            app(FundamentalUpdateService::class)->process($run, 2);
        }

        $state = DB::table('stox_yahoo_fundamental_symbol_state')->where('stock_id', $stock->id)->first();
        $this->assertSame(20, (int) $state->consecutive_no_data);
        $this->assertNotNull($state->skipped_at);
        $this->assertSame(20, DB::table('stox_yahoo_fundamental_observations')->where('stock_id', $stock->id)->where('outcome', 'no_data')->count());
        $this->assertSame(0, DB::table('stox_fundamental_provider_checks')->where('stock_id', $stock->id)->count());
        Carbon::setTestNow();
    }
}
