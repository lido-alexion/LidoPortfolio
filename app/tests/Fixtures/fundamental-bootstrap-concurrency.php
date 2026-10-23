<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.env' => 'testing']);
if (($cacheDirectory = getenv('BOOTSTRAP_CACHE_DIRECTORY')) !== false && $cacheDirectory !== '') {
    config([
        'cache.default' => 'file',
        'cache.stores.file.path' => $cacheDirectory,
        'cache.stores.file.lock_path' => $cacheDirectory,
    ]);
    // The application bootstrap may already have resolved the file store
    // against its normal storage directory. Drop that repository only after
    // overriding its configuration so both child and parent use this test's
    // isolated lock directory.
    app('cache')->purge('file');
    app('cache')->setDefaultDriver('file');
}

$cacheDiagnostics = static function (): array {
    $manager = app('cache');
    $defaultRepository = $manager->store();
    $fileStore = $manager->store('file')->getStore();
    $reflection = new ReflectionProperty($fileStore, 'lockDirectory');
    $lockDirectory = $reflection->getValue($fileStore) ?: $fileStore->getDirectory();
    $key = 'file-store-lock:'.\App\Services\Fundamentals\FundamentalBootstrapService::PROCESS_LOCK_KEY;
    $hash = sha1($key);
    $lockPath = rtrim($lockDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.substr($hash, 0, 2).DIRECTORY_SEPARATOR.substr($hash, 2, 2).DIRECTORY_SEPARATOR.$hash;
    $contents = is_file($lockPath) ? file_get_contents($lockPath) : false;

    return [
        'default' => $manager->getDefaultDriver(),
        'default_store_class' => get_class($defaultRepository->getStore()),
        'file_store_class' => get_class($fileStore),
        'file_directory' => $fileStore->getDirectory(),
        'lock_directory' => $lockDirectory,
        'lock_file_exists' => is_file($lockPath),
        'lock_expires_at' => is_string($contents) && strlen($contents) >= 10 && ctype_digit(substr($contents, 0, 10))
            ? (int) substr($contents, 0, 10)
            : null,
        'lock_path' => $lockPath,
    ];
};
if (($argv[1] ?? '') === 'launch') {
    app(\App\Services\Fundamentals\BootstrapOperationsService::class)->launch(
        ['scope' => 'stocks', 'stock_ids' => [(int) $argv[3]]],
        (int) $argv[2], 'MySQL concurrency fixture', 'bootstrap-race-'.$argv[4],
    );
} elseif (($argv[1] ?? '') === 'cancel') {
    app(\App\Services\Fundamentals\FundamentalBootstrapService::class)->cancelRun(
        \App\Models\V7\FundamentalBootstrapRun::query()->findOrFail((int) $argv[2]),
        'MySQL cancellation fixture', 'cancel stale bootstrap attempts',
    );
} elseif (($argv[1] ?? '') === 'competing-worker') {
    $result = app(\App\Services\Fundamentals\FundamentalBootstrapService::class)->process(
        \App\Models\V7\FundamentalBootstrapRun::query()->findOrFail((int) $argv[2]), 1,
    );
    file_put_contents((string) getenv('BOOTSTRAP_RESULT_PATH'), json_encode([
        'status' => $result['status'] ?? null,
        'skipped_reason' => $result['skipped_reason'] ?? null,
        'cache' => $cacheDiagnostics(),
    ], JSON_THROW_ON_ERROR), LOCK_EX);
    file_put_contents((string) getenv('BOOTSTRAP_READY_PATH'), (string) ($result['skipped_reason'] ?? 'processed'), LOCK_EX);
} elseif (in_array(($argv[1] ?? ''), ['worker-old', 'worker-new'], true)) {
    $role = $argv[1];
    $readyPath = (string) getenv('BOOTSTRAP_READY_PATH');
    $releasePath = (string) getenv('BOOTSTRAP_RELEASE_PATH');
    $ingest = new class($role, $readyPath, $releasePath) extends \App\Services\Fundamentals\FundamentalHistoricalIngestService {
        public function __construct(private string $role, private string $readyPath, private string $releasePath) {}

        public function fetch(\App\Models\Stock $stock, string $cadence, bool $manualStockRequest = false): array
        {
            if (app('db')->transactionLevel() !== 0) {
                throw new RuntimeException('Historical provider callback was invoked inside a database transaction.');
            }
            if ($cadence !== 'quarterly') return [];
            if (in_array($this->role, ['worker-old', 'worker-new'], true)) {
                file_put_contents($this->readyPath, 'ready', LOCK_EX);
                $deadline = microtime(true) + 30;
                while (! is_file($this->releasePath) && microtime(true) < $deadline) usleep(20000);
                if (! is_file($this->releasePath)) throw new RuntimeException('Timed out waiting for stale worker release.');
            }
            return [[
                'statement_type' => 'income', 'cadence' => $cadence, 'fact_key' => 'revenue',
                'period_end' => '2024-03-31', 'value' => $this->role === 'worker-old' ? 111 : 222,
                'currency' => 'INR', 'provider' => 'yahoo', 'source_meta' => ['worker' => $this->role],
            ]];
        }
    };
    $app->instance(\App\Services\Fundamentals\FundamentalHistoricalIngestService::class, $ingest);
    $result = app(\App\Services\Fundamentals\FundamentalBootstrapService::class)->process(
        \App\Models\V7\FundamentalBootstrapRun::query()->findOrFail((int) $argv[2]), 1,
    );
    file_put_contents((string) getenv('BOOTSTRAP_RESULT_PATH'), json_encode([
        'status' => $result['status'] ?? null,
        'skipped_reason' => $result['skipped_reason'] ?? null,
        'cache' => $cacheDiagnostics(),
    ], JSON_THROW_ON_ERROR), LOCK_EX);
} else {
    app(\App\Services\Fundamentals\FundamentalBootstrapService::class)->process(
        \App\Models\V7\FundamentalBootstrapRun::query()->findOrFail((int) $argv[1]), 1,
    );
}
