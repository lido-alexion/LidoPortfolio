<?php

namespace Tests\Feature\OPS001;

use App\Models\User;
use App\Services\Fundamentals\BootstrapScheduleOperationsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BootstrapScheduleMaterializerConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private ?int $createdScheduleId = null;
    private ?int $createdAdminId = null;
    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefaultConnection = (string) config('database.default');
        if (DB::connection($this->originalDefaultConnection)->getDriverName() === 'mysql') {
            config(['database.connections.bootstrap_schedule_committed' => config('database.connections.'.$this->originalDefaultConnection)]);
            DB::purge('bootstrap_schedule_committed');
            config(['database.default' => 'bootstrap_schedule_committed']);
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdScheduleId !== null) {
            \App\Models\V7\BootstrapScheduleOccurrence::query()->where('schedule_id', $this->createdScheduleId)->delete();
            \App\Models\V7\BootstrapSchedule::query()->whereKey($this->createdScheduleId)->delete();
        }
        if ($this->createdAdminId !== null) User::query()->whereKey($this->createdAdminId)->delete();
        config(['database.default' => $this->originalDefaultConnection]);
        parent::tearDown();
    }

    public function test_two_mysql_ticks_claim_one_occurrence_under_the_schedule_row_lock(): void
    {
        if (DB::getDriverName() !== 'mysql') $this->markTestSkipped('The schedule materializer concurrency contract requires real MySQL row locks.');

        $admin = User::factory()->create(['is_admin' => true]);
        $this->createdAdminId = (int) $admin->id;
        $start = CarbonImmutable::now('UTC')->addMinutes(3)->startOfMinute();
        $schedule = app(BootstrapScheduleOperationsService::class)->createSchedule([
            'name' => 'Concurrent materializer fixture', 'configuration' => ['scope' => 'all', 'stock_ids' => []],
            'frequency' => 'daily', 'interval' => 1, 'timezone' => 'UTC', 'local_time' => $start->format('H:i:s'),
            'starts_at_local' => $start->format('Y-m-d H:i:s'),
        ], $admin->id);
        $this->createdScheduleId = (int) $schedule->id;
        app(BootstrapScheduleOperationsService::class)->setEnabled($schedule, true, $admin->id);
        $due = CarbonImmutable::parse($schedule->getRawOriginal('next_occurrence_at'), 'UTC')->addSecond();

        $barrier = sys_get_temp_dir().'/stox-sched-claim-'.bin2hex(random_bytes(8));
        if (! mkdir($barrier, 0700, true) && ! is_dir($barrier)) throw new \RuntimeException('Could not create schedule tick rendezvous.');
        $connection = config('database.connections.'.$this->originalDefaultConnection);
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => $this->originalDefaultConnection, 'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => (string) $connection['password'], 'DB_URL' => '',
            'BOOTSTRAP_SCHEDULE_BARRIER' => $barrier, 'BOOTSTRAP_SCHEDULE_DUE_UTC' => $due->format('Y-m-d H:i:s'),
            'BOOTSTRAP_SCHEDULE_ID' => (string) $schedule->id,
        ];
        $workers = [];
        try {
            foreach ([1, 2] as $workerNo) {
                $workers[] = new Process([PHP_BINARY, base_path('tests/Fixtures/bootstrap-schedule-materialize.php'), (string) $workerNo], base_path(), $environment, null, 30);
            }
            foreach ($workers as $worker) $worker->start();
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            }
            self::assertSame(1, (int) \App\Models\V7\BootstrapScheduleOccurrence::query()->where('schedule_id', $schedule->id)->count());
            self::assertSame(1, (int) $schedule->fresh()->occurrences_created);
            self::assertSame(1, array_sum(array_map(fn (Process $worker): int => (int) trim($worker->getOutput()), $workers)));
        } finally {
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop(1);
            foreach (glob($barrier.'/*') ?: [] as $path) @unlink($path);
            @rmdir($barrier);
        }
    }
}
