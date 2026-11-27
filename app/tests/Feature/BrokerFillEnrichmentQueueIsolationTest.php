<?php

namespace Tests\Feature;

use App\Engines\Execution\ExecutionEngine;
use App\Models\Stock;
use App\Models\TradingOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\TransactionWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrokerFillEnrichmentQueueIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_broker_buy_fill_queues_one_durable_enrichment_job(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.connection' => null]);
        $user = User::query()->create([
            'name' => 'Queue Success',
            'email' => 'queue-success-'.Str::random(8).'@example.test',
            'password' => 'synthetic-test-password',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, 10_000, 'synthetic broker fill fixture', $user);
        $stock = Stock::query()->create([
            'symbol' => 'QSU'.strtoupper(Str::random(4)), 'exchange' => 'NSE', 'name' => 'Queue Success Fixture',
            'is_active' => true, 'is_benchmark' => false,
        ]);
        $order = TradingOrder::query()->create([
            'profile_id' => $profile->id, 'security_id' => $stock->id, 'side' => 'buy',
            'quantity' => 2, 'order_type' => 'market', 'status' => TradingOrder::STATUS_PENDING,
        ]);

        $result = app(ExecutionEngine::class)->applyBrokerFill($profile, $order, 2, 101);

        $this->assertNotNull($result['transaction']);
        $this->assertSame(1, DB::table('portfolio_jobs')->count());
        $job = DB::table('portfolio_jobs')->first();
        $payload = json_decode($job->payload, true);
        $command = unserialize($payload['data']['command']);
        $this->assertSame('default', $job->queue);
        $this->assertInstanceOf(\App\Jobs\BackfillHistoricalDataJob::class, $command);
        $this->assertSame($stock->id, $command->stockId);
        $this->assertSame(now()->toDateString(), $command->fromDate);

        // A duplicate reconciliation sees no positive fill delta and cannot add a duplicate job.
        app(ExecutionEngine::class)->applyBrokerFill($profile, $order->fresh(), 2, 101);
        $this->assertSame(1, DB::table('portfolio_jobs')->count());
    }

    public function test_broker_fill_commits_when_enrichment_queue_is_unavailable_but_user_create_remains_atomic(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => null,
            // Point at a nonexistent table to exercise queue insert failure without DDL.
            'queue.connections.database.table' => 'portfolio_jobs_unavailable',
        ]);

        $user = User::query()->create([
            'name' => 'Queue Isolation',
            'email' => 'queue-isolation-'.Str::random(8).'@example.test',
            'password' => 'synthetic-test-password',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, 10_000, 'synthetic broker fill fixture', $user);
        $stock = Stock::query()->create([
            'symbol' => 'QISO'.strtoupper(Str::random(4)), 'exchange' => 'NSE', 'name' => 'Queue Isolation Fixture',
            'is_active' => true, 'is_benchmark' => false,
        ]);

        try {
            app(TransactionWriteService::class)->create($profile, $stock, [
                'type' => 'buy', 'quantity' => 1, 'price' => 100, 'fees' => 0,
                'transaction_date' => now()->toDateString(),
            ], user: $user);
            $this->fail('A user transaction must roll back when durable enrichment cannot be queued.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(0, Transaction::query()->where('profile_id', $profile->id)->count());
            $this->assertEqualsWithDelta(10_000.0, app(CashManagementService::class)->balance($profile), 0.0001);
        }

        $order = TradingOrder::query()->create([
            'profile_id' => $profile->id, 'security_id' => $stock->id, 'side' => 'buy',
            'quantity' => 2, 'order_type' => 'market', 'status' => TradingOrder::STATUS_PENDING,
        ]);

        $result = app(ExecutionEngine::class)->applyBrokerFill($profile, $order, 2, 101);

        $this->assertNotNull($result['transaction']);
        $this->assertSame(1, Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(1, $order->orderTransactions()->count());
        $this->assertSame(TradingOrder::STATUS_EXECUTED, $order->fresh()->status);
        $this->assertEqualsWithDelta(
            10_000.0 - ((float) $result['transaction']->quantity * (float) $result['transaction']->price)
                - (float) $result['transaction']->fees,
            app(CashManagementService::class)->balance($profile),
            0.0001,
        );

        $manualOrder = TradingOrder::query()->create([
            'profile_id' => $profile->id, 'security_id' => $stock->id, 'side' => 'buy',
            'quantity' => 1, 'order_type' => 'market', 'status' => TradingOrder::STATUS_PENDING,
        ]);
        try {
            app(ExecutionEngine::class)->executeOrder($profile, $manualOrder, ['price' => 102]);
            $this->fail('Manual order execution must keep enrichment and the ledger unit atomic.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, Transaction::query()->where('profile_id', $profile->id)->count());
            $this->assertSame(TradingOrder::STATUS_PENDING, $manualOrder->fresh()->status);
            $this->assertEqualsWithDelta(
                10_000.0 - ((float) $result['transaction']->quantity * (float) $result['transaction']->price)
                    - (float) $result['transaction']->fees,
                app(CashManagementService::class)->balance($profile),
                0.0001,
            );
        }
    }
}
