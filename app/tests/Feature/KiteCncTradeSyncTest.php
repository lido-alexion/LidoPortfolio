<?php

namespace Tests\Feature;

use App\Models\KiteCncTradeImport;
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
        app(FakeBrokerGateway::class)->reset();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_imports_only_cnc_fills_with_ids_and_engine_fees_idempotently(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        app(FakeBrokerGateway::class)->tradeRows = [
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

        $second = app(KiteCncTradeSyncService::class)->sync($profile);
        $this->assertSame(0, $second['imported']);
        $this->assertSame(2, $profile->transactions()->count());
        $this->assertSame(2, KiteCncTradeImport::query()->where('profile_id', $profile->id)->count());
    }

    public function test_matches_aggregate_manual_transaction_and_flags_ambiguous_manual_rows(): void
    {
        [$profile, $stock] = $this->portfolioAndStock();
        Transaction::query()->create(['profile_id' => $profile->id, 'stock_id' => $stock->id, 'type' => 'buy',
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
        $this->assertSame(1, (int) KiteCncTradeImport::query()->first()->matched_transaction_id);
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

    private function portfolioAndStock(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $profile->forceFill(['execution_mode' => PortfolioProfile::EXECUTION_MODE_AUTOMATIC])->save();
        $stock = Stock::query()->create(['symbol' => 'AAA', 'exchange' => 'NSE', 'name' => 'AAA', 'is_active' => true]);
        return [$profile, $stock];
    }

    private function fill(int $id, string $orderId, string $side, int $quantity, float $price): array
    {
        return ['trade_id' => $id, 'order_id' => $orderId, 'tradingsymbol' => 'AAA', 'exchange' => 'NSE',
            'transaction_type' => $side, 'product' => 'CNC', 'quantity' => $quantity, 'price' => $price,
            'fill_timestamp' => '2026-11-20 15:20:00'];
    }
}
