<?php

namespace Tests\Feature;

use App\Models\KiteCncTradeImport;
use App\Models\KiteCncTradeSyncRun;
use App\Models\CalendarEvent;
use App\Models\BrokerConnection;
use App\Models\PortfolioProfile;
use App\Models\PortfolioReconciliationRun;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Broker\FakeBrokerGateway;
use App\Services\Reconciliation\KiteCncTradeSyncService;
use App\Services\Reconciliation\PortfolioReconciliationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KiteCncTradeSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-20 16:10:00', 'Asia/Kolkata'));
        \App\Support\TradingCalendar::clearHolidayCache();
        app(FakeBrokerGateway::class)->reset();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        \App\Support\TradingCalendar::clearHolidayCache();
        parent::tearDown();
    }

    public function test_imports_only_cnc_fills_with_ids_and_engine_fees_idempotently(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [
            $this->fill(98701, 'B-17', 'BUY', 2, 101.25),
            $this->fill(98702, 'B-17', 'BUY', 3, 101.50),
            [...$this->fill(98703, 'M-1', 'BUY', 100, 1), 'product' => 'MIS'],
            [...$this->fill(98704, 'F-1', 'BUY', 1, 100), 'exchange' => 'NFO'],
            [...$this->fill(98705, 'M-2', 'SELL', 1, 100), 'product' => 'MTF'],
        ];

        $first = app(KiteCncTradeSyncService::class)->sync($profile);
        $this->assertSame(2, $first['imported']);
        $this->assertSame(0, $first['review']);
        $this->assertSame(2, $profile->transactions()->count());
        $this->assertSame(5.0, (float) $profile->transactions()->sum('quantity'));
        $this->assertGreaterThan(0, (float) $profile->transactions()->first()->fees);
        $this->assertDatabaseHas('kite_cnc_trade_imports', ['broker_trade_id' => '98701', 'broker_order_id' => 'B-17', 'status' => 'imported']);

        $fake->tradeRows = [$this->fill(98701, 'B-17', 'BUY', 99, 1), $this->fill(98702, 'B-17', 'BUY', 99, 1)];
        $second = app(KiteCncTradeSyncService::class)->sync($profile);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(2, $profile->transactions()->count());
        $this->assertSame(2, KiteCncTradeImport::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(2.0, (float) KiteCncTradeImport::query()->where('broker_trade_id', '98701')->value('quantity'));
        $this->assertSame(101.25, (float) KiteCncTradeImport::query()->where('broker_trade_id', '98701')->value('price'));
    }

    public function test_matches_aggregate_manual_transaction_and_flags_ambiguous_manual_rows(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $manual = Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 5, 'price' => 101.4, 'fees' => 2, 'transaction_date' => '2026-11-20', 'source' => Transaction::SOURCE_MANUAL]);
        app(FakeBrokerGateway::class)->tradeRows = [
            $this->fill(98711, 'B-19', 'BUY', 2, 101.25),
            $this->fill(98712, 'B-19', 'BUY', 3, 101.50),
        ];
        $result = app(KiteCncTradeSyncService::class)->sync($profile);
        $this->assertSame(0, $result['imported']);
        $this->assertSame(2, $result['matched']);
        $this->assertSame(1, $profile->transactions()->count());
        $this->assertSame(2, KiteCncTradeImport::query()->where('status', 'matched_manual')->count());
        $this->assertSame($manual->id, (int) KiteCncTradeImport::query()->first()->matched_transaction_id);
    }

    public function test_duplicate_manual_transactions_are_reviewed_without_double_importing(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        foreach ([1, 2] as $_) {
            Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
                'quantity' => 5, 'price' => 100, 'fees' => 2, 'transaction_date' => '2026-11-20',
                'source' => Transaction::SOURCE_MANUAL]);
        }
        app(FakeBrokerGateway::class)->tradeRows = [$this->fill(98713, 'B-19', 'BUY', 5, 100)];

        $result = app(KiteCncTradeSyncService::class)->sync($profile);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['review']);
        $this->assertSame(2, $profile->transactions()->count());
        $this->assertDatabaseHas('kite_cnc_trade_imports', [
            'broker_trade_id' => '98713', 'status' => 'manual_match_review', 'matched_transaction_id' => null,
        ]);
    }

    public function test_matching_partial_manual_rows_links_each_fill_without_importing_again(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $first = Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 2, 'price' => 101.25, 'fees' => 1, 'transaction_date' => '2026-11-20', 'source' => Transaction::SOURCE_MANUAL]);
        $second = Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
            'quantity' => 3, 'price' => 101.50, 'fees' => 1, 'transaction_date' => '2026-11-20', 'source' => Transaction::SOURCE_MANUAL]);
        app(FakeBrokerGateway::class)->tradeRows = [
            $this->fill(98728, 'B-26', 'BUY', 2, 101.25), $this->fill(98729, 'B-26', 'BUY', 3, 101.50),
        ];

        $result = app(KiteCncTradeSyncService::class)->sync($profile);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(2, $result['matched']);
        $this->assertSame(0, $result['review']);
        $this->assertSame($first->id, (int) KiteCncTradeImport::query()->where('broker_trade_id', '98728')->value('matched_transaction_id'));
        $this->assertSame($second->id, (int) KiteCncTradeImport::query()->where('broker_trade_id', '98729')->value('matched_transaction_id'));
        $this->assertSame(2, $profile->transactions()->count());
    }

    public function test_settlement_reconciliation_adds_only_execution_proven_t1_delta_and_uses_trading_days(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [$this->fill(98721, 'B-20', 'BUY', 5, 100)];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $localHolding = $profile->holdings()->where('stock_id', $stock->id)->firstOrFail();
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 0, 't1_quantity' => 0,
            'used_quantity' => 0, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];
        $run = app(PortfolioReconciliationService::class)->run($profile, 'manual');
        $adjustments = $run->broker_snapshot['settlement_adjustments'];
        $this->assertSame(1, count($adjustments));
        $this->assertSame(5.0, (float) $adjustments[0]['quantity_delta']);
        $this->assertSame('2026-11-23', $adjustments[0]['settles_by']);
        $this->assertSame((float) $localHolding->quantity, (float) $run->broker_snapshot['comparison_holdings'][0]['quantity']);
        $this->assertSame('reconciled', $run->holdings_status);
    }

    public function test_partial_broker_reflection_is_left_as_a_holdings_discrepancy(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [$this->fill(98722, 'B-21', 'BUY', 5, 100)];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 2, 't1_quantity' => 0,
            'used_quantity' => 0, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];

        $run = app(PortfolioReconciliationService::class)->run($profile, 'manual');

        $this->assertSame([], $run->broker_snapshot['settlement_adjustments']);
        $this->assertSame('mismatch', $run->holdings_status);
        $this->assertSame(5.0, (float) $run->discrepancies['holdings'][0]['stoxQty']);
        $this->assertSame(2.0, (float) $run->discrepancies['holdings'][0]['brokerQty']);
    }

    public function test_unsettled_sale_is_adjusted_only_when_it_exactly_explains_broker_gap(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        app(\App\Services\TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy', 'quantity' => 10, 'price' => 100, 'transaction_date' => '2026-11-19',
            'source' => Transaction::SOURCE_MANUAL,
        ]);
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [$this->fill(98723, 'S-22', 'SELL', 4, 110)];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 10, 't1_quantity' => 0,
            'used_quantity' => 0, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];

        $run = app(PortfolioReconciliationService::class)->run($profile, 'manual');

        $this->assertSame(-4.0, (float) $run->broker_snapshot['settlement_adjustments'][0]['quantity_delta']);
        $this->assertSame(6.0, (float) $run->broker_snapshot['comparison_holdings'][0]['quantity']);
        $this->assertSame('reconciled', $run->holdings_status);
    }

    public function test_unsettled_sale_does_not_hide_a_different_broker_gap(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        app(\App\Services\TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy', 'quantity' => 10, 'price' => 100, 'transaction_date' => '2026-11-19',
            'source' => Transaction::SOURCE_MANUAL,
        ]);
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [$this->fill(98734, 'S-24', 'SELL', 4, 110)];
        app(KiteCncTradeSyncService::class)->sync($profile);
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 9, 't1_quantity' => 0,
            'used_quantity' => 0, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];

        $run = app(PortfolioReconciliationService::class)->run($profile, 'manual');

        $this->assertSame([], $run->broker_snapshot['settlement_adjustments']);
        $this->assertSame(9.0, (float) $run->broker_snapshot['comparison_holdings'][0]['quantity']);
        $this->assertSame('mismatch', $run->holdings_status);
        $this->assertSame(6.0, (float) $run->discrepancies['holdings'][0]['stoxQty']);
        $this->assertSame(9.0, (float) $run->discrepancies['holdings'][0]['brokerQty']);
    }

    public function test_offsetting_same_day_buy_sell_uses_t1_and_used_quantity_without_adjustment(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        app(\App\Services\TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy', 'quantity' => 10, 'price' => 100, 'transaction_date' => '2026-11-19',
            'source' => Transaction::SOURCE_MANUAL,
        ]);
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [
            $this->fill(98724, 'S-23', 'SELL', 5, 110),
            $this->fill(98725, 'B-24', 'BUY', 5, 105),
        ];
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 10, 't1_quantity' => 5,
            'used_quantity' => 5, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];

        $run = app(PortfolioReconciliationService::class)->run($profile, 'manual');

        $this->assertSame([], $run->broker_snapshot['settlement_adjustments']);
        $this->assertSame(10.0, (float) $run->broker_snapshot['comparison_holdings'][0]['quantity']);
        $this->assertSame('reconciled', $run->holdings_status);
    }

    public function test_pending_imports_across_a_trading_holiday_are_netted_by_execution_evidence(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        CalendarEvent::query()->create(['profile_id' => null, 'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Test holiday', 'anchor_date' => '2026-11-23', 'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'is_active' => true]);
        \App\Support\TradingCalendar::clearHolidayCache();
        Carbon::setTestNow(Carbon::parse('2026-11-24 16:10:00', 'Asia/Kolkata'));
        app(\App\Services\TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy', 'quantity' => 2, 'price' => 100, 'transaction_date' => '2026-11-20', 'source' => Transaction::SOURCE_OTHER,
        ]);
        $pastRun = KiteCncTradeSyncRun::query()->create(['profile_id' => $profile->id, 'user_id' => $profile->user_id,
            'trading_date' => '2026-11-20', 'trigger' => 'scheduled', 'status' => 'completed',
            'started_at' => '2026-11-20 16:00:00', 'completed_at' => '2026-11-20 16:01:00']);
        KiteCncTradeImport::query()->create(['profile_id' => $profile->id, 'sync_run_id' => $pastRun->id,
            'stock_id' => $stock->id, 'broker_trade_id' => '98726',
            'symbol' => 'AAA', 'exchange' => 'NSE', 'side' => 'buy', 'quantity' => 2, 'price' => 100,
            'executed_at' => '2026-11-20 15:20:00', 'product' => 'CNC', 'status' => 'imported', 'broker_evidence' => ['trade_id' => '98726']]);
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [[...$this->fill(98727, 'B-25', 'BUY', 3, 100), 'exchange_timestamp' => '2026-11-24 15:20:00']];
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 20, 't1_quantity' => 0,
            'used_quantity' => 0, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];
        // The established base position is 20; Friday and Tuesday fills add five
        // while the Monday holiday delays Friday's T+1 settlement until Tuesday.
        app(\App\Services\TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy', 'quantity' => 20, 'price' => 100, 'transaction_date' => '2026-11-19', 'source' => Transaction::SOURCE_MANUAL,
        ]);
        app(KiteCncTradeSyncService::class)->sync($profile);

        $run = app(PortfolioReconciliationService::class)->run($profile, 'manual');

        $this->assertSame(5.0, (float) $run->broker_snapshot['settlement_adjustments'][0]['quantity_delta']);
        $this->assertSame('2026-11-25', $run->broker_snapshot['settlement_adjustments'][0]['settles_by']);
        $this->assertSame(25.0, (float) $run->broker_snapshot['comparison_holdings'][0]['quantity']);
        $this->assertSame('reconciled', $run->holdings_status);
    }

    public function test_reconnect_records_each_unavailable_session_once_after_tradebook_gap(): void
    {
        [$profile] = $this->portfolioAndStock();
        KiteCncTradeSyncRun::query()->create(['profile_id' => $profile->id, 'user_id' => $profile->user_id,
            'trading_date' => '2026-11-24', 'trigger' => 'scheduled', 'status' => 'completed',
            'started_at' => '2026-11-24 16:00:00', 'completed_at' => '2026-11-24 16:01:00']);
        Carbon::setTestNow(Carbon::parse('2026-11-27 16:10:00', 'Asia/Kolkata'));
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [];

        app(KiteCncTradeSyncService::class)->sync($profile, 'reconnect');
        app(KiteCncTradeSyncService::class)->sync($profile, 'reconnect');

        $gaps = KiteCncTradeSyncRun::query()->where('profile_id', $profile->id)->where('status', 'gap_unavailable')->orderBy('trading_date')->get();
        $this->assertSame(['2026-11-25', '2026-11-26'], $gaps->map(fn ($run) => $run->trading_date->toDateString())->all());
    }

    public function test_missing_tradebook_is_recorded_as_failed_and_surfaces_error(): void
    {
        [$profile] = $this->portfolioAndStock();
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRowsUnavailable = true;
        try {
            app(KiteCncTradeSyncService::class)->sync($profile);
            $this->fail('Expected incomplete Kite trade sync.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('current-day trades are unavailable', $error->getMessage());
        }
        $this->assertDatabaseHas('kite_cnc_trade_sync_runs', ['profile_id' => $profile->id, 'status' => 'sync_failed']);
    }

    public function test_order_timestamp_alone_is_not_execution_evidence(): void
    {
        [$profile] = $this->portfolioAndStock();
        app(FakeBrokerGateway::class)->tradeRows = [[
            ...$this->fill(98730, 'B-30', 'BUY', 2, 100),
            'fill_timestamp' => null,
            'exchange_timestamp' => null,
            'order_timestamp' => '2026-11-20 15:20:00',
        ]];

        $result = app(KiteCncTradeSyncService::class)->sync($profile);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['review']);
        $this->assertSame(0, $profile->transactions()->count());
        $this->assertDatabaseHas('kite_cnc_trade_sync_runs', [
            'profile_id' => $profile->id,
            'eligible_count' => 0,
            'review_count' => 1,
            'status' => 'needs_review',
        ]);
    }

    public function test_zero_and_negative_cnc_fills_are_reviewed_without_ledger_or_cash_writes(): void
    {
        [$profile] = $this->portfolioAndStock();
        app(FakeBrokerGateway::class)->tradeRows = [
            $this->fill(98731, 'B-31', 'BUY', 0, 100),
            $this->fill(98732, 'B-32', 'SELL', -2, 100),
        ];
        $cashBefore = app(\App\Services\CashManagementService::class)->balance($profile);

        $result = app(KiteCncTradeSyncService::class)->sync($profile);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(2, $result['review']);
        $this->assertSame(0, $profile->transactions()->count());
        $this->assertEquals($cashBefore, app(\App\Services\CashManagementService::class)->balance($profile));
        $this->assertSame(0, KiteCncTradeImport::query()->count());
    }

    public function test_sync_isolated_by_profile_and_paused_when_one_kite_user_has_multiple_live_profiles(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        $otherUser = User::factory()->create();
        $otherProfile = $this->defaultPortfolioFor($otherUser);
        $otherProfile->forceFill(['portfolio_type' => PortfolioProfile::TYPE_LIVE,
            'execution_mode' => PortfolioProfile::EXECUTION_MODE_AUTOMATIC])->save();
        BrokerConnection::query()->create([
            'user_id' => $otherUser->id, 'provider' => BrokerConnection::PROVIDER_KITE,
            'access_token' => 'other-test-token', 'connected_at' => now(), 'expires_at' => now()->addDay(),
            'broker_user_id' => 'OTHER1',
        ]);
        $fake = app(FakeBrokerGateway::class);
        $fake->tradeRows = [$this->fill(98733, 'B-33', 'BUY', 2, 100)];

        app(KiteCncTradeSyncService::class)->sync($profile);
        $this->assertSame(1, $profile->transactions()->count());
        $this->assertSame(0, $otherProfile->transactions()->count());
        $this->assertSame(1, KiteCncTradeImport::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(0, KiteCncTradeImport::query()->where('profile_id', $otherProfile->id)->count());

        $secondProfile = PortfolioProfile::query()->create([
            'user_id' => $profile->user_id, 'name' => 'Second Live Portfolio', 'is_default' => false,
            'portfolio_type' => PortfolioProfile::TYPE_LIVE,
        ]);
        // execution_mode is intentionally not mass assignable on this model.
        $secondProfile->forceFill(['execution_mode' => PortfolioProfile::EXECUTION_MODE_AUTOMATIC])->save();
        $this->assertSame(2, PortfolioProfile::query()->where('user_id', $profile->user_id)
            ->where('portfolio_type', PortfolioProfile::TYPE_LIVE)
            ->whereIn('execution_mode', [PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC, PortfolioProfile::EXECUTION_MODE_AUTOMATIC])
            ->count());
        try {
            app(KiteCncTradeSyncService::class)->sync($profile);
            $this->fail('A shared Kite account with ambiguous profile ownership must be paused.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('multiple live portfolios', $error->getMessage());
        }
        $this->assertSame(0, $secondProfile->transactions()->count());
    }

    public function test_expired_fill_is_not_reintroduced_when_a_later_fill_is_pending(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        Carbon::setTestNow(Carbon::parse('2026-11-24 16:10:00', 'Asia/Kolkata'));
        foreach ([['2026-11-19', 10], ['2026-11-20', 2], ['2026-11-24', 3]] as [$date, $quantity]) {
            app(\App\Services\TransactionWriteService::class)->create($profile, $stock, [
                'type' => 'buy', 'quantity' => $quantity, 'price' => 100, 'transaction_date' => $date,
                'source' => Transaction::SOURCE_OTHER,
            ]);
        }
        $run = KiteCncTradeSyncRun::query()->create(['profile_id' => $profile->id, 'user_id' => $profile->user_id,
            'trading_date' => '2026-11-24', 'trigger' => 'scheduled', 'status' => 'completed',
            'started_at' => now(), 'completed_at' => now()]);
        foreach ([['old', '2026-11-20 15:00:00', 2], ['new', '2026-11-24 15:00:00', 3]] as [$id, $executedAt, $quantity]) {
            KiteCncTradeImport::query()->create(['profile_id' => $profile->id, 'sync_run_id' => $run->id,
                'stock_id' => $stock->id, 'broker_trade_id' => $id, 'symbol' => 'AAA', 'exchange' => 'NSE',
                'side' => 'buy', 'quantity' => $quantity, 'price' => 100, 'executed_at' => $executedAt,
                'product' => 'CNC', 'status' => 'imported', 'broker_evidence' => ['trade_id' => $id]]);
        }
        $fake = app(FakeBrokerGateway::class);
        $fake->reconciliationSnapshot = ['provider' => 'kite', 'holdings' => [[
            'symbol' => 'AAA', 'exchange' => 'NSE', 'isin' => null, 'quantity' => 12, 't1_quantity' => 0,
            'used_quantity' => 0, 'cost' => null,
        ]], 'positions' => ['net' => [], 'day' => [], 'complete' => true], 'current_cash' => 0];

        $reconciliation = app(PortfolioReconciliationService::class)->run($profile, 'manual');

        $this->assertCount(1, $reconciliation->broker_snapshot['settlement_adjustments']);
        $this->assertSame(['2026-11-24'], $reconciliation->broker_snapshot['settlement_adjustments'][0]['trade_dates']);
        $this->assertSame(3.0, (float) $reconciliation->broker_snapshot['settlement_adjustments'][0]['quantity_delta']);
        $this->assertSame('reconciled', $reconciliation->holdings_status);
    }

    private function portfolioAndStock(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $profile->forceFill(['portfolio_type' => PortfolioProfile::TYPE_LIVE,
            'execution_mode' => PortfolioProfile::EXECUTION_MODE_AUTOMATIC])->save();
        BrokerConnection::query()->create([
            'user_id' => $user->id, 'provider' => BrokerConnection::PROVIDER_KITE,
            'access_token' => 'test-access-token', 'connected_at' => now(), 'expires_at' => now()->addDay(),
            'broker_user_id' => 'AB1234',
        ]);
        app(\App\Services\CashManagementService::class)->deposit($profile, 100000, 'Test opening cash', $user);
        $stock = Stock::query()->create(['symbol' => 'AAA', 'exchange' => 'NSE', 'name' => 'AAA', 'is_active' => true]);
        return [$profile, $stock];
    }

    private function fill(int $id, string $orderId, string $side, int $quantity, float $price): array
    {
        return ['trade_id' => $id, 'order_id' => $orderId, 'tradingsymbol' => 'AAA', 'exchange' => 'NSE',
            'transaction_type' => $side, 'product' => 'CNC', 'quantity' => $quantity, 'price' => $price,
            'exchange_timestamp' => '2026-11-20 15:20:00'];
    }
}
