<?php

namespace Tests\Feature\OPS001;

use App\Models\NotificationSource;
use App\Models\Stock;
use App\Models\User;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use App\Services\Fundamentals\BootstrapCoverageOperationsService;
use App\Services\Fundamentals\BootstrapOperationsProducer;
use App\Services\Fundamentals\BootstrapOperationalOutcomeService;
use App\Services\Fundamentals\BootstrapScheduleOperationsService;
use App\Services\Fundamentals\FundamentalBootstrapService;
use App\Services\Fundamentals\BootstrapOperationsService;
use App\Services\Fundamentals\Scheduling\BootstrapScheduleLaunchAdapter;
use App\Services\Fundamentals\Scheduling\BootstrapEligibleStockResolver;
use App\Services\Fundamentals\Scheduling\CanonicalBootstrapScheduleLaunchAdapter;
use App\Services\Notification\GenericNotificationPublisher;
use App\Services\Notification\NotificationPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BootstrapOperationsProducerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_projection_cursor_restarts_without_skipping_or_double_reconciling_ids(): void
    {
        User::factory()->create(['is_admin' => true]);
        $managed = $this->managedRun('completed');
        foreach (range(1, 53) as $i) {
            FundamentalBootstrapJob::query()->create(['run_id' => $managed->id, 'stock_id' => $this->stock($i)->id, 'status' => 'complete_good']);
        }
        $jobIds = FundamentalBootstrapJob::query()->where('run_id', $managed->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $legacy = FundamentalBootstrapRun::query()->create(['scope' => 'all', 'trigger' => 'manual', 'status' => 'completed']);

        $schedules = $this->emptyScheduleService();
        $coverage = app(BootstrapCoverageOperationsService::class);
        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publishEvent')->once()->andReturn(new NotificationSource());
        $producerFactory = fn () => $this->producer($schedules, $coverage, $this->engine(), $this->outcomes($publisher));

        // A fresh producer after each bounded command models a process restart.
        $producerFactory()->tick();
        $first = DB::table('stox_bootstrap_projection_receipts')->where('run_id', $managed->id)->first();
        $this->assertSame($jobIds[49], (int) $first->last_job_id);
        $this->assertNull($first->reconciliation_completed_at);
        $producerFactory()->tick();
        $second = DB::table('stox_bootstrap_projection_receipts')->where('run_id', $managed->id)->first();
        $this->assertSame($jobIds[52], (int) $second->last_job_id);
        $this->assertNull($second->reconciliation_completed_at);
        $producerFactory()->tick();
        $complete = DB::table('stox_bootstrap_projection_receipts')->where('run_id', $managed->id)->first();
        $this->assertNotNull($complete->reconciliation_completed_at);
        $this->assertNotNull($complete->outcomes_completed_at);
        $this->assertDatabaseMissing('stox_bootstrap_projection_receipts', ['run_id' => $legacy->id]);
    }

    public function test_reconciliation_and_outcome_failure_roll_back_cursor_then_retry_without_duplicate_acceptance(): void
    {
        User::factory()->create(['is_admin' => true]);
        $run = $this->managedRun('failed');
        $schedules = $this->emptyScheduleService();
        $coverage = Mockery::mock(BootstrapCoverageOperationsService::class);
        $coverage->shouldNotReceive('reconcileJobs');
        $publisher = Mockery::mock(NotificationPublisher::class);
        $attempt = 0;
        $publisher->shouldReceive('publishEvent')->twice()->andReturnUsing(function () use (&$attempt): NotificationSource {
            if (++$attempt === 1) throw new RuntimeException('publisher unavailable');
            return new NotificationSource();
        });
        $producer = $this->producer($schedules, $coverage, $this->engine(), $this->outcomes($publisher));

        $producer->tick();
        $this->assertDatabaseMissing('stox_bootstrap_projection_receipts', ['run_id' => $run->id]);
        $this->assertDatabaseMissing('stox_bootstrap_outcome_receipts', ['event_key' => 'run:'.$run->id.':failed']);
        $producer->tick();
        $receipt = DB::table('stox_bootstrap_projection_receipts')->where('run_id', $run->id)->first();
        $this->assertNotNull($receipt->reconciliation_completed_at);
        $this->assertNotNull($receipt->outcomes_completed_at);
        $this->assertDatabaseHas('stox_bootstrap_outcome_receipts', ['event_key' => 'run:'.$run->id.':failed']);
        $producer->tick();
        $this->assertSame(1, DB::table('stox_bootstrap_outcome_receipts')->where('event_key', 'run:'.$run->id.':failed')->count());
    }

    public function test_b_persistence_is_rolled_back_with_cursor_when_reconciliation_throws_after_persisting(): void
    {
        User::factory()->create(['is_admin' => true]);
        $run = $this->managedRun('completed');
        $stock = $this->stock(900);
        FundamentalBootstrapJob::query()->create(['run_id' => $run->id, 'stock_id' => $stock->id, 'status' => 'complete_good']);
        $schedules = $this->emptyScheduleService();
        $realCoverage = app(BootstrapCoverageOperationsService::class);
        $coverage = Mockery::mock($realCoverage)->makePartial();
        $coverage->shouldReceive('reconcileJobs')->once()->andReturnUsing(function ($candidate, array $ids) use ($realCoverage): array {
            $persisted = $realCoverage->reconcileJobs($candidate, $ids);
            throw new RuntimeException('fail after B persistence: '.count($persisted));
        });
        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publishEvent')->once()->andReturn(new NotificationSource());
        $factory = fn ($b) => $this->producer($schedules, $b, $this->engine(), $this->outcomes($publisher));

        $factory($coverage)->tick();
        $this->assertDatabaseMissing('stox_bootstrap_projection_receipts', ['run_id' => $run->id]);
        $this->assertDatabaseMissing('stox_fund_bootstrap_gap_observations', ['run_id' => $run->id]);

        $factory($realCoverage)->tick();
        $cursor = DB::table('stox_bootstrap_projection_receipts')->where('run_id', $run->id)->first();
        $this->assertGreaterThan(0, (int) $cursor->last_job_id);
        $this->assertGreaterThan(0, DB::table('stox_fund_bootstrap_gap_observations')->where('run_id', $run->id)->count());
        $factory($realCoverage)->tick();
        $this->assertNotNull(DB::table('stox_bootstrap_projection_receipts')->where('run_id', $run->id)->value('outcomes_completed_at'));
    }

    public function test_engine_skip_is_reported_as_no_processed_slice_and_legacy_run_is_unchanged(): void
    {
        $legacy = FundamentalBootstrapRun::query()->create(['scope' => 'all', 'trigger' => 'manual', 'status' => 'running']);
        $managed = $this->managedRun('queued');
        $schedules = $this->emptyScheduleService();
        $engine = Mockery::mock(FundamentalBootstrapService::class);
        $engine->shouldReceive('process')->once()->andReturn(['skipped_reason' => 'fundamentals_globally_paused']);
        $coverage = Mockery::mock(BootstrapCoverageOperationsService::class);
        $coverage->shouldNotReceive('reconcileJobs');
        $publisher = Mockery::mock(NotificationPublisher::class);
        $producer = $this->producer($schedules, $coverage, $engine, $this->outcomes($publisher));
        $blocked = $producer->tick();
        $this->assertSame(0, $blocked['processed']);
        $this->assertNull($blocked['engine_slice'], 'A legacy running owner prevents even an attempted managed slice.');
        $this->assertSame('running', $legacy->fresh()->status);
        $legacy->forceFill(['status' => 'completed'])->save();
        $result = $producer->tick();
        $this->assertSame(0, $result['processed']);
        $this->assertSame('fundamentals_globally_paused', $result['engine_slice']['skipped_reason']);
        $this->assertSame('completed', $legacy->fresh()->status);
        $this->assertSame('queued', $managed->fresh()->status);
        $this->assertSame(2, FundamentalBootstrapRun::query()->count());
        $this->assertSame(0, FundamentalBootstrapJob::query()->count());
    }

    public function test_real_schedule_adapter_delivers_overlapping_occurrences_as_queued_managed_runs(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 09:00:00 UTC');
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = Stock::query()->create(['symbol' => 'QUEUE01', 'name' => 'Queue fixture', 'exchange' => 'NSE', 'is_active' => true, 'is_benchmark' => false]);
        $this->app->instance(BootstrapEligibleStockResolver::class, new class implements BootstrapEligibleStockResolver {
            public function eligibleStockIds(array $requestedIds): array
            {
                return Stock::query()->whereIn('id', $requestedIds)->where('is_active', true)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
            }
        });
        $schedules = app(BootstrapScheduleOperationsService::class);
        $input = ['frequency' => 'daily', 'interval' => 1, 'timezone' => 'UTC', 'local_time' => '09:30:00',
            'starts_at_local' => '2026-10-22 09:30:00', 'max_occurrences' => 1];
        foreach (['First overlap', 'Second overlap'] as $name) {
            $schedule = $schedules->createSchedule(['name' => $name, 'configuration' => ['scope' => 'stocks', 'stock_ids' => [$stock->id]], ...$input], $admin->id);
            $schedules->setEnabled($schedule, true, $admin->id);
        }
        CarbonImmutable::setTestNow('2026-10-22 09:30:00 UTC');
        $engine = Mockery::mock(FundamentalBootstrapService::class);
        $engine->shouldReceive('process')->once()->andReturn(['skipped_reason' => 'another_bootstrap_run_is_active']);
        $publisher = Mockery::mock(NotificationPublisher::class);
        $producer = new BootstrapOperationsProducer($schedules, new CanonicalBootstrapScheduleLaunchAdapter(app(BootstrapOperationsService::class)),
            $engine, app(BootstrapCoverageOperationsService::class), $this->outcomes($publisher));

        $result = $producer->tick();

        $this->assertSame(2, $result['materialized']);
        $this->assertSame(2, $result['delivered']);
        $this->assertSame(0, $result['processed']);
        $occurrences = \App\Models\V7\BootstrapScheduleOccurrence::query()->orderBy('id')->get();
        $this->assertCount(2, $occurrences);
        $this->assertSame(['queued', 'queued'], $occurrences->pluck('status')->all());
        $this->assertCount(2, $occurrences->pluck('run_id')->unique());
        $this->assertSame(2, FundamentalBootstrapRun::query()->opsManaged()->where('status', 'queued')->count());
    }

    public function test_invalid_older_occurrences_do_not_starve_a_later_outcome_and_accepted_rows_leave_the_scan(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 08:00:00 UTC');
        $operator = User::factory()->create(['is_admin' => true]);
        $actor = User::factory()->create(['is_admin' => true]);
        $schedules = app(BootstrapScheduleOperationsService::class);
        $schedule = $schedules->createSchedule([
            'name' => 'Fair outcome scan', 'configuration' => ['scope' => 'all'],
            'frequency' => 'daily', 'timezone' => 'UTC', 'local_time' => '09:00:00',
            'starts_at_local' => '2026-10-22 09:00:00',
        ], $actor->id);
        $actor->forceFill(['is_admin' => false])->save();
        $occurrences = [];
        foreach (range(1, 51) as $number) {
            $due = CarbonImmutable::parse('2026-10-22 09:00:00', 'UTC')->addMinutes($number);
            $identity = implode(':', [$schedule->id, $schedule->generation, $due->format('Ymd\\THis.u\\Z')]);
            $occurrences[] = \App\Models\V7\BootstrapScheduleOccurrence::query()->create([
                'schedule_id' => $schedule->id, 'generation' => $schedule->generation, 'schedule_version' => $schedule->version,
                'scheduled_at_utc' => $due, 'scheduled_at_local' => $due, 'timezone' => 'UTC',
                'configuration_snapshot' => $schedule->configuration_snapshot, 'actor_admin_id' => $actor->id,
                'status' => 'cannot_start', 'failed_at' => now(), 'failure_code' => 'actor_not_admin',
                'idempotency_key' => hash('sha256', $number === 51 ? 'bootstrap-schedule:'.$identity : 'invalid-legacy-receipt:'.$number),
            ]);
        }
        $later = $occurrences[50];
        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publishEvent')->once()->withArgs(fn ($recipients, array $event) =>
            $event['context']['occurrence_id'] === $later->id && $event['external_info_delivery'] === false
        )->andReturn(new NotificationSource());
        $factory = fn () => $this->producer($this->emptyScheduleService(), app(BootstrapCoverageOperationsService::class),
            $this->engine(), $this->outcomes($publisher));
        $this->assertSame(0, $factory()->tick()['occurrence_outcomes']);
        $this->assertDatabaseMissing('stox_bootstrap_occurrence_projection_checks', ['occurrence_id' => $later->id]);
        $this->assertSame(1, $factory()->tick()['occurrence_outcomes']);
        $this->assertDatabaseHas('stox_bootstrap_outcome_receipts', ['event_key' => 'occurrence:'.$later->id.':cannot_start']);
        $checks = DB::table('stox_bootstrap_occurrence_projection_checks')->where('occurrence_id', $later->id)->value('scan_count');
        $this->assertSame(0, $factory()->tick()['occurrence_outcomes']);
        $this->assertSame((int) $checks, (int) DB::table('stox_bootstrap_occurrence_projection_checks')->where('occurrence_id', $later->id)->value('scan_count'));
        $this->assertSame(51, \App\Models\V7\BootstrapScheduleOccurrence::query()->where('status', 'cannot_start')->count());
        $this->assertSame(0, FundamentalBootstrapRun::query()->count());
    }

    private function managedRun(string $status): FundamentalBootstrapRun
    {
        return FundamentalBootstrapRun::query()->create([
            'scope' => 'all', 'trigger' => 'manual', 'status' => $status,
            'request_snapshot' => ['managed_version' => FundamentalBootstrapService::OPS_MANAGED_VERSION,
                'origin' => 'admin_fundamentals_bootstrap_operations', 'trigger' => 'manual'],
        ]);
    }

    private function stock(int $number): Stock
    {
        return Stock::query()->create(['symbol' => 'P'.str_pad((string) $number, 5, '0', STR_PAD_LEFT), 'name' => 'Producer '.$number,
            'exchange' => 'NSE', 'is_active' => true]);
    }

    private function emptyScheduleService(): BootstrapScheduleOperationsService
    {
        $schedules = Mockery::mock(BootstrapScheduleOperationsService::class);
        $schedules->shouldReceive('materializeDue')->zeroOrMoreTimes()->andReturn(0);
        $schedules->shouldReceive('deliverPending')->zeroOrMoreTimes()->andReturn(0);
        return $schedules;
    }

    private function engine(): FundamentalBootstrapService
    {
        return Mockery::mock(FundamentalBootstrapService::class);
    }

    private function outcomes(NotificationPublisher $publisher): BootstrapOperationalOutcomeService
    {
        return new BootstrapOperationalOutcomeService(new GenericNotificationPublisher($publisher));
    }

    private function producer(BootstrapScheduleOperationsService $schedules, BootstrapCoverageOperationsService $coverage,
        FundamentalBootstrapService $engine, BootstrapOperationalOutcomeService $outcomes): BootstrapOperationsProducer
    {
        return new BootstrapOperationsProducer($schedules, Mockery::mock(BootstrapScheduleLaunchAdapter::class), $engine, $coverage, $outcomes);
    }
}
