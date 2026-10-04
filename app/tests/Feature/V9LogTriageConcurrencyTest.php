<?php

namespace Tests\Feature;

use App\Models\LogErrorTriage;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class V9LogTriageConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['stox_log_error_triages', 'stox_log_triage_decisions', 'portfolio_jobs', 'cache_locks'];

    protected function tearDown(): void
    {
        foreach ($this->tablesToTruncate as $table) DB::table($table)->delete();
        parent::tearDown();
    }

    public function test_concurrent_mysql_observers_and_workers_converge(): void
    {
        if (DB::getDriverName() !== 'mysql') $this->markTestSkipped('MySQL acceptance gate exercises real concurrent row locks.');
        $this->processes('observe');
        $row = LogErrorTriage::sole();
        self::assertSame(40, $row->occurrence_count);
        self::assertSame(1, DB::table('portfolio_jobs')->where('queue','log-triage')->count());
        $row->update(['next_attempt_at' => now()->subSecond()]);
        $this->processes('worker', $row->id);
        self::assertSame(1, DB::table('stox_log_triage_decisions')->count());
        self::assertSame('classified', $row->fresh()->status);
        self::assertSame(40, $row->fresh()->occurrence_count);
    }

    public function test_outer_transaction_commit_survives_deferred_queue_failure(): void
    {
        config(['log_error_triage.enabled' => true, 'log_error_triage.environments' => ['testing']]);
        $dispatcher = \Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue is unavailable'));
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
        DB::transaction(function () {
            DB::table('cache')->insert(['key' => 'ops003-original-operation', 'value' => 'preserved', 'expiration' => time()+60]);
            app(\App\Services\Operations\LogErrorTriageService::class)->observe(new \RuntimeException('Null original failure'));
        });
        self::assertSame('preserved', DB::table('cache')->where('key','ops003-original-operation')->value('value'));
        self::assertSame('queue_failed', LogErrorTriage::sole()->failure_reason);
        DB::table('cache')->where('key','ops003-original-operation')->delete();
    }

    private function processes(string $mode, int $id = 0): void
    {
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => (string) $connection['password'], 'DB_URL' => ''];
        $processes = [];
        for ($i=0; $i<4; $i++) {
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/log-triage-concurrency.php'), $mode, (string) $id], base_path(), $environment, null, 30);
            $process->start(); $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait(); self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        }
    }
}
