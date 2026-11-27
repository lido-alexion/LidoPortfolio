<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\PortfolioSnapshotRebuildService;
use App\Jobs\BackfillHistoricalDataJob;
use App\Services\StockValidationService;
use App\Services\StockResolverService;
use App\Support\StockValidationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionStockResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_buy_transaction_creates_stock_from_symbol_without_prior_master_entry(): void
    {
        $user = User::query()->create([
            'name' => 'Tx User',
            'email' => 'tx-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user);

        $this->postJson('/api/cash/deposit', [
            'amount' => 100000,
            'reason' => 'test seed',
        ])->assertCreated();

        $symbol = 'N'.strtoupper(Str::random(4));

        $this->mock(StockValidationService::class, function ($mock) {
            $mock->shouldReceive('validateAndPersist')
                ->andReturnUsing(function (string $inputSymbol, ?string $exchange, ?string $name) {
                    $stock = Stock::query()->create([
                        'symbol' => $inputSymbol,
                        'exchange' => $exchange ?? 'NSE',
                        'name' => $name ?? $inputSymbol,
                        'is_active' => true,
                        'is_benchmark' => false,
                    ]);

                    return StockValidationResult::valid($stock, 'test');
                });
        });

        $response = $this->postJson('/api/transactions', [
            'symbol' => $symbol,
            'name' => 'New Via Transaction',
            'exchange' => 'NSE',
            'type' => 'buy',
            'quantity' => 2,
            'price' => 50,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('portfolio_stocks', [
            'symbol' => $symbol,
            'name' => 'New Via Transaction',
        ]);
        $this->assertDatabaseHas('portfolio_transactions', [
            'profile_id' => $profile->id,
            'type' => 'buy',
            'grouped_fee_policy_enrolled' => true,
        ]);
    }

    public function test_buy_transaction_reuses_existing_stock_by_symbol(): void
    {
        $user = User::query()->create([
            'name' => 'Tx User 2',
            'email' => 'tx2-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'REUSE',
            'exchange' => 'NSE',
            'name' => 'Reuse Me',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $this->actingAs($user);

        $this->postJson('/api/cash/deposit', [
            'amount' => 100000,
            'reason' => 'test seed',
        ])->assertCreated();

        $response = $this->postJson('/api/transactions', [
            'symbol' => 'REUSE',
            'type' => 'buy',
            'quantity' => 1,
            'price' => 10,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertSame(1, Stock::query()->where('symbol', 'REUSE')->count());
        $this->assertDatabaseHas('portfolio_transactions', [
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
        ]);
    }

    public function test_idempotent_buy_replays_committed_transaction_and_rejects_payload_conflict(): void
    {
        Queue::fake();
        $user = User::query()->create([
            'name' => 'Idempotency User', 'email' => 'idem-'.Str::random(8).'@example.com', 'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'IDEM', 'exchange' => 'NSE', 'name' => 'Idempotency Stock', 'is_active' => true, 'is_benchmark' => false,
        ]);
        $this->actingAs($user);
        $this->postJson('/api/cash/deposit', ['amount' => 100000, 'reason' => 'test seed'])->assertCreated();
        config(['transactions.run_post_commit_side_effects_in_tests' => true]);
        $this->mock(PortfolioSnapshotRebuildService::class, function ($mock) use ($profile) {
            $mock->shouldReceive('rebuildAfterTransactionChange')->once()->withArgs(fn ($p) => $p->id === $profile->id)
                ->andThrow(new \RuntimeException('simulated snapshot failure after commit'));
        });
        $this->partialMock(CashManagementService::class, function ($mock) {
            $mock->shouldReceive('summary')->andThrow(new \RuntimeException('simulated post-commit summary failure'));
        });
        $this->mock(StockResolverService::class, function ($mock) use ($stock) {
            $mock->shouldReceive('resolve')->once()->andReturn($stock);
        });
        $payload = [
            'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 2, 'price' => 50,
            'fees' => 0, 'transaction_date' => now()->toDateString(),
        ];

        $first = $this->withHeader('Idempotency-Key', 'tx-retry-key-00000001')->postJson('/api/transactions', $payload)
            ->assertCreated()->assertJsonPath('cash', null);
        $transactionId = $first->json('data.id');
        $replay = $this->withHeader('Idempotency-Key', 'tx-retry-key-00000001')->postJson('/api/transactions', $payload)
            ->assertOk()->assertJsonPath('data.id', $transactionId);
        $firstData = json_decode($first->getContent(), flags: JSON_THROW_ON_ERROR)->data;
        $replayData = json_decode($replay->getContent(), flags: JSON_THROW_ON_ERROR)->data;
        $this->assertSame(
            self::normalizeJsonValue($firstData),
            self::normalizeJsonValue($replayData),
            'The created transaction and its idempotent replay must match in every JSON field and value.',
        );
        $this->assertSame(1, \App\Models\Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertDatabaseHas('portfolio_transaction_request_receipts', [
            'profile_id' => $profile->id, 'actor_user_id' => $user->id, 'transaction_id' => $transactionId,
        ]);
        Queue::assertPushedOn('default', BackfillHistoricalDataJob::class, fn ($job) => $job->stockId === $stock->id);

        $this->withHeader('Idempotency-Key', 'tx-retry-key-00000001')->postJson('/api/transactions', [
            ...$payload, 'quantity' => 3,
        ])->assertStatus(409);

        \App\Models\Transaction::query()->whereKey($transactionId)->delete();
        $this->withHeader('Idempotency-Key', 'tx-retry-key-00000001')->postJson('/api/transactions', $payload)
            ->assertStatus(410);
        $this->assertDatabaseHas('portfolio_transaction_request_receipts', [
            'profile_id' => $profile->id, 'idempotency_key_hash' => hash('sha256', 'tx-retry-key-00000001'),
            'transaction_id' => $transactionId,
        ]);
    }

    public function test_paper_replay_is_served_even_after_the_profile_pauses(): void
    {
        Queue::fake();
        $user = User::query()->create([
            'name' => 'Paper Replay User', 'email' => 'paper-replay-'.Str::random(8).'@example.com', 'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $profile->forceFill(['portfolio_type' => 'paper', 'simulation_state' => 'active'])->save();
        $stock = Stock::query()->create([
            'symbol' => 'PAPERREPLAY', 'exchange' => 'NSE', 'name' => 'Paper Replay Stock', 'is_active' => true, 'is_benchmark' => false,
        ]);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/cash/deposit', ['amount' => 100000, 'reason' => 'test seed'])->assertCreated();
        $payload = ['stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 1, 'price' => 50, 'transaction_date' => now()->toDateString()];
        $first = $this->withHeader('Idempotency-Key', 'paper-replay-request-0001')->postJson('/api/transactions', $payload)->assertCreated();
        $profile->forceFill(['simulation_state' => 'paused'])->save();

        $this->withHeader('Idempotency-Key', 'paper-replay-request-0001')->postJson('/api/transactions', $payload)
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, \App\Models\Transaction::query()->where('profile_id', $profile->id)->count());
    }

    public function test_same_key_is_independent_across_portfolios(): void
    {
        Queue::fake();
        $user = User::query()->create([
            'name' => 'Scope User', 'email' => 'scope-'.Str::random(8).'@example.com', 'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $otherProfile = $this->createPortfolioProfile($user, 'Secondary', false);
        $stock = Stock::query()->create([
            'symbol' => 'SCOPETX', 'exchange' => 'NSE', 'name' => 'Scoped Stock', 'is_active' => true, 'is_benchmark' => false,
        ]);
        $this->actingAs($user);
        foreach ([$profile, $otherProfile] as $scopeProfile) {
            $this->withProfileHeader($user, $scopeProfile)->postJson('/api/cash/deposit', ['amount' => 100000, 'reason' => 'test seed'])->assertCreated();
        }
        $payload = ['stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 1, 'price' => 50, 'transaction_date' => now()->toDateString()];
        $first = $this->withProfileHeader($user, $profile)->withHeader('Idempotency-Key', 'same-request-key-0001')
            ->postJson('/api/transactions', $payload)->assertCreated();
        $second = $this->withProfileHeader($user, $otherProfile)->withHeader('Idempotency-Key', 'same-request-key-0001')
            ->postJson('/api/transactions', $payload)->assertCreated();
        $otherActor = User::query()->create([
            'name' => 'Other Scope User', 'email' => 'other-scope-'.Str::random(8).'@example.com', 'password' => 'password123',
        ]);
        $otherActorProfile = $this->defaultPortfolioFor($otherActor);
        $this->actingAs($otherActor)->withProfileHeader($otherActor, $otherActorProfile)
            ->postJson('/api/cash/deposit', ['amount' => 100000, 'reason' => 'test seed'])->assertCreated();
        $third = $this->withProfileHeader($otherActor, $otherActorProfile)->withHeader('Idempotency-Key', 'same-request-key-0001')
            ->postJson('/api/transactions', $payload)->assertCreated();
        $this->assertCount(3, collect([$first->json('data.id'), $second->json('data.id'), $third->json('data.id')])->unique());
        $this->assertDatabaseCount('portfolio_transaction_request_receipts', 3);
    }

    public function test_price_enrichment_queue_insert_failure_rolls_back_ledger_and_receipt(): void
    {
        $user = User::query()->create([
            'name' => 'Queue Failure User', 'email' => 'queue-failure-'.Str::random(8).'@example.com', 'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'QUEUEFAIL', 'exchange' => 'NSE', 'name' => 'Queue Failure Stock', 'is_active' => true, 'is_benchmark' => false,
        ]);
        $this->actingAs($user);
        $this->postJson('/api/cash/deposit', ['amount' => 100000, 'reason' => 'test seed'])->assertCreated();
        $cashEntryCount = \Illuminate\Support\Facades\DB::table('portfolio_cash_ledger_entries')->where('profile_id', $profile->id)->count();
        config(['queue.connections.database.table' => 'isolated_missing_portfolio_jobs']);
        // QueueManager can retain a resolved database connection across HTTP
        // requests in this test process. Rebuild it so the failing insert uses
        // the deliberately missing table configured above.
        app()->forgetInstance('queue');
        \Illuminate\Support\Facades\Queue::clearResolvedInstance('queue');

        try {
            $this->withHeader('Idempotency-Key', 'queue-failure-request-0001')->postJson('/api/transactions', [
                'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 1, 'price' => 50,
                'transaction_date' => now()->toDateString(),
            ])->assertServerError();
        } finally {
            config(['queue.connections.database.table' => 'portfolio_jobs']);
            app()->forgetInstance('queue');
            \Illuminate\Support\Facades\Queue::clearResolvedInstance('queue');
        }

        $this->assertDatabaseMissing('portfolio_transactions', ['profile_id' => $profile->id, 'stock_id' => $stock->id]);
        $this->assertDatabaseMissing('portfolio_transaction_request_receipts', [
            'profile_id' => $profile->id, 'actor_user_id' => $user->id,
            'idempotency_key_hash' => hash('sha256', 'queue-failure-request-0001'),
        ]);
        $this->assertSame($cashEntryCount, \Illuminate\Support\Facades\DB::table('portfolio_cash_ledger_entries')->where('profile_id', $profile->id)->count());
    }

    public function test_buy_enrichment_is_inserted_into_the_real_database_queue_once(): void
    {
        $user = User::query()->create([
            'name' => 'Real Queue User', 'email' => 'real-queue-'.Str::random(8).'@example.com', 'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'REALQUEUE', 'exchange' => 'NSE', 'name' => 'Real Queue Stock', 'is_active' => true, 'is_benchmark' => false,
        ]);
        $this->actingAs($user);
        $this->postJson('/api/cash/deposit', ['amount' => 100000, 'reason' => 'test seed'])->assertCreated();
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => \Illuminate\Support\Facades\DB::connection()->getName(),
            'queue.connections.database.table' => 'portfolio_jobs',
        ]);
        $payload = ['stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 1, 'price' => 50, 'transaction_date' => now()->toDateString()];
        $before = \Illuminate\Support\Facades\DB::table('portfolio_jobs')->where('queue', 'default')->count();

        $first = $this->withHeader('Idempotency-Key', 'real-db-queue-request-0001')->postJson('/api/transactions', $payload)->assertCreated();
        $this->withHeader('Idempotency-Key', 'real-db-queue-request-0001')->postJson('/api/transactions', $payload)->assertOk();

        $this->assertSame($before + 1, \Illuminate\Support\Facades\DB::table('portfolio_jobs')->where('queue', 'default')->count());
        $job = \Illuminate\Support\Facades\DB::table('portfolio_jobs')->where('queue', 'default')->orderByDesc('id')->first();
        $envelope = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
        $queuedJob = unserialize($envelope['data']['command']);
        $this->assertInstanceOf(BackfillHistoricalDataJob::class, $queuedJob);
        $this->assertSame($stock->id, $queuedJob->stockId);
        $this->assertSame('default', $job->queue);
        $this->assertSame(1, \App\Models\Transaction::query()->where('profile_id', $profile->id)->whereKey($first->json('data.id'))->count());
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('portfolio_transaction_request_receipts')->where('transaction_id', $first->json('data.id'))->count());
    }

    /** Sort object properties recursively while tagging object/array shape and preserving scalar types. */
    private static function normalizeJsonValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            uksort($properties, fn ($left, $right) => strcmp((string) $left, (string) $right));
            $normalized = [];
            foreach ($properties as $key => $child) {
                $normalized[] = [(string) $key, self::normalizeJsonValue($child)];
            }

            return ['json-object', $normalized];
        }
        if (is_array($value)) {
            return ['json-array', array_map(self::normalizeJsonValue(...), $value)];
        }

        return $value;
    }
}
