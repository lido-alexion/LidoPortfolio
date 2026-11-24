<?php

namespace Tests\Feature;

use App\Models\CorporateAction;
use App\Models\DataQualityIssue;
use App\Models\Holding;
use App\Models\PriceAdjustmentFactor;
use App\Models\Stock;
use App\Models\StockMetric;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CorporateActionPriceRepairService;
use App\Services\CorporateActionService;
use App\Services\DataQualityResolutionService;
use App\Services\MetricsUpdateService;
use App\Services\PortfolioSnapshotRebuildService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesPortfolioProfiles;
use Tests\TestCase;

/** Separate-process regressions for MySQL/MariaDB REPEATABLE READ lock ordering. */
class CorporateActionRepairSnapshotConcurrencyTest extends TestCase
{
    use CreatesPortfolioProfiles;

    /** @var list<int> */
    private array $stockIds = [];

    /** @var list<int> */
    private array $profileIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindQuietMetrics();
        $snapshots = Mockery::mock(PortfolioSnapshotRebuildService::class);
        $snapshots->shouldReceive('rebuildAfterTransactionChange')->andReturn([]);
        $this->app->instance(PortfolioSnapshotRebuildService::class, $snapshots);
    }

    protected function tearDown(): void
    {
        $connection = DB::connection();
        while ($connection->transactionLevel() > 0) $connection->rollBack();
        if ($connection->getDriverName() === 'mysql' && $this->stockIds !== []) {
            $issueIds = DB::table('portfolio_data_quality_issues')->whereIn('stock_id', $this->stockIds)->pluck('id');
            DB::table('portfolio_data_quality_issue_resolutions')->whereIn('issue_id', $issueIds)->delete();
            DB::table('portfolio_data_quality_issue_evidence')->whereIn('issue_id', $issueIds)->delete();
            DB::table('portfolio_price_adjustment_factors')->whereIn('stock_id', $this->stockIds)->delete();
            DB::table('portfolio_data_quality_issues')->whereIn('id', $issueIds)->delete();
            DB::table('portfolio_corporate_actions')->whereIn('stock_id', $this->stockIds)->delete();
            DB::table('portfolio_transactions')->whereIn('stock_id', $this->stockIds)->delete();
            DB::table('portfolio_holdings')->whereIn('stock_id', $this->stockIds)->delete();
            DB::table('portfolio_stock_prices')->whereIn('stock_id', $this->stockIds)->delete();
            DB::table('portfolio_stock_metrics')->whereIn('stock_id', $this->stockIds)->delete();
            DB::table('portfolio_watchlist_items')->whereIn('stock_id', $this->stockIds)->delete();
            DB::table('portfolio_stocks')->whereIn('id', $this->stockIds)->delete();
            DB::table('portfolio_watchlists')->whereIn('profile_id', $this->profileIds)->delete();
            DB::table('portfolio_profiles')->whereIn('id', $this->profileIds)->delete();
            DB::table('portfolio_users')->whereIn('id', $this->userIds)->delete();
        }
        parent::tearDown();
    }

    public function test_f020_repair_observes_f042_factor_committed_while_waiting_for_stock_lock(): void
    {
        $this->requireMysql();
        [$user, $profile, $stock] = $this->portfolioStock('RACEA'.Str::random(4));
        $action = $this->corporateAction($profile->id, $stock->id, '2026-03-01');
        $this->price($stock->id, '2026-02-27', 100);
        $this->price($stock->id, '2026-03-01', 50);
        $issue = $this->pendingIssue($stock, '2026-03-01');

        $this->withStockLock($stock, function (string $ready, string $result) use ($issue, $action): void {
            $worker = $this->startWorker('action', (int) $action->id, $ready, $result);
            try {
                $this->waitForReady($worker, $ready);
                $accepted = app(DataQualityResolutionService::class)->accept($issue, 2.0, 'Concurrency fixture');
                $factor = PriceAdjustmentFactor::query()->where('issue_id', $accepted->id)->firstOrFail();
                $repair = app(CorporateActionPriceRepairService::class)->repairPendingFactors(
                    factorId: (int) $factor->id, dryRun: false,
                );
                $this->assertSame(1, $repair['repaired']);
            } finally {
                $this->commitStockLock();
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            }
            $workerResult = json_decode((string) file_get_contents($result), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $workerResult['skipped']);
            $this->assertSame(CorporateActionPriceRepairService::STATUS_DEFERRED_TO_FACTOR, $workerResult['details'][0]['status']);
        });

        $this->assertSame(50.0, $this->closeOn($stock->id, '2026-02-27'));
        $this->assertNull($action->fresh()->metadata['price_adjustment'] ?? null);
    }

    public function test_f042_repair_uses_prices_committed_by_earlier_f020_while_waiting_for_stock_lock(): void
    {
        $this->requireMysql();
        [$user, $profile, $stock] = $this->portfolioStock('RACEF'.Str::random(4));
        $this->buyAndHold($profile->id, $stock->id);
        foreach ([['2026-11-11', 100], ['2026-11-12', 100], ['2026-11-15', 100], ['2026-11-16', 10]] as [$date, $close]) {
            $this->price($stock->id, $date, $close);
        }
        $factor = $this->pendingFactor($stock, '2026-11-16', 10.0);

        $this->withStockLock($stock, function (string $ready, string $result) use ($factor, $profile, $stock): void {
            $worker = $this->startWorker('factor', (int) $factor->id, $ready, $result);
            try {
                $this->waitForReady($worker, $ready);
                app(CorporateActionService::class)->apply($profile, $stock, [
                    'action_type' => 'split', 'ratio_from' => 1, 'ratio_to' => 2, 'ex_date' => '2026-11-12',
                ]);
            } finally {
                $this->commitStockLock();
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            }
            $workerResult = json_decode((string) file_get_contents($result), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $workerResult['repaired']);
        });

        // The worker must see F020's committed 50 before applying F042's /10.
        $this->assertSame(5.0, $this->closeOn($stock->id, '2026-11-11'));
        $this->assertSame(10.0, $this->closeOn($stock->id, '2026-11-12'));
        $this->assertSame(10.0, $this->closeOn($stock->id, '2026-11-15'));
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED, $factor->fresh()->metadata['ohlcv_repair_status']);
    }

    public function test_metadata_only_repair_rechecks_factor_state_after_stock_lock(): void
    {
        $this->requireMysql();
        [$user, $profile, $stock] = $this->portfolioStock('RACEM'.Str::random(4));
        $action = $this->corporateAction($profile->id, $stock->id, '2026-03-01');
        $this->price($stock->id, '2026-02-27', 100);
        $this->price($stock->id, '2026-03-01', 100);
        $issue = $this->pendingIssue($stock, '2026-03-01');

        $this->withStockLock($stock, function (string $ready, string $result) use ($issue, $action): void {
            $worker = $this->startWorker('action', (int) $action->id, $ready, $result);
            try {
                $this->waitForReady($worker, $ready);
                app(DataQualityResolutionService::class)->accept($issue, 2.0, 'Metadata concurrency fixture');
            } finally {
                $this->commitStockLock();
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            }
            $workerResult = json_decode((string) file_get_contents($result), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $workerResult['skipped']);
        });

        $this->assertNull($action->fresh()->metadata['price_adjustment'] ?? null);
    }

    private function requireMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Separate-process MySQL row-lock regression requires the hosted MySQL test database.');
        }
        $isolation = DB::selectOne('select @@transaction_isolation as isolation_level');
        $this->assertSame('REPEATABLE-READ', strtoupper(str_replace('_', '-', (string) $isolation->isolation_level)));
    }

    private function portfolioStock(string $symbol): array
    {
        $user = User::query()->create([
            'name' => 'Corporate Action Concurrency', 'email' => 'ca-race-'.Str::random(10).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => $symbol, 'exchange' => 'NSE', 'name' => $symbol.' Ltd',
            'is_active' => true, 'is_benchmark' => false,
        ]);
        $this->userIds[] = (int) $user->id;
        $this->profileIds[] = (int) $profile->id;
        $this->stockIds[] = (int) $stock->id;

        return [$user, $profile, $stock];
    }

    private function corporateAction(int $profileId, int $stockId, string $exDate): CorporateAction
    {
        return CorporateAction::query()->create([
            'profile_id' => $profileId, 'stock_id' => $stockId, 'action_type' => 'split',
            'ratio_from' => 1, 'ratio_to' => 2, 'ex_date' => $exDate, 'applied_at' => now(), 'metadata' => null,
        ]);
    }

    private function pendingIssue(Stock $stock, string $exDate): DataQualityIssue
    {
        return DataQualityIssue::query()->create([
            'stock_id' => $stock->id, 'symbol' => $stock->symbol,
            'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'issue_status' => DataQualityIssue::STATUS_PENDING_REVIEW,
            'detection_method' => DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
            'detection_source' => 'concurrency-test', 'suggested_ratio' => 2.0, 'latest_suggested_ratio' => 2.0,
            'confidence' => 1.0, 'corporate_action_type' => 'split', 'ex_date' => $exDate,
            'exchange_match' => true, 'detected_at' => now(),
        ]);
    }

    private function pendingFactor(Stock $stock, string $exDate, float $factor): PriceAdjustmentFactor
    {
        return PriceAdjustmentFactor::query()->create([
            'stock_id' => $stock->id, 'issue_id' => null, 'factor_type' => 'corporate_action',
            'action_type' => 'face_value_split', 'effective_ex_date' => $exDate,
            'applied_ratio' => $factor, 'price_divisor' => $factor, 'volume_multiplier' => $factor,
            'is_active' => true, 'applied_at' => now(),
            'metadata' => ['source' => 'concurrency-test', 'ohlcv_repair_status' => PriceAdjustmentFactor::REPAIR_STATUS_PENDING],
        ]);
    }

    private function price(int $stockId, string $date, float $close): void
    {
        StockPrice::query()->create([
            'stock_id' => $stockId, 'price_date' => $date, 'open_price' => $close,
            'high_price' => $close, 'low_price' => $close, 'close_price' => $close,
            'adjusted_close_price' => $close, 'volume' => 1000, 'provider_source' => 'test',
            'data_source' => 'test', 'created_at' => now(),
        ]);
    }

    private function buyAndHold(int $profileId, int $stockId): void
    {
        Transaction::query()->create([
            'profile_id' => $profileId, 'stock_id' => $stockId, 'type' => 'buy',
            'quantity' => 10, 'price' => 100, 'fees' => 0, 'transaction_date' => '2026-11-01', 'source' => 'manual',
        ]);
        Holding::query()->create([
            'profile_id' => $profileId, 'stock_id' => $stockId, 'quantity' => 10,
            'avg_buy_price' => 100, 'invested_amount' => 1000, 'realized_profit' => 0, 'updated_at' => now(),
        ]);
    }

    private function bindQuietMetrics(): void
    {
        $metrics = Mockery::mock(MetricsUpdateService::class);
        $metrics->shouldReceive('updateStock')->andReturnUsing(static function (Stock $stock) {
            return StockMetric::query()->firstOrCreate(
                ['stock_id' => $stock->id], ['highest_close' => 0, 'latest_close' => 0, 'updated_at' => now()],
            );
        });
        $this->app->instance(MetricsUpdateService::class, $metrics);
    }

    private function withStockLock(Stock $stock, callable $operation): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();
        DB::table('portfolio_stocks')->where('id', $stock->id)->lockForUpdate()->first();
        [$readyPath, $resultPath] = $this->temporaryPaths();
        try {
            $operation($readyPath, $resultPath);
        } finally {
            while ($connection->transactionLevel() > 0) $connection->rollBack();
            @unlink($readyPath);
            @unlink($resultPath);
            $tempDirectory = dirname($readyPath);
            if (is_dir($tempDirectory) && glob($tempDirectory.'/*') === []) @rmdir($tempDirectory);
        }
    }

    private function commitStockLock(): void
    {
        DB::connection()->commit();
    }

    private function temporaryPaths(): array
    {
        $token = Str::uuid()->toString();
        $directory = base_path('tests/.tmp');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Could not create in-checkout concurrency-test temp directory.');
        }

        return [$directory.'/ca-repair-ready-'.$token, $directory.'/ca-repair-result-'.$token];
    }

    private function startWorker(string $mode, int $id, string $readyPath, string $resultPath): Process
    {
        $name = DB::getDefaultConnection();
        $connection = config('database.connections.'.$name);
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => $name,
            'DB_HOST' => $connection['host'] ?? '', 'DB_PORT' => (string) ($connection['port'] ?? ''),
            'DB_DATABASE' => $connection['database'] ?? '', 'DB_USERNAME' => $connection['username'] ?? '',
            'DB_PASSWORD' => (string) ($connection['password'] ?? ''), 'DB_URL' => '',
            'CA_REPAIR_READY_PATH' => $readyPath, 'CA_REPAIR_RESULT_PATH' => $resultPath,
        ];
        $process = new Process([
            PHP_BINARY, base_path('tests/Fixtures/corporate-action-repair-snapshot-concurrency.php'), $mode, (string) $id,
        ], base_path(), $environment, null, 60);
        $process->start();

        return $process;
    }

    private function waitForReady(Process $process, string $readyPath): void
    {
        $deadline = microtime(true) + 30;
        while (! is_file($readyPath) && microtime(true) < $deadline) {
            if (! $process->isRunning()) {
                $this->fail('Repair worker exited before parent-key read: '.$process->getErrorOutput().$process->getOutput());
            }
            usleep(20000);
        }
        $this->assertFileExists($readyPath, 'Repair worker did not complete its parent-key read before timeout.');
    }

    private function closeOn(int $stockId, string $date): float
    {
        return (float) StockPrice::query()->where('stock_id', $stockId)->whereDate('price_date', $date)->value('close_price');
    }
}
