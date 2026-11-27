<?php

namespace Tests\Feature;

use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashManagementService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TransactionIdempotencyConcurrencyTest extends TestCase
{
    private ?string $ownedDatabase = null;

    private array $originalMysqlConnection = [];

    private string $originalDefaultConnection = '';

    private ?string $ownedBarrierDirectory = null;

    protected function tearDown(): void
    {
        if (config('database.connections.tx_req_test_lock')) {
            DB::disconnect('tx_req_test_lock');
        }
        if ($this->ownedBarrierDirectory !== null
            && preg_match('#^'.preg_quote(sys_get_temp_dir(), '#').'/stox_tx_req_barrier_[a-f0-9-]+$#', $this->ownedBarrierDirectory)) {
            foreach (['ready-1', 'ready-2', 'released'] as $file) {
                $path = $this->ownedBarrierDirectory.'/'.$file;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($this->ownedBarrierDirectory)) {
                rmdir($this->ownedBarrierDirectory);
            }
        }
        if ($this->ownedDatabase !== null) {
            DB::disconnect('mysql');
            config([
                'database.connections.mysql' => $this->originalMysqlConnection,
                'database.default' => $this->originalDefaultConnection,
            ]);
            DB::purge('mysql');
            DB::statement('DROP DATABASE `'.$this->ownedDatabase.'`');
        }
        parent::tearDown();
    }

    public function test_two_simultaneous_mysql_requests_create_one_linked_ledger_unit_and_both_replay(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent same-key acceptance requires MySQL row locks.');
        }
        $this->originalMysqlConnection = config('database.connections.mysql');
        $this->originalDefaultConnection = (string) config('database.default');
        $databaseName = (string) $this->originalMysqlConnection['database'];
        if (! app()->environment('testing') || ! preg_match('/(?:_test|_testing|_ci)$/i', $databaseName)) {
            $this->markTestSkipped('Concurrency fixture requires APP_ENV=testing and an explicitly test-suffixed database name.');
        }
        $ownedDatabase = 'stox_tx_req_race_'.getmypid().'_'.strtolower(Str::random(6)).'_test';
        if (! preg_match('/^stox_tx_req_race_[0-9]+_[a-z0-9]+_test$/', $ownedDatabase)) {
            throw new \LogicException('Generated isolated database name failed its allowlist.');
        }
        DB::statement('CREATE DATABASE `'.$ownedDatabase.'`');
        $this->ownedDatabase = $ownedDatabase;
        $mysql = $this->originalMysqlConnection;
        $mysql['database'] = $ownedDatabase;
        config(['database.connections.mysql' => $mysql, 'database.default' => 'mysql']);
        DB::purge('mysql');
        Artisan::call('migrate:fresh', ['--force' => true]);

        $runId = (string) Str::uuid();
        $connection = DB::connection()->getConfig();
        config(['database.connections.tx_req_test_lock' => $connection]);
        DB::purge('tx_req_test_lock');
        $lockDb = DB::connection('tx_req_test_lock');

        $user = User::query()->create([
            'name' => 'Concurrent Request User',
            'email' => 'tx-concurrency-'.Str::random(8).'@example.com',
            'password' => 'synthetic-test-password',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, 50_000, 'isolated concurrency fixture', $user);
        $stock = Stock::query()->create([
            'symbol' => 'TXRACE'.strtoupper(Str::random(4)), 'exchange' => 'NSE', 'name' => 'Concurrent Fixture',
            'is_active' => true, 'is_benchmark' => false,
        ]);
        $recommendation = TradingRecommendation::query()->create([
            'profile_id' => $profile->id, 'security_id' => $stock->id,
            'recommendation_type' => 'OPEN_POSITION', 'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
            'priority' => 1, 'strategy_score' => 80, 'confidence' => 0.8, 'risk_level' => 'medium',
            'suggested_allocation_amount' => 500, 'reference_price' => 100,
            'execution_plan' => ['suggested_quantity' => 2, 'suggested_investment_amount' => 200, 'side' => 'buy'],
            'approved_at' => now(), 'generated_at' => now(),
            'reservation_status' => TradingRecommendation::RESERVATION_NONE, 'reserved_amount' => 0,
        ]);
        $token = $user->createToken('isolated-concurrent-test')->plainTextToken;
        $payload = [
            'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 2, 'price' => 100,
            'fees' => 1, 'transaction_date' => now()->toDateString(), 'recommendation_id' => $recommendation->id,
        ];
        $barrierDirectory = sys_get_temp_dir().'/stox_tx_req_barrier_'.$runId;
        if (! mkdir($barrierDirectory, 0700)) {
            throw new \RuntimeException('Unable to create the owned concurrency barrier directory.');
        }
        $this->ownedBarrierDirectory = $barrierDirectory;

        $processes = [];
        $lockDb->beginTransaction();
        try {
            $lockDb->table('portfolio_profiles')->where('id', $profile->id)->lockForUpdate()->firstOrFail();
            for ($worker = 1; $worker <= 2; $worker++) {
                $process = new Process([
                    PHP_BINARY, base_path('tests/Fixtures/transaction-idempotency-http.php'),
                    $runId, (string) $worker, $barrierDirectory, (string) $user->id, (string) $profile->id,
                    $token, 'concurrent-request-key-0001', base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                ], base_path(), $this->databaseEnvironment(), null, 45);
                $process->start();
                $processes[] = $process;
            }

            $readyDeadline = microtime(true) + 20;
            while (! is_file($barrierDirectory.'/ready-1') || ! is_file($barrierDirectory.'/ready-2')) {
                if (microtime(true) > $readyDeadline) {
                    throw new \RuntimeException('Concurrent HTTP workers did not reach the isolated barrier.');
                }
                usleep(20_000);
            }
            touch($barrierDirectory.'/released');

            $lockWaitDeadline = microtime(true) + 20;
            while ($this->mysqlRowLockWaitCount() < 2) {
                if (microtime(true) > $lockWaitDeadline) {
                    $details = [
                        'wait_count' => $this->mysqlRowLockWaitCount(),
                        'transactions' => DB::select('select trx_id, trx_state, trx_mysql_thread_id, trx_query from information_schema.innodb_trx'),
                        'processes' => DB::select('show full processlist'),
                        'barrier_ready' => [is_file($barrierDirectory.'/ready-1'), is_file($barrierDirectory.'/ready-2')],
                        'barrier_released' => is_file($barrierDirectory.'/released'),
                        'workers' => array_map(fn (Process $process) => [
                            'running' => $process->isRunning(),
                            'stdout' => $process->getOutput(),
                            'stderr' => $process->getErrorOutput(),
                        ], $processes),
                    ];
                    throw new \RuntimeException('Both HTTP requests did not concurrently block on the locked portfolio row: '.json_encode($details));
                }
                usleep(20_000);
            }
        } finally {
            if ($lockDb->transactionLevel() > 0) {
                $lockDb->commit();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->wait();
                    if ($process->isRunning()) {
                        $process->stop(1, SIGTERM);
                    }
                }
            }
        }

        $responses = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $responses[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        }
        self::assertEqualsCanonicalizing([200, 201], array_column($responses, 'status'));
        self::assertSame($responses[0]['body']['data']['id'], $responses[1]['body']['data']['id']);
        self::assertSame($responses[0]['body']['data'], $responses[1]['body']['data']);
        self::assertSame(['recommendation_id' => $recommendation->id, 'recommendation_status' => 'executed'], $responses[0]['body']['tos']);
        self::assertSame($responses[0]['body']['tos'], $responses[1]['body']['tos']);
        $transaction = Transaction::query()->where('profile_id', $profile->id)->where('recommendation_id', $recommendation->id)->firstOrFail();
        self::assertSame(1, Transaction::query()->where('profile_id', $profile->id)->where('recommendation_id', $recommendation->id)->count());
        self::assertSame(1, DB::table('portfolio_transaction_request_receipts')->where('profile_id', $profile->id)->where('actor_user_id', $user->id)->count());
        self::assertSame(1, DB::table('portfolio_cash_ledger_entries')->where('transaction_id', $transaction->id)->where('entry_type', 'buy')->count());
        $cashMovement = (float) DB::table('portfolio_cash_ledger_entries')->where('transaction_id', $transaction->id)->sum('amount');
        self::assertEqualsWithDelta(-((float) $transaction->quantity * (float) $transaction->price + (float) $transaction->fees), $cashMovement, 0.0001);
        self::assertSame(1, DB::table('portfolio_jobs')->where('queue', 'default')->where('payload', 'like', '%BackfillHistoricalDataJob%')->count());
        self::assertSame(TradingRecommendation::STATUS_EXECUTED, $recommendation->fresh()->status);

    }

    private function mysqlRowLockWaitCount(): int
    {
        $version = (string) DB::selectOne('select version() as version')->version;
        $sql = str_contains(strtolower($version), 'mariadb')
            ? 'select count(*) as waiting from information_schema.innodb_lock_waits'
            : 'select count(*) as waiting from performance_schema.data_lock_waits';

        return (int) DB::selectOne($sql)->waiting;
    }

    private function databaseEnvironment(): array
    {
        $connection = config('database.connections.mysql');

        return [
            'APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) ($connection['host'] ?? 'localhost'),
            'DB_PORT' => (string) ($connection['port'] ?? '3306'),
            'DB_SOCKET' => (string) ($connection['unix_socket'] ?? ''),
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
            'DB_URL' => '', 'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'API_FAILURE_REPORTING_ENABLED' => 'false',
        ];
    }
}
