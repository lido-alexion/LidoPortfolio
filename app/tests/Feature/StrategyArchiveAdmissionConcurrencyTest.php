<?php

namespace Tests\Feature;

use App\Models\PortfolioProfile;
use App\Models\CapitalRequest;
use App\Models\PendingSaleProceeds;
use App\Models\TradingOrder;
use App\Models\TradingStrategy;
use App\Models\TradingRecommendation;
use App\Models\Stock;
use App\Models\User;
use App\Engines\Execution\ExecutionEngine;
use App\Services\Artifacts\StrategyArtifactRegistry;
use App\Services\Lending\CapitalRequestService;
use App\Services\Lending\SaleProceedsAvailabilityService;
use App\Services\Strategy\StrategyArchiveBlocked;
use App\Services\Strategy\StrategyLifecycleAdmission;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Separate PHP/MySQL sessions prove both sides contend on the strategy admission row. */
class StrategyArchiveAdmissionConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $primary = config('database.default');
        DB::setDefaultConnection($primary);
        DB::purge($primary);

        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_sequential_writer_then_archive_and_archive_then_writer_recheck_lifecycle(): void
    {
        $primary = DB::getDefaultConnection();
        $this->assertSame('mysql', DB::connection($primary)->getDriverName());
        $alternate = 'str14_archive_interleaving';
        config(['database.connections.'.$alternate => config('database.connections.'.$primary)]);
        DB::purge($alternate);

        try {
            [$writerProfile, $writerStrategy] = $this->strategyFixture('writer-first');
            $this->useConnection($primary);
            app(SaleProceedsAvailabilityService::class)->scheduleForObligation(
                $writerProfile, $writerStrategy, 125.0, 125.0, PendingSaleProceeds::OBLIGATION_RECALL,
            ); // The writer commits before archive starts; this verifies the blocker recheck only.

            $this->useConnection($alternate);
            $writerProfile = PortfolioProfile::query()->findOrFail($writerProfile->id);
            $writerStrategy = TradingStrategy::query()->findOrFail($writerStrategy->id);
            try {
                app(StrategyArtifactRegistry::class)->archive($writerStrategy->id, $writerProfile, 'Retirement requested');
                $this->fail('Archive must observe the settlement committed by the preceding writer transaction.');
            } catch (StrategyArchiveBlocked $blocked) {
                $this->assertContains('settlements', array_column($blocked->blockers, 'code'));
            }
            $this->assertSame(TradingStrategy::STATUS_ACTIVE, $writerStrategy->fresh()->status);

            [$archiveProfile, $archiveStrategy] = $this->strategyFixture('archive-first');
            $archiveProfile = PortfolioProfile::query()->findOrFail($archiveProfile->id);
            $archiveStrategy = TradingStrategy::query()->findOrFail($archiveStrategy->id);
            app(StrategyArtifactRegistry::class)->archive($archiveStrategy->id, $archiveProfile, 'Retirement requested');
            $this->assertSame(TradingStrategy::STATUS_ARCHIVED, $archiveStrategy->fresh()->status);

            $this->useConnection($primary);
            $archiveProfile = PortfolioProfile::query()->findOrFail($archiveProfile->id);
            $archiveStrategy = TradingStrategy::query()->findOrFail($archiveStrategy->id);
            try {
                app(SaleProceedsAvailabilityService::class)->scheduleForObligation(
                    $archiveProfile, $archiveStrategy, 125.0, 125.0, PendingSaleProceeds::OBLIGATION_RECALL,
                );
                $this->fail('A writer must re-read Archived after acquiring the same barrier.');
            } catch (ValidationException) {
                $this->assertSame(0, PendingSaleProceeds::query()->where('profile_id', $archiveProfile->id)->count());
            }
        } finally {
            $this->useConnection($primary);
            DB::purge($alternate);
        }
    }

    public function test_bilateral_admission_locks_strategy_rows_in_ascending_id_order(): void
    {
        $primary = DB::getDefaultConnection();
        $this->assertSame('mysql', DB::connection($primary)->getDriverName());
        [$profile, $borrower] = $this->strategyFixture('bilateral-borrower');
        $lender = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Bilateral lender',
            'slug' => 'bilateral-lender-'.$profile->id,
            'status' => TradingStrategy::STATUS_DISABLED,
        ]);

        $locked = DB::transaction(fn () => app(StrategyLifecycleAdmission::class)->lockForNewObligation(
            (int) $profile->id, [(int) $lender->id, (int) $borrower->id],
        ));

        $this->assertSame(
            collect([$borrower->id, $lender->id])->sort()->values()->all(),
            $locked->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_order_writer_holds_admission_row_while_archive_waits_then_archive_sees_order(): void
    {
        [$profile, $strategy] = $this->strategyFixture('overlap-order-writer');
        $stock = Stock::query()->create(['symbol' => 'STR14OW', 'exchange' => 'NSE', 'name' => 'Order overlap', 'is_active' => true]);
        $recommendation = $this->recommendation($profile, $strategy, $stock, TradingRecommendation::STATUS_PENDING_EXECUTION);

        $order = $this->withinParentTransaction(function () use ($profile, $strategy, $stock, $recommendation) {
            $result = app(ExecutionEngine::class)->recordOrder($profile, $stock, [
                'side' => 'buy', 'quantity' => 1, 'recommendation_id' => $recommendation->id,
            ]);
            $this->assertChildLockTimeout('archive', $profile, $strategy, $recommendation, $stock);

            return $result['order'];
        });

        try {
            app(StrategyArtifactRegistry::class)->archive($strategy->id, $profile, 'Retirement requested');
            $this->fail('Archive must see the pending order after the writer commits.');
        } catch (StrategyArchiveBlocked $blocked) {
            $this->assertContains('orders', array_column($blocked->blockers, 'code'));
        }
        $this->assertSame(TradingStrategy::STATUS_ACTIVE, $strategy->fresh()->status);
        $this->assertSame(TradingOrder::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $recommendation->fresh()->status);
        $this->assertSame(0, DB::table('portfolio_transactions')->where('profile_id', $profile->id)->count());
    }

    public function test_capital_request_writer_holds_admission_row_while_archive_waits_then_archive_sees_request(): void
    {
        [$profile, $strategy] = $this->strategyFixture('overlap-capital-writer');
        $stock = Stock::query()->create(['symbol' => 'STR14CW', 'exchange' => 'NSE', 'name' => 'Capital overlap', 'is_active' => true]);
        $recommendation = $this->recommendation($profile, $strategy, $stock, TradingRecommendation::STATUS_REJECTED);

        $request = $this->withinParentTransaction(function () use ($profile, $strategy, $recommendation, $stock) {
            $request = app(CapitalRequestService::class)->createRequest($profile, $recommendation, $strategy, 5000.0);
            $this->assertChildLockTimeout('archive', $profile, $strategy, $recommendation, $stock);

            return $request;
        });

        try {
            app(StrategyArtifactRegistry::class)->archive($strategy->id, $profile, 'Retirement requested');
            $this->fail('Archive must see the capital request after the writer commits.');
        } catch (StrategyArchiveBlocked $blocked) {
            $this->assertContains('capital_requests', array_column($blocked->blockers, 'code'));
        }
        $this->assertSame(TradingStrategy::STATUS_ACTIVE, $strategy->fresh()->status);
        $this->assertSame(CapitalRequest::STATUS_DISPLAYED, $request->fresh()->status);
    }

    public function test_archive_holds_admission_row_while_capital_writer_waits_then_writer_is_refused(): void
    {
        [$profile, $strategy] = $this->strategyFixture('overlap-capital-archive');
        $stock = Stock::query()->create(['symbol' => 'STR14CA', 'exchange' => 'NSE', 'name' => 'Archived capital', 'is_active' => true]);
        $recommendation = $this->recommendation($profile, $strategy, $stock, TradingRecommendation::STATUS_REJECTED);

        $this->withinParentTransaction(function () use ($profile, $strategy, $recommendation, $stock) {
            app(StrategyArtifactRegistry::class)->archive($strategy->id, $profile, 'Retirement requested');
            $this->assertChildLockTimeout('capital', $profile, $strategy, $recommendation, $stock);
        });

        try {
            app(CapitalRequestService::class)->createRequest($profile, $recommendation, $strategy, 5000.0);
            $this->fail('A retry must reject a new capital request for an Archived strategy.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('Archived strategies cannot accept', $error->getMessage());
        }
        $this->assertSame(TradingStrategy::STATUS_ARCHIVED, $strategy->fresh()->status);
        $this->assertSame(0, CapitalRequest::query()->where('profile_id', $profile->id)->count());
    }

    public function test_archive_holds_admission_row_while_settlement_writer_waits_then_writer_is_refused(): void
    {
        [$profile, $strategy] = $this->strategyFixture('overlap-settlement-archive');
        $stock = Stock::query()->create(['symbol' => 'STR14SA', 'exchange' => 'NSE', 'name' => 'Archived settlement', 'is_active' => true]);
        $recommendation = $this->recommendation($profile, $strategy, $stock, TradingRecommendation::STATUS_REJECTED);

        $this->withinParentTransaction(function () use ($profile, $strategy, $recommendation, $stock) {
            app(StrategyArtifactRegistry::class)->archive($strategy->id, $profile, 'Retirement requested');
            $this->assertChildLockTimeout('settlement', $profile, $strategy, $recommendation, $stock);
        });

        try {
            app(SaleProceedsAvailabilityService::class)->scheduleForObligation(
                $profile, $strategy, 125.0, 125.0, PendingSaleProceeds::OBLIGATION_RECALL,
            );
            $this->fail('A retry must reject new unsettled proceeds for an Archived strategy.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('Archived strategies cannot accept', $error->getMessage());
        }
        $this->assertSame(TradingStrategy::STATUS_ARCHIVED, $strategy->fresh()->status);
        $this->assertSame(0, PendingSaleProceeds::query()->where('profile_id', $profile->id)->count());
    }

    /** @return array{PortfolioProfile,TradingStrategy} */
    private function strategyFixture(string $name): array
    {
        $user = User::factory()->create(['name' => $name]);
        $profile = $this->defaultPortfolioFor($user);
        $version = app(\App\Services\StrategyConfigurationService::class)->ensureActive($profile);

        return [$profile, TradingStrategy::query()->findOrFail($version->strategy_id)];
    }

    private function useConnection(string $name): void
    {
        DB::setDefaultConnection($name);
        DB::purge($name);
    }

    private function recommendation($profile, TradingStrategy $strategy, Stock $stock, string $status): TradingRecommendation
    {
        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => $status,
            'priority' => 1,
            'strategy_score' => 70,
            'confidence' => 0.7,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'generated_at' => now(),
        ]);
    }

    private function withinParentTransaction(callable $operation): mixed
    {
        $connection = DB::connection();
        $connection->beginTransaction();
        try {
            $result = $operation();
            $connection->commit();

            return $result;
        } catch (\Throwable $error) {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    private function assertChildLockTimeout(string $mode, $profile, TradingStrategy $strategy, TradingRecommendation $recommendation, Stock $stock): void
    {
        $connectionName = DB::getDefaultConnection();
        $connection = config('database.connections.'.$connectionName);
        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => $connectionName,
            'DB_HOST' => $connection['host'] ?? '',
            'DB_PORT' => (string) ($connection['port'] ?? ''),
            'DB_DATABASE' => $connection['database'] ?? '',
            'DB_USERNAME' => $connection['username'] ?? '',
            'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
            'DB_URL' => '',
        ];
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/strategy-archive-admission-lock-probe.php'),
            $mode,
            (string) $profile->id,
            (string) $strategy->id,
            (string) $recommendation->id,
            (string) $stock->id,
        ], base_path(), $environment, null, 10);
        $process->run();

        $this->assertSame(20, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame('{"status":"lock-timeout","sqlstate":"HY000","errno":1205}', trim($process->getOutput()));
    }
}
