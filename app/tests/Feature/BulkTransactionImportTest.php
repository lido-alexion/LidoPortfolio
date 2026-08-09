<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\Transaction;
use App\Models\TransactionImportBatch;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\StockValidationService;
use App\Services\TransactionWriteService;
use App\Support\StockValidationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BulkTransactionImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(StockValidationService::class, function ($mock) {
            $mock->shouldReceive('validateAndPersist')
                ->andReturnUsing(function (string $inputSymbol, ?string $exchange, ?string $name) {
                    $stock = Stock::query()->firstOrCreate(
                        [
                            'symbol' => strtoupper($inputSymbol),
                            'exchange' => $exchange ?? 'NSE',
                        ],
                        [
                            'name' => $name ?? $inputSymbol,
                            'is_active' => true,
                            'is_benchmark' => false,
                        ],
                    );

                    return StockValidationResult::valid($stock, 'test');
                });
            $mock->shouldReceive('validate')
                ->andReturnUsing(function (string $inputSymbol, ?string $exchange) {
                    $stock = Stock::query()
                        ->where('symbol', strtoupper($inputSymbol))
                        ->where('exchange', $exchange ?? 'NSE')
                        ->first();
                    if (! $stock) {
                        return StockValidationResult::invalid(['Not found']);
                    }

                    return StockValidationResult::valid($stock, 'test');
                });
        });
    }

    protected function makeUserWithCash(float $cash = 1_000_000): array
    {
        $user = User::query()->create([
            'name' => 'Bulk User',
            'email' => 'bulk-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, $cash, 'test seed', $user);

        return [$user, $profile];
    }

    protected function row(string $rowId, string $symbol, string $type, float $qty, float $price, ?string $date = null): array
    {
        return [
            'row_id' => $rowId,
            'symbol' => $symbol,
            'exchange' => 'NSE',
            'type' => $type,
            'quantity' => $qty,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => $date ?? now()->toDateString(),
        ];
    }

    public function test_complete_valid_batch_commits_successfully(): void
    {
        [$user, $profile] = $this->makeUserWithCash();
        $batchId = (string) Str::uuid();
        $row1 = (string) Str::uuid();
        $row2 = (string) Str::uuid();

        $response = $this->actingAs($user)->postJson('/api/transactions/bulk', [
            'batch_id' => $batchId,
            'rows' => [
                $this->row($row1, 'AAA', 'buy', 2, 10),
                $this->row($row2, 'BBB', 'buy', 3, 20),
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'committed')
            ->assertJsonPath('batch_id', $batchId)
            ->assertJsonPath('row_count', 2);

        $this->assertSame(2, Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertDatabaseHas('portfolio_transaction_import_batches', [
            'batch_key' => $batchId,
            'profile_id' => $profile->id,
            'status' => 'committed',
        ]);
    }

    public function test_validation_failure_commits_nothing(): void
    {
        [$user, $profile] = $this->makeUserWithCash();
        $batchId = (string) Str::uuid();

        $response = $this->actingAs($user)->postJson('/api/transactions/bulk', [
            'batch_id' => $batchId,
            'rows' => [
                $this->row((string) Str::uuid(), 'AAA', 'buy', 1, 10),
                $this->row((string) Str::uuid(), 'BBB', 'sell', 1, 10),
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(0, TransactionImportBatch::query()->where('batch_key', $batchId)->count());
    }

    public function test_cash_failure_rolls_back_entire_batch(): void
    {
        [$user, $profile] = $this->makeUserWithCash(50);
        $batchId = (string) Str::uuid();

        $response = $this->actingAs($user)->postJson('/api/transactions/bulk', [
            'batch_id' => $batchId,
            'rows' => [
                $this->row((string) Str::uuid(), 'CHEAP', 'buy', 1, 10),
                $this->row((string) Str::uuid(), 'EXPENSIVE', 'buy', 1, 100),
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertEqualsWithDelta(50.0, app(CashManagementService::class)->balance($profile), 0.001);
    }

    public function test_successful_batch_cannot_be_submitted_again_as_new_economics(): void
    {
        [$user, $profile] = $this->makeUserWithCash();
        $batchId = (string) Str::uuid();
        $rows = [
            $this->row((string) Str::uuid(), 'AAA', 'buy', 1, 10),
            $this->row((string) Str::uuid(), 'BBB', 'buy', 1, 10),
        ];

        $this->actingAs($user)->postJson('/api/transactions/bulk', [
            'batch_id' => $batchId,
            'rows' => $rows,
        ])->assertCreated();

        $retry = $this->actingAs($user)->postJson('/api/transactions/bulk', [
            'batch_id' => $batchId,
            'rows' => $rows,
        ]);

        $retry->assertOk()
            ->assertJsonPath('status', 'already_committed')
            ->assertJsonPath('row_count', 2);

        $this->assertSame(2, Transaction::query()->where('profile_id', $profile->id)->count());
    }

    public function test_failed_batch_can_be_retried_safely(): void
    {
        [$user, $profile] = $this->makeUserWithCash(50);
        $batchId = (string) Str::uuid();
        $rowA = (string) Str::uuid();
        $rowB = (string) Str::uuid();

        $this->actingAs($user)->postJson('/api/transactions/bulk', [
            'batch_id' => $batchId,
            'rows' => [
                $this->row($rowA, 'AAA', 'buy', 1, 10),
                $this->row($rowB, 'BBB', 'buy', 1, 100),
            ],
        ])->assertStatus(422);

        app(CashManagementService::class)->deposit($profile, 10_000, 'top up', $user);

        $retry = $this->actingAs($user)->postJson('/api/transactions/bulk', [
            'batch_id' => $batchId,
            'rows' => [
                $this->row($rowA, 'AAA', 'buy', 1, 10),
                $this->row($rowB, 'BBB', 'buy', 1, 100),
            ],
        ]);

        $retry->assertCreated()->assertJsonPath('status', 'committed');
        $this->assertSame(2, Transaction::query()->where('profile_id', $profile->id)->count());
    }

    public function test_identical_economic_fields_are_not_deduplicated(): void
    {
        [$user, $profile] = $this->makeUserWithCash();
        $batchId = (string) Str::uuid();

        $this->actingAs($user)->postJson('/api/transactions/bulk', [
            'batch_id' => $batchId,
            'rows' => [
                $this->row((string) Str::uuid(), 'DUP', 'buy', 1, 10),
                $this->row((string) Str::uuid(), 'DUP', 'buy', 1, 10),
            ],
        ])->assertCreated()->assertJsonPath('row_count', 2);

        $this->assertSame(2, Transaction::query()->where('profile_id', $profile->id)->count());
    }

    public function test_row_order_and_per_row_dates_are_preserved(): void
    {
        [$user] = $this->makeUserWithCash();
        $batchId = (string) Str::uuid();
        $d1 = now()->subDays(2)->toDateString();
        $d2 = now()->subDays(1)->toDateString();

        $response = $this->actingAs($user)->postJson('/api/transactions/bulk', [
            'batch_id' => $batchId,
            'rows' => [
                $this->row((string) Str::uuid(), 'ORD1', 'buy', 1, 10, $d1),
                $this->row((string) Str::uuid(), 'ORD2', 'buy', 1, 11, $d2),
            ],
        ]);

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertSame('ORD1', $data[0]['stock']['symbol']);
        $this->assertSame('ORD2', $data[1]['stock']['symbol']);
        $this->assertSame($d1, substr($data[0]['transaction_date'], 0, 10));
        $this->assertSame($d2, substr($data[1]['transaction_date'], 0, 10));
    }

    public function test_buy_then_sell_in_same_batch_respects_order(): void
    {
        [$user, $profile] = $this->makeUserWithCash();
        $batchId = (string) Str::uuid();

        $this->actingAs($user)->postJson('/api/transactions/bulk', [
            'batch_id' => $batchId,
            'rows' => [
                $this->row((string) Str::uuid(), 'FLOW', 'buy', 5, 10),
                $this->row((string) Str::uuid(), 'FLOW', 'sell', 2, 12),
            ],
        ])->assertCreated();

        $this->assertSame(2, Transaction::query()->where('profile_id', $profile->id)->count());
    }

    public function test_foreign_profile_cannot_import_into_another_portfolio(): void
    {
        [$userA] = $this->makeUserWithCash();
        [$userB, $profileB] = $this->makeUserWithCash();

        $response = $this->actingAs($userA)
            ->withHeader('X-Profile-Id', (string) $profileB->id)
            ->postJson('/api/transactions/bulk', [
                'batch_id' => (string) Str::uuid(),
                'rows' => [
                    $this->row((string) Str::uuid(), 'ZZZ', 'buy', 1, 10),
                ],
            ]);

        $this->assertTrue(in_array($response->status(), [403, 404, 422], true));
        $this->assertSame(0, Transaction::query()->where('profile_id', $profileB->id)->count());
    }

    public function test_single_create_uses_transaction_write_service_and_rolls_back_cash_failure(): void
    {
        [$user, $profile] = $this->makeUserWithCash(5);

        $this->assertInstanceOf(TransactionWriteService::class, app(TransactionWriteService::class));

        $response = $this->actingAs($user)->postJson('/api/transactions', [
            'symbol' => 'CASHY',
            'exchange' => 'NSE',
            'type' => 'buy',
            'quantity' => 1,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertEqualsWithDelta(5.0, app(CashManagementService::class)->balance($profile), 0.001);
    }

    public function test_single_create_succeeds_with_sufficient_cash(): void
    {
        [$user, $profile] = $this->makeUserWithCash(10_000);

        $response = $this->actingAs($user)->postJson('/api/transactions', [
            'symbol' => 'OKBUY',
            'exchange' => 'NSE',
            'type' => 'buy',
            'quantity' => 2,
            'price' => 50,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertSame(1, Transaction::query()->where('profile_id', $profile->id)->count());
    }

    public function test_new_batch_may_repeat_same_economic_data(): void
    {
        [$user, $profile] = $this->makeUserWithCash();

        $this->actingAs($user)->postJson('/api/transactions/bulk', [
            'batch_id' => (string) Str::uuid(),
            'rows' => [
                $this->row((string) Str::uuid(), 'REP', 'buy', 1, 10),
            ],
        ])->assertCreated();

        $this->actingAs($user)->postJson('/api/transactions/bulk', [
            'batch_id' => (string) Str::uuid(),
            'rows' => [
                $this->row((string) Str::uuid(), 'REP', 'buy', 1, 10),
            ],
        ])->assertCreated();

        $this->assertSame(2, Transaction::query()->where('profile_id', $profile->id)->count());
    }
}
