<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use App\Models\V7\FundamentalSetting;
use App\Services\Fundamentals\FundamentalBootstrapService;
use App\Services\Fundamentals\FundamentalDataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
