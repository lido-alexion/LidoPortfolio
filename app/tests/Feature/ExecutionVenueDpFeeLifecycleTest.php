<?php

namespace Tests\Feature;

use App\Models\CashLedgerEntry;
use App\Models\PortfolioProfile;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\StockValidationService;
use App\Services\TransactionWriteService;
use App\Support\StockValidationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExecutionVenueDpFeeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(StockValidationService::class, function ($mock) {
            $mock->shouldReceive('validateAndPersist')->andReturnUsing(function (string $symbol, ?string $exchange) {
                $stock = Stock::query()->firstOrCreate(
                    ['symbol' => strtoupper($symbol), 'exchange' => $exchange ?? 'NSE'],
                    ['name' => $symbol, 'isin' => 'INE000A01010', 'is_active' => true, 'is_benchmark' => false],
                );
                return StockValidationResult::valid($stock, 'test');
            });
            $mock->shouldReceive('validate')->andReturnUsing(function (string $symbol, ?string $exchange) {
                $stock = Stock::query()->where('symbol', strtoupper($symbol))->where('exchange', $exchange ?? 'NSE')->first();
                return $stock ? StockValidationResult::valid($stock, 'test') : StockValidationResult::invalid(['Not found']);
            });
        });
    }

    private function account(string $label = 'DP'): array
    {
        $user = User::query()->create([
            'name' => $label,
            'email' => strtolower($label).'-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, 1_000_000, 'seed', $user);
        return [$user, $profile];
    }

    private function stock(string $symbol, string $isin = 'INE000A01010', string $exchange = 'NSE'): Stock
    {
        return Stock::query()->create([
            'symbol' => $symbol, 'exchange' => $exchange, 'name' => $symbol, 'isin' => $isin,
            'is_active' => true, 'is_benchmark' => false, 'is_dual_listed' => true,
        ]);
    }

    private function trade(PortfolioProfile $profile, Stock $stock, string $type, float $qty, string $date, array $extra = [], bool $applyCash = true): Transaction
    {
        return app(TransactionWriteService::class)->create(
            $profile,
            $stock,
            ['type' => $type, 'quantity' => $qty, 'price' => 100, 'fees' => 0, 'transaction_date' => $date, ...$extra],
            applyCash: $applyCash,
        );
    }

    private function assertCashLedgerMatchesAccount(PortfolioProfile $profile): void
    {
        $sum = (float) CashLedgerEntry::query()->where('profile_id', $profile->id)->sum('amount');
        $this->assertEqualsWithDelta($sum, app(CashManagementService::class)->balance($profile), 0.0001);
    }

    public function test_dp_reallocates_once_across_same_day_and_new_day_groups_and_owner_lifecycle(): void
    {
        [$user, $profile] = $this->account();
        $stock = $this->stock('DPONE');
        $day = '2026-11-17';
        $this->trade($profile, $stock, 'buy', 10, $day);

        $first = $this->trade($profile, $stock, 'sell', 2, $day);
        $this->assertEqualsWithDelta(15.34, (float) $first->fresh()->dp_fee_amount, 0.0001);

        $second = $this->trade($profile, $stock, 'sell', 2, $day);
        $sameDay = Transaction::query()->where('profile_id', $profile->id)->where('type', 'sell')->whereDate('transaction_date', $day)->get();
        $this->assertEqualsWithDelta(15.34, (float) $sameDay->sum('dp_fee_amount'), 0.0001);
        $this->assertEquals(1, $sameDay->filter(fn (Transaction $tx) => (float) $tx->dp_fee_amount > 0)->count());

        $otherDay = $this->trade($profile, $stock, 'sell', 2, '2026-11-18');
        $this->assertEqualsWithDelta(30.68, (float) Transaction::query()->where('profile_id', $profile->id)->sum('dp_fee_amount'), 0.0001);

        app(TransactionWriteService::class)->update($profile, $second->fresh(), $stock, [
            'type' => 'sell', 'quantity' => 3, 'price' => 100, 'fees' => 0,
            'transaction_date' => '2026-11-18', 'execution_exchange' => 'BSE',
        ], user: $user);
        $this->assertEqualsWithDelta(30.68, (float) Transaction::query()->where('profile_id', $profile->id)->sum('dp_fee_amount'), 0.0001);
        $this->assertEquals(1, Transaction::query()->where('profile_id', $profile->id)->whereDate('transaction_date', '2026-11-18')->where('dp_fee_amount', '>', 0)->count());

        app(TransactionWriteService::class)->delete($profile, $otherDay->fresh(), $user);
        $this->assertEqualsWithDelta(15.34, (float) $second->fresh()->dp_fee_amount, 0.0001);
        $this->assertEqualsWithDelta(30.68, (float) Transaction::query()->where('profile_id', $profile->id)->sum('dp_fee_amount'), 0.0001);

        app(TransactionWriteService::class)->delete($profile, $second->fresh(), $user);
        $this->assertEqualsWithDelta(15.34, (float) Transaction::query()->where('profile_id', $profile->id)->sum('dp_fee_amount'), 0.0001);
        $this->assertSame(3, CashLedgerEntry::query()->where('profile_id', $profile->id)
            ->where('entry_type', CashLedgerEntry::TYPE_ADJUSTMENT)
            ->where('reason', 'like', 'DP fee allocation adjustment%')->count());
        $this->assertCashLedgerMatchesAccount($profile);
    }

    public function test_dp_groups_are_isolated_by_profile_and_buy_rows_never_get_dp(): void
    {
        [$userA, $profileA] = $this->account('ProfileA');
        [$userB, $profileB] = $this->account('ProfileB');
        $stock = $this->stock('DPTWO');
        $date = '2026-11-17';
        $buy = $this->trade($profileA, $stock, 'buy', 5, $date);
        $sellA = $this->trade($profileA, $stock, 'sell', 1, $date);
        $this->trade($profileB, $stock, 'buy', 5, $date);
        $sellB = $this->trade($profileB, $stock, 'sell', 1, $date);

        $this->assertSame('0.0000', $buy->fresh()->dp_fee_amount);
        $this->assertEqualsWithDelta(15.34, (float) $sellA->fresh()->dp_fee_amount, 0.0001);
        $this->assertEqualsWithDelta(15.34, (float) $sellB->fresh()->dp_fee_amount, 0.0001);
        $this->assertCashLedgerMatchesAccount($profileA);
        $this->assertCashLedgerMatchesAccount($profileB);
    }

    public function test_new_cash_trades_join_grouped_fee_policy_but_legacy_rows_stay_untouched(): void
    {
        [$user, $profile] = $this->account('GroupedFees');
        $stock = $this->stock('GROUPED');
        $legacy = Transaction::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 1,
            'price' => 500, 'fees' => 7.25, 'transaction_date' => '2026-11-18', 'source' => 'manual',
            'execution_exchange' => 'NSE', 'dp_policy_enrolled' => false, 'grouped_fee_policy_enrolled' => false,
        ]);

        $first = $this->trade($profile, $stock, 'buy', 1, '2026-11-18', ['price' => 82500]);
        $legacy->refresh();
        $legacyFee = (float) $legacy->fees;
        $this->assertFalse((bool) $legacy->grouped_fee_policy_enrolled);
        $this->assertTrue((bool) $first->fresh()->grouped_fee_policy_enrolled);

        $second = $this->trade($profile, $stock, 'buy', 1, '2026-11-18', ['price' => 83700]);
        $this->assertEqualsWithDelta($legacyFee, (float) $legacy->fresh()->fees, 0.0001);
        $this->assertTrue((bool) $second->fresh()->grouped_fee_policy_enrolled);
        $this->assertCashLedgerMatchesAccount($profile);

        app(TransactionWriteService::class)->update($profile, $first->fresh(), $stock, [
            'type' => 'buy', 'quantity' => 1, 'price' => 82500, 'fees' => 0, 'transaction_date' => '2026-11-19',
        ], user: $user);
        $this->assertCashLedgerMatchesAccount($profile);

        app(TransactionWriteService::class)->delete($profile, $second->fresh(), $user);
        $this->assertCashLedgerMatchesAccount($profile);
        $this->assertEqualsWithDelta($legacyFee, (float) $legacy->fresh()->fees, 0.0001);
    }

    public function test_legacy_fees_remain_unchanged_when_a_new_sell_joins_the_group(): void
    {
        [, $profile] = $this->account('LegacyFee');
        $stock = $this->stock('LEGACYDP');
        $this->trade($profile, $stock, 'buy', 5, '2026-11-17');
        $legacy = $this->trade($profile, $stock, 'sell', 1, '2026-11-17');
        $legacy->forceFill(['fees' => 7.25, 'dp_fee_amount' => 0, 'dp_policy_enrolled' => false, 'grouped_fee_policy_enrolled' => false, 'created_at' => now()])->save();

        $newSell = $this->trade($profile, $stock, 'sell', 1, '2026-11-17');

        $this->assertEqualsWithDelta(7.25, (float) $legacy->fresh()->fees, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $legacy->fresh()->dp_fee_amount, 0.0001);
        $this->assertEqualsWithDelta(15.34, (float) $newSell->fresh()->dp_fee_amount, 0.0001);
    }

    public function test_simulated_and_cashless_sells_do_not_create_dp_or_change_live_cash(): void
    {
        [$user, $profile] = $this->account('SafeCash');
        $stock = $this->stock('DPSAFE');
        $this->trade($profile, $stock, 'buy', 5, '2026-11-17');
        $cashBefore = app(CashManagementService::class)->balance($profile);
        $ledgerCount = CashLedgerEntry::query()->where('profile_id', $profile->id)->count();

        $cashless = $this->trade($profile, $stock, 'sell', 1, '2026-11-17', [], applyCash: false);
        $this->assertEqualsWithDelta(0, (float) $cashless->fresh()->dp_fee_amount, 0.0001);
        $this->assertSame($ledgerCount, CashLedgerEntry::query()->where('profile_id', $profile->id)->count());
        $this->assertEqualsWithDelta($cashBefore, app(CashManagementService::class)->balance($profile), 0.0001);
        $normalSell = $this->trade($profile, $stock, 'sell', 1, '2026-11-17');
        $this->assertEqualsWithDelta(0, (float) $cashless->fresh()->dp_fee_amount, 0.0001);
        $this->assertEqualsWithDelta(15.34, (float) $normalSell->fresh()->dp_fee_amount, 0.0001);
        $this->assertCashLedgerMatchesAccount($profile);
        $cashAfterNormalSell = app(CashManagementService::class)->balance($profile);
        $cashlessEditRejected = false;
        try {
            app(TransactionWriteService::class)->updateFinancialUnit($profile, $normalSell->fresh(), $stock, [
                'type' => 'sell', 'quantity' => 1, 'price' => 100, 'fees' => 0, 'transaction_date' => '2026-11-17',
            ], applyCash: false);
        } catch (ValidationException) {
            $cashlessEditRejected = true;
        }
        $this->assertTrue($cashlessEditRejected);
        $this->assertEqualsWithDelta($cashAfterNormalSell, app(CashManagementService::class)->balance($profile), 0.0001);

        $paper = PortfolioProfile::query()->create([
            'user_id' => $user->id, 'name' => 'Paper', 'is_default' => false, 'portfolio_type' => PortfolioProfile::TYPE_PAPER,
        ]);
        app(CashManagementService::class)->deposit($paper, 100_000, 'paper seed', $user);
        $paperBuy = $this->trade($paper, $stock, 'buy', 2, '2026-11-17', ['simulation_origin' => 'strategy_simulation']);
        $paperSell = $this->trade($paper, $stock, 'sell', 1, '2026-11-17', ['simulation_origin' => 'strategy_simulation']);
        $this->assertEqualsWithDelta(0, (float) $paperBuy->fresh()->dp_fee_amount, 0.0001);
        $this->assertEqualsWithDelta(0, (float) $paperSell->fresh()->dp_fee_amount, 0.0001);

        $this->expectException(ValidationException::class);
        $this->trade($profile, $stock, 'sell', 1, '2026-11-18', ['simulation_origin' => 'investor_intervention']);
    }

    public function test_api_persists_edited_execution_venue_and_reprices_exchange_components(): void
    {
        [$user, $profile] = $this->account('Venue');
        $stock = $this->stock('VENUE');
        Setting::setValue('fee_components', json_encode([
            ['id' => 'nse_fee', 'label' => 'NSE', 'value' => '0.10', 'mode' => 'percentage', 'applies_buy' => true, 'applies_sell' => true, 'exchange' => 'NSE', 'gst_percent' => '0'],
            ['id' => 'bse_fee', 'label' => 'BSE', 'value' => '0.25', 'mode' => 'percentage', 'applies_buy' => true, 'applies_sell' => true, 'exchange' => 'BSE', 'gst_percent' => '0'],
        ]));
        Cache::forget('setting.fee_components');

        $this->actingAs($user);
        $created = $this->postJson('/api/transactions', [
            'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 1, 'price' => 100,
            'fees' => 0, 'execution_exchange' => 'NSE', 'transaction_date' => '2026-11-17',
        ])->assertCreated()->json('data');
        $this->assertEqualsWithDelta(0.10, (float) $created['fees'], 0.0001);

        $updated = $this->putJson('/api/transactions/'.$created['id'], [
            'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 1, 'price' => 100,
            'fees' => 0, 'execution_exchange' => 'BSE', 'transaction_date' => '2026-11-17',
        ])->assertOk()->json('data');
        $this->assertSame('BSE', $updated['execution_exchange']);
        $this->assertEqualsWithDelta(0.25, (float) $updated['fees'], 0.0001);
        $this->assertCashLedgerMatchesAccount($profile);
    }

    public function test_bulk_preflight_counts_one_dp_fee_and_respects_existing_group_marker(): void
    {
        [$user, $profile] = $this->account('BulkDP');
        $stock = $this->stock('BULKDP');
        $this->trade($profile, $stock, 'buy', 10, '2026-11-17');
        $existing = $this->trade($profile, $stock, 'sell', 1, '2026-11-17');
        $this->assertEqualsWithDelta(15.34, (float) $existing->fresh()->dp_fee_amount, 0.0001);

        $response = $this->actingAs($user)->postJson('/api/transactions/bulk', [
            'batch_id' => (string) Str::uuid(),
            'rows' => [
                ['row_id' => (string) Str::uuid(), 'stock_id' => $stock->id, 'symbol' => $stock->symbol, 'exchange' => 'NSE', 'type' => 'sell', 'quantity' => 1, 'price' => 100, 'fees' => 0, 'transaction_date' => '2026-11-17'],
                ['row_id' => (string) Str::uuid(), 'stock_id' => $stock->id, 'symbol' => $stock->symbol, 'exchange' => 'NSE', 'type' => 'sell', 'quantity' => 1, 'price' => 100, 'fees' => 0, 'transaction_date' => '2026-11-17'],
                ['row_id' => (string) Str::uuid(), 'stock_id' => $stock->id, 'symbol' => $stock->symbol, 'exchange' => 'NSE', 'type' => 'sell', 'quantity' => 1, 'price' => 100, 'fees' => 0, 'transaction_date' => '2026-11-18'],
                ['row_id' => (string) Str::uuid(), 'stock_id' => $stock->id, 'symbol' => $stock->symbol, 'exchange' => 'NSE', 'type' => 'sell', 'quantity' => 1, 'price' => 100, 'fees' => 0, 'transaction_date' => '2026-11-18'],
            ],
        ])->assertCreated();

        $this->assertEqualsWithDelta(30.68, (float) Transaction::query()->where('profile_id', $profile->id)->sum('dp_fee_amount'), 0.0001);
        $this->assertSame(2, Transaction::query()->where('profile_id', $profile->id)->where('dp_fee_amount', '>', 0)->count());
        $this->assertCashLedgerMatchesAccount($profile);
    }
}
