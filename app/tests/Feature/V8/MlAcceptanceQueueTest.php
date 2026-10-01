<?php

namespace Tests\Feature\V8;

use App\Jobs\MlAcceptanceJob;
use App\Jobs\MlRetrainJob;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlAcceptanceCampaignService;
use App\Services\ML\MlAcceptanceReportService;
use App\Services\ML\MlAcceptanceRuntime;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MlAcceptanceQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'database']);
    }

    public function test_acceptance_jobs_are_isolated_from_default_database_worker(): void
    {
        config(['queue.default' => 'database']);
        foreach (['source', 'backfill', 'campaign'] as $operation) {
            Queue::connection(MlAcceptanceRuntime::CONNECTION)->push(new MlAcceptanceJob($operation, '1'));
        }
        $this->assertSame(3, DB::table('portfolio_jobs')->where('queue', 'ml-acceptance')->count());
        $this->assertNull(Queue::connection('database')->pop('default'));
        $this->assertNull(Queue::connection('database')->pop('notifications'));
        $job = Queue::connection('ml-acceptance')->pop('ml-acceptance');
        $payload = unserialize($job->payload()['data']['command']);
        $this->assertSame('ml-acceptance', $payload->connection);
        $this->assertSame('ml-acceptance', $payload->queue);
        $this->assertSame(14400, $payload->timeout);
        $runtime = app(MlAcceptanceRuntime::class);
        $this->assertTrue($runtime->validWorkerEvidence($runtime->workerEvidence($job)));
        $this->assertNull($runtime->workerEvidence(null));
        $this->assertFalse($runtime->validWorkerEvidence(['observed_at' => now()->toIso8601String()]));
        $evidence = $runtime->workerEvidence($job);
        $evidence['observed_at'] = now()->subDays(31)->toIso8601String();
        $this->assertFalse($runtime->validWorkerEvidence($evidence));
        $evidence = $runtime->workerEvidence($job);
        foreach (['connection' => 'database', 'queue' => 'default',
            'observed_at' => now()->addMinute()->toIso8601String(), 'identity' => []] as $key => $value) {
            $this->assertFalse($runtime->validWorkerEvidence(array_replace($evidence, [$key => $value])));
        }
        $wrongReservation = \Mockery::mock(Job::class);
        $wrongReservation->shouldReceive('getJobId')->andReturn('1');
        $wrongReservation->shouldReceive('getConnectionName')->andReturn('database');
        $this->assertNull($runtime->workerEvidence($wrongReservation));
    }

    public function test_only_reserved_preflight_records_worker_evidence_and_unknown_remains_blocking(): void
    {
        Bus::fake();
        $service = app(MlAcceptanceCampaignService::class);
        $campaign = $service->create('2026-09-30', 1);
        $service->step($campaign->id);
        $this->assertNull($campaign->refresh()->horizons['1m']['worker_observed_at']);
        $job = new MlAcceptanceJob('campaign', $campaign->id);
        Queue::connection('ml-acceptance')->push($job);
        $reserved = Queue::connection('ml-acceptance')->pop('ml-acceptance');
        $job->setJob($reserved);
        $job->handle();
        $evidence = $campaign->refresh()->horizons['3m']['worker_evidence'];
        $this->assertTrue(app(MlAcceptanceRuntime::class)->validWorkerEvidence($evidence));
        $report = app(MlAcceptanceReportService::class)->report();
        $this->assertNull($report['runtime']['worker_evidence']['1m']);
        $this->assertNotNull($report['runtime']['worker_evidence']['3m']);
        $this->assertNull($report['runtime']['python_evidence']['3m']);
        $this->assertFalse($report['readiness']['ready']);
        $campaign->forceFill(['status' => 'qualified', 'qualified_at' => now()])->save();
        $this->assertFalse($service->readiness()['ready'], 'A status alone cannot replace missing worker/Python evidence.');
    }

    public function test_linked_training_routes_to_dedicated_worker_and_regular_training_is_unchanged(): void
    {
        $run = MlTrainingRun::query()->create(['horizon' => '1m', 'status' => 'queued',
            'configuration' => ['acceptance' => ['campaign_id' => 'example']]]);
        $job = new MlRetrainJob('1m', trainingRunId: $run->id);
        $this->assertSame('ml-acceptance', $job->connection);
        $this->assertSame('ml-acceptance', $job->queue);
        $this->assertSame(14400, $job->timeout);
        $regular = new MlRetrainJob('1m');
        $this->assertNull($regular->connection);
        $this->assertNull($regular->queue);
        $this->assertNull($regular->timeout);
    }

    public function test_readiness_rejects_visibility_boundary_unsupported_drivers_and_local_locks(): void
    {
        $runtime = app(MlAcceptanceRuntime::class);
        $this->assertTrue($runtime->queueReady());
        $this->assertSame(90, config('queue.connections.database.retry_after'));
        foreach ([0, 90, 14399, 14400, 'forever', null] as $seconds) {
            config(['queue.connections.ml-acceptance.retry_after' => $seconds]);
            $this->assertFalse($runtime->queueReady());
        }
        config(['queue.connections.ml-acceptance.retry_after' => 14401]);
        $this->assertTrue($runtime->queueReady());
        foreach (['sync', 'deferred', 'failover', 'sqs', null] as $driver) {
            config(['queue.connections.ml-acceptance.driver' => $driver]);
            $this->assertFalse($runtime->queueReady());
        }
        config(['queue.connections.ml-acceptance.driver' => 'redis']);
        $this->assertTrue($runtime->queueReady());
        config(['cache.stores.database.driver' => 'array']);
        $this->assertFalse($runtime->queueReady(), 'Store names do not prove distributed locks, even in testing.');
        $this->expectException(ValidationException::class);
        $runtime->assertQueue();
    }

    public function test_shared_queue_or_default_connection_cannot_be_used_for_acceptance(): void
    {
        $runtime = app(MlAcceptanceRuntime::class);
        foreach (['default', 'notifications', 'notifications,ml-acceptance', null] as $queue) {
            config(['queue.connections.ml-acceptance.queue' => $queue]);
            $this->assertFalse($runtime->queueReady());
        }
        config(['queue.connections.ml-acceptance.queue' => 'ml-acceptance', 'queue.default' => 'ml-acceptance']);
        $this->assertFalse($runtime->queueReady());
    }

    public function test_service_timeout_and_visibility_allow_graceful_shutdown(): void
    {
        $unit = file_get_contents(base_path('../deploy/systemd/stoxla-ml-acceptance.service'));
        $this->assertStringContainsString('queue:work '.MlAcceptanceRuntime::CONNECTION.' --queue='.MlAcceptanceRuntime::QUEUE, $unit);
        $this->assertStringContainsString('--timeout='.MlAcceptanceRuntime::TIMEOUT, $unit);
        preg_match('/TimeoutStopSec=(\d+)/', $unit, $matches);
        $this->assertGreaterThan(MlAcceptanceRuntime::TIMEOUT, (int) $matches[1]);
        $this->assertGreaterThan((int) $matches[1], config('queue.connections.ml-acceptance.retry_after'));
        $this->assertGreaterThan(MlAcceptanceRuntime::TIMEOUT, MlAcceptanceRuntime::LOCK_SECONDS);
        $this->assertLessThan(config('queue.connections.ml-acceptance.retry_after'), MlAcceptanceRuntime::LOCK_SECONDS);
        $this->assertStringContainsString('queue:work --queue=notifications,default --sleep=3 --tries=3 --timeout=120',
            file_get_contents(base_path('../deploy/systemd/stoxla-queue.service')));
    }
}
