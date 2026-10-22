<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\FundamentalFact;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use App\Models\V7\FundamentalSetting;
use App\Services\Fundamentals\FundamentalBootstrapService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FundamentalBootstrapQueueConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['stox_fundamental_bootstrap_jobs', 'stox_fundamental_bootstrap_runs', 'stox_fund_bootstrap_control'];

    private ?string $sharedCacheDirectory = null;

    private ?string $sharedCachePrefix = null;

    /** @var list<int> */
    private array $ownedStockIds = [];

    /** @var list<int> */
    private array $ownedUserIds = [];

    /** @var list<int> */
    private array $ownedRunIds = [];

    private ?int $ownedSettingId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $setting = FundamentalSetting::query()->create([
            'quarterly_freshness_months' => 5, 'annual_freshness_months' => 15,
            'request_delay_ms' => 0, 'max_attempts' => 3, 'provider' => 'yahoo', 'paused' => false,
        ]);
        $this->ownedSettingId = (int) $setting->id;
        if (! DB::table('stox_fund_bootstrap_control')->where('id', 1)->exists()) {
            DB::table('stox_fund_bootstrap_control')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        try {
            // Child PHP processes commit independently of the test connection;
            // remove every fixture row they and this test created so later
            // RefreshDatabase tests cannot inherit stocks or unfinished runs.
            $connection = DB::connection();
            if (in_array($connection->getDriverName(), ['mysql', 'sqlite'], true)) {
                $stockIds = array_values(array_unique($this->ownedStockIds));
                $runIds = array_values(array_unique(array_merge(
                    $this->ownedRunIds,
                    $this->ownedUserIds === [] ? [] : FundamentalBootstrapRun::query()
                        ->whereIn('created_by_user_id', $this->ownedUserIds)->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    $stockIds === [] ? [] : FundamentalBootstrapJob::query()
                        ->whereIn('stock_id', $stockIds)->pluck('run_id')->map(fn ($id) => (int) $id)->all(),
                )));

                if ($runIds !== []) {
                    DB::table('stox_fund_bootstrap_control')->whereIn('active_run_id', $runIds)->update(['active_run_id' => null, 'updated_at' => now()]);
                    FundamentalBootstrapJob::query()->whereIn('run_id', $runIds)->delete();
                }
                if ($stockIds !== []) {
                    DB::table('stox_fundamental_provider_checks')->whereIn('stock_id', $stockIds)->delete();
                    FundamentalFact::query()->whereIn('stock_id', $stockIds)->delete();
                    FundamentalBootstrapJob::query()->whereIn('stock_id', $stockIds)->delete();
                }
                if ($runIds !== []) FundamentalBootstrapRun::query()->whereIn('id', $runIds)->delete();
                if ($this->ownedSettingId !== null) FundamentalSetting::query()->whereKey($this->ownedSettingId)->delete();
                if ($stockIds !== []) Stock::query()->whereIn('id', $stockIds)->delete();
                if ($this->ownedUserIds !== []) User::query()->whereIn('id', $this->ownedUserIds)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_mysql_processes_respect_fifo_slot_and_recover_a_crashed_claim(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The bootstrap concurrency acceptance gate requires real MySQL row locks.');
        }

        $stock = Stock::query()->create(['symbol' => 'BOOTSTRAPLOCK', 'exchange' => 'NSE', 'name' => 'Concurrency fixture', 'is_active' => true, 'is_benchmark' => false]);
        $this->ownedStockIds[] = (int) $stock->id;
        $actor = User::factory()->create(['is_admin' => true]);
        $this->ownedUserIds[] = (int) $actor->id;
        $this->processesConcurrently($actor->id, $stock->id);
        $runs = FundamentalBootstrapRun::query()->orderBy('id')->get();
        $this->ownedRunIds = array_merge($this->ownedRunIds, $runs->modelKeys());
        $this->assertCount(2, $runs);
        $this->assertSame('queued', $runs[0]->status);
        $this->assertSame('queued', $runs[1]->status);
        $stock->forceFill(['is_benchmark' => true])->save();
        Artisan::call('stox:fundamentals-bootstrap-work-once', ['--batch' => 1]);
        $this->assertSame('completed', $runs[0]->fresh()->status);

        // Simulate process death after claim. A fresh worker must safely reclaim
        // the stale attempt, finish it, and release the singleton slot.
        $runs[1]->forceFill(['status' => 'running'])->save();
        $job = FundamentalBootstrapJob::query()->where('run_id', $runs[1]->id)->firstOrFail();
        $job->forceFill(['status' => FundamentalBootstrapJob::STATUS_RUNNING, 'attempts' => 1])->save();
        app(FundamentalBootstrapService::class)->process($runs[1]->fresh(), 1);
        $this->assertSame('completed', $runs[1]->fresh()->status);
        $this->assertSame(2, (int) $job->fresh()->attempts);
        $this->assertNull(DB::table('stox_fund_bootstrap_control')->where('id', 1)->value('active_run_id'));
    }

    public function test_expired_two_process_attempts_cannot_write_after_cancel(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The bootstrap process-fencing acceptance gate requires real MySQL row locks.');
        }

        $stock = Stock::query()->create(['symbol' => 'BOOTSTRAPFENCE', 'exchange' => 'NSE', 'name' => 'Fencing fixture', 'is_active' => true, 'is_benchmark' => false]);
        $this->ownedStockIds[] = (int) $stock->id;
        $actor = User::factory()->create(['is_admin' => true]);
        $this->ownedUserIds[] = (int) $actor->id;
        $run = app(\App\Services\Fundamentals\BootstrapOperationsService::class)->launch(
            ['scope' => 'stocks', 'stock_ids' => [$stock->id]], $actor->id, 'fence test', 'bootstrap-fence-key-001',
        )->run;
        $this->ownedRunIds[] = (int) $run->id;
        $temporaryKey = bin2hex(random_bytes(8));
        $cacheDirectory = $this->configureSharedFileCache($temporaryKey);
        $oldReadyPath = sys_get_temp_dir().'/bootstrap-old-'.$temporaryKey.'.ready';
        $oldReleasePath = sys_get_temp_dir().'/bootstrap-old-'.$temporaryKey.'.release';
        $newReadyPath = sys_get_temp_dir().'/bootstrap-new-'.$temporaryKey.'.ready';
        $newReleasePath = sys_get_temp_dir().'/bootstrap-new-'.$temporaryKey.'.release';
        $probePath = sys_get_temp_dir().'/bootstrap-lock-probe-'.$temporaryKey.'.result';
        $oldProcess = $newProcess = $cancelProcess = null;
        try {
            $oldProcess = $this->workerProcess('worker-old', $run->id, $oldReadyPath, $oldReleasePath);
            $oldProcess->start();
            $this->waitForBarrier($oldReadyPath, $oldProcess);
            $parentCache = $this->sharedCacheDiagnostics();
            self::assertSame('file', $parentCache['default']);
            self::assertSame(\Illuminate\Cache\FileStore::class, $parentCache['default_store_class']);
            self::assertSame($cacheDirectory, $parentCache['file_directory']);
            self::assertSame($cacheDirectory, $parentCache['lock_directory']);
            self::assertTrue($parentCache['lock_file_exists'], 'The blocked worker must hold the isolated shared process fence.');
            self::assertGreaterThan(time(), $parentCache['lock_expires_at']);

            $probe = $this->workerProcess('competing-worker', $run->id, $probePath, $oldReleasePath);
            $probe->run();
            self::assertTrue($probe->isSuccessful(), $probe->getErrorOutput().$probe->getOutput());
            self::assertSame('another_bootstrap_slice_is_in_progress', trim((string) file_get_contents($probePath)), 'A competing execution process must observe the shared process fence before lease expiry.');
            $probeResult = json_decode((string) file_get_contents($probePath.'.result'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($parentCache['lock_path'], $probeResult['cache']['lock_path']);
            self::assertSame($parentCache['lock_expires_at'], $probeResult['cache']['lock_expires_at']);
            self::assertSame($parentCache['default_store_class'], $probeResult['cache']['default_store_class']);
            self::assertSame($parentCache['file_store_class'], $probeResult['cache']['file_store_class']);
            self::assertSame($cacheDirectory, $probeResult['cache']['file_directory']);
            self::assertSame($cacheDirectory, $probeResult['cache']['lock_directory']);
            self::assertTrue($probeResult['cache']['lock_file_exists']);
            self::assertSame(1, (int) $run->jobs()->sole()->attempts, 'The fenced competitor must not claim the running job.');

            // Simulate process lease expiry while attempt 1 is blocked in provider
            // I/O. Attempt 2 claims the same job and also blocks outside a transaction.
            $this->expireSharedProcessFence();
            $newProcess = $this->workerProcess('worker-new', $run->id, $newReadyPath, $newReleasePath);
            $newProcess->start();
            $this->waitForBarrier($newReadyPath, $newProcess);
            self::assertSame(2, (int) $run->jobs()->sole()->attempts);

            $this->expireSharedProcessFence();
            $cancelProcess = $this->workerProcess('cancel', $run->id, $oldReadyPath, $oldReleasePath);
            $cancelProcess->run();
            self::assertTrue($cancelProcess->isSuccessful(), $cancelProcess->getErrorOutput().$cancelProcess->getOutput());
            self::assertSame('cancelled', $run->fresh()->status);

            file_put_contents($oldReleasePath, 'release', LOCK_EX);
            file_put_contents($newReleasePath, 'release', LOCK_EX);
            $oldProcess->wait();
            self::assertTrue($oldProcess->isSuccessful(), $oldProcess->getErrorOutput().$oldProcess->getOutput());
            $newProcess->wait();
            self::assertTrue($newProcess->isSuccessful(), $newProcess->getErrorOutput().$newProcess->getOutput());

            $facts = FundamentalFact::query()->where('stock_id', $stock->id)->where('fact_key', 'revenue')->get();
            self::assertCount(0, $facts);
            self::assertSame(FundamentalBootstrapJob::STATUS_CANCELLED, $run->jobs()->sole()->status);
        } finally {
            if ($oldProcess?->isRunning()) $oldProcess->stop(1);
            if ($newProcess?->isRunning()) $newProcess->stop(1);
            if ($cancelProcess?->isRunning()) $cancelProcess->stop(1);
            @unlink($oldReadyPath);
            @unlink($oldReleasePath);
            @unlink($newReadyPath);
            @unlink($newReleasePath);
            @unlink($oldReadyPath.'.result');
            @unlink($newReadyPath.'.result');
            @unlink($probePath);
            @unlink($probePath.'.result');
            Cache::lock(FundamentalBootstrapService::PROCESS_LOCK_KEY, 900)->forceRelease();
            $this->removeOwnedCacheDirectory($cacheDirectory);
        }
    }

    public function test_old_provider_return_cannot_write_after_newer_attempt_completes(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The bootstrap process-fencing acceptance gate requires real MySQL row locks.');
        }

        $stock = Stock::query()->create(['symbol' => 'BOOTSTRAPATTEMPT', 'exchange' => 'NSE', 'name' => 'Attempt fixture', 'is_active' => true, 'is_benchmark' => false]);
        $this->ownedStockIds[] = (int) $stock->id;
        $actor = User::factory()->create(['is_admin' => true]);
        $this->ownedUserIds[] = (int) $actor->id;
        $run = app(\App\Services\Fundamentals\BootstrapOperationsService::class)->launch(
            ['scope' => 'stocks', 'stock_ids' => [$stock->id]], $actor->id, 'attempt test', 'bootstrap-attempt-key-001',
        )->run;
        $this->ownedRunIds[] = (int) $run->id;
        $temporaryKey = bin2hex(random_bytes(8));
        $cacheDirectory = $this->configureSharedFileCache($temporaryKey);
        $oldReadyPath = sys_get_temp_dir().'/bootstrap-attempt-old-'.$temporaryKey.'.ready';
        $oldReleasePath = sys_get_temp_dir().'/bootstrap-attempt-old-'.$temporaryKey.'.release';
        $newReadyPath = sys_get_temp_dir().'/bootstrap-attempt-new-'.$temporaryKey.'.ready';
        $newReleasePath = sys_get_temp_dir().'/bootstrap-attempt-new-'.$temporaryKey.'.release';
        $oldProcess = $newProcess = null;
        try {
            $oldProcess = $this->workerProcess('worker-old', $run->id, $oldReadyPath, $oldReleasePath);
            $oldProcess->start();
            $this->waitForBarrier($oldReadyPath, $oldProcess);
            $this->expireSharedProcessFence();

            $newProcess = $this->workerProcess('worker-new', $run->id, $newReadyPath, $newReleasePath);
            $newProcess->start();
            $this->waitForBarrier($newReadyPath, $newProcess);
            self::assertSame(2, (int) $run->jobs()->sole()->attempts);

            file_put_contents($oldReleasePath, 'release', LOCK_EX);
            $oldProcess->wait();
            self::assertTrue($oldProcess->isSuccessful(), $oldProcess->getErrorOutput().$oldProcess->getOutput());
            self::assertSame(0, FundamentalFact::query()->where('stock_id', $stock->id)->where('fact_key', 'revenue')->count(), 'Attempt 1 must be fenced even after its callback returns.');

            file_put_contents($newReleasePath, 'release', LOCK_EX);
            $newProcess->wait();
            self::assertTrue($newProcess->isSuccessful(), $newProcess->getErrorOutput().$newProcess->getOutput());
            self::assertEquals(222.0, (float) FundamentalFact::query()->where('stock_id', $stock->id)->where('fact_key', 'revenue')->where('is_current', true)->value('value'));
            self::assertSame('completed', $run->fresh()->status);
        } finally {
            if ($oldProcess?->isRunning()) $oldProcess->stop(1);
            if ($newProcess?->isRunning()) $newProcess->stop(1);
            @unlink($oldReadyPath);
            @unlink($oldReleasePath);
            @unlink($newReadyPath);
            @unlink($newReleasePath);
            @unlink($oldReadyPath.'.result');
            @unlink($newReadyPath.'.result');
            $this->expireSharedProcessFence();
            $this->removeOwnedCacheDirectory($cacheDirectory);
        }
    }

    private function processesConcurrently(int $actorId, int $stockId): void
    {
        $connection = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => (string) $connection['password'], 'DB_URL' => '',
        ];
        $processes = [];
        foreach ([1, 2] as $requestNo) {
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/fundamental-bootstrap-concurrency.php'), 'launch', (string) $actorId, (string) $stockId, (string) $requestNo], base_path(), $environment, null, 45);
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        }
    }

    private function workerProcess(string $role, int $runId, string $readyPath, string $releasePath): Process
    {
        $connection = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => (string) $connection['password'], 'DB_URL' => '',
            'CACHE_STORE' => 'file',
            'CACHE_PREFIX' => $this->sharedCachePrefix,
            'BOOTSTRAP_READY_PATH' => $readyPath, 'BOOTSTRAP_RELEASE_PATH' => $releasePath,
            'BOOTSTRAP_RESULT_PATH' => $readyPath.'.result',
            'BOOTSTRAP_CACHE_DIRECTORY' => $this->sharedCacheDirectory,
        ];
        return new Process([PHP_BINARY, base_path('tests/Fixtures/fundamental-bootstrap-concurrency.php'), $role, (string) $runId], base_path(), $environment, null, 45);
    }

    private function configureSharedFileCache(string $key): string
    {
        $directory = sys_get_temp_dir().'/stox-bootstrap-cache-'.$key;
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Could not create isolated bootstrap cache directory.');
        }
        $this->sharedCacheDirectory = $directory;
        $this->sharedCachePrefix = 'stox-bootstrap-test-'.$key;
        config(['cache.default' => 'file', 'cache.prefix' => $this->sharedCachePrefix, 'cache.stores.file.path' => $directory, 'cache.stores.file.lock_path' => $directory]);
        Cache::setDefaultDriver('file');
        Cache::purge('file');
        return $directory;
    }

    /** @return array<string, mixed> */
    private function sharedCacheDiagnostics(): array
    {
        $repository = Cache::store('file');
        $store = $repository->getStore();
        $reflection = new \ReflectionProperty($store, 'lockDirectory');
        $lockDirectory = $reflection->getValue($store) ?: $store->getDirectory();
        $hash = sha1('file-store-lock:'.FundamentalBootstrapService::PROCESS_LOCK_KEY);
        $lockPath = rtrim($lockDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.substr($hash, 0, 2).DIRECTORY_SEPARATOR.substr($hash, 2, 2).DIRECTORY_SEPARATOR.$hash;
        $contents = is_file($lockPath) ? file_get_contents($lockPath) : false;

        return [
            'default' => app('cache')->getDefaultDriver(),
            'default_store_class' => get_class(Cache::store()->getStore()),
            'file_store_class' => get_class($store),
            'file_directory' => $store->getDirectory(),
            'lock_directory' => $lockDirectory,
            'lock_file_exists' => is_file($lockPath),
            'lock_expires_at' => is_string($contents) && strlen($contents) >= 10 && ctype_digit(substr($contents, 0, 10))
                ? (int) substr($contents, 0, 10)
                : null,
            'lock_path' => $lockPath,
        ];
    }

    private function expireSharedProcessFence(): void
    {
        Cache::store('file')->lock(FundamentalBootstrapService::PROCESS_LOCK_KEY, 900)->forceRelease();
        self::assertFalse($this->sharedCacheDiagnostics()['lock_file_exists'], 'The shared isolated fence must be removed before the next process attempt.');
    }

    private function waitForBarrier(string $path, Process $process): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path) && microtime(true) < $deadline && $process->isRunning()) usleep(20000);
        if (! is_file($path)) {
            $resultPath = $path.'.result';
            self::fail(sprintf(
                "Worker did not reach barrier %s; running=%s exit=%s command=%s\nRESULT:\n%s\nSTDOUT:\n%s\nSTDERR:\n%s",
                $path,
                $process->isRunning() ? 'yes' : 'no',
                var_export($process->getExitCode(), true),
                $process->getCommandLine(),
                is_file($resultPath) ? (string) file_get_contents($resultPath) : '(no result file)',
                $process->getOutput(),
                $process->getErrorOutput(),
            ));
        }
    }

    private function removeOwnedCacheDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            $paths = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($paths as $path) {
                $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
            }
        }
        @rmdir($directory);
        $this->sharedCacheDirectory = null;
        $this->sharedCachePrefix = null;
    }
}
