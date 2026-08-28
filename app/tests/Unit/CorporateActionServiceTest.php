<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CorporateActionService;
use App\Services\HoldingsCalculationService;
use App\Services\TransactionRealizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorporateActionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_split_scales_multiple_buys_and_partial_sell_preserving_economics(): void
    {
        $user = User::query()->create([
            'name' => 'Split User',
            'email' => 'split-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'SPLT',
            'exchange' => 'NSE',
            'name' => 'Split Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 80,
            'fees' => 0,
            'transaction_date' => '2026-01-15',
        ]);
        $sell = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 3,
            'price' => 120,
            'fees' => 0,
            'transaction_date' => '2026-02-01',
        ]);

        app(HoldingsCalculationService::class)->recalculateForProfileStock($profile, $stock);
        app(TransactionRealizationService::class)->recalculateForProfileStock($profile, $stock);
        $sell->refresh();
        $realizedBefore = $sell->realized_pl;

        $service = app(CorporateActionService::class);
        $result = $service->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $holding = $result['holding'];
        $this->assertSame('24.0000', $holding->quantity);
        $this->assertSame('46.6667', $holding->avg_buy_price);

        $updatedSell = Transaction::query()->findOrFail($sell->id);
        $this->assertEquals(6, (float) $updatedSell->quantity);
        $this->assertEquals(60, (float) $updatedSell->price);
        $this->assertEquals(60, (float) $updatedSell->realized_pl);
    }

    public function test_split_restates_prices_and_highest_close_since_buy(): void
    {
        $user = User::query()->create([
            'name' => 'Split Prices',
            'email' => 'split-prices-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'SPLP',
            'exchange' => 'NSE',
            'name' => 'Split Prices Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2026-01-15',
            'open_price' => 100,
            'high_price' => 100,
            'low_price' => 100,
            'close_price' => 100,
            'adjusted_close_price' => 100,
            'volume' => 1000,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2026-02-15',
            'open_price' => 120,
            'high_price' => 120,
            'low_price' => 120,
            'close_price' => 120,
            'adjusted_close_price' => 120,
            'volume' => 1000,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2026-03-01',
            'open_price' => 58,
            'high_price' => 58,
            'low_price' => 58,
            'close_price' => 58,
            'adjusted_close_price' => 58,
            'volume' => 2000,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $holding = app(HoldingsCalculationService::class)->recalculateForProfileStock($profile, $stock);
        $summary = app(\App\Services\HoldingPresentationService::class)
            ->enrichHolding($profile, $holding)['stoploss_summary'];

        $this->assertEquals(60.0, (float) $summary['highest_close_since_buy']);
        // OD-22 portfolio trailing 15%: 60 × 0.85 = 51 (independent of stop-loss %)
        $this->assertEquals(51.0, (float) $summary['trailing_stop_price']);
        $this->assertEquals(58.0, (float) $summary['latest_close']);
    }

    public function test_bonus_uses_eligible_quantity_after_partial_sell_and_fifo_prefers_priced_lot(): void
    {
        $user = User::query()->create([
            'name' => 'Bonus User',
            'email' => 'bonus-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'BONS',
            'exchange' => 'NSE',
            'name' => 'Bonus Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 100,
            'price' => 50,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 40,
            'price' => 60,
            'fees' => 0,
            'transaction_date' => '2026-02-01',
        ]);

        $service = app(CorporateActionService::class);
        $preview = $service->preview($profile, $stock, [
            'action_type' => 'bonus',
            'ratio_from' => 1,
            'ratio_to' => 1,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertSame(60.0, $preview['eligible_quantity']);
        $this->assertSame(60.0, $preview['bonus_quantity']);

        $service->apply($profile, $stock, [
            'action_type' => 'bonus',
            'ratio_from' => 1,
            'ratio_to' => 1,
            'ex_date' => '2026-03-01',
        ]);

        $holding = app(HoldingsCalculationService::class)->recalculateForProfileStock($profile, $stock);
        $this->assertSame('120.0000', $holding->quantity);
        $this->assertSame('25.0000', $holding->avg_buy_price);

        $postSell = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 10,
            'price' => 70,
            'fees' => 0,
            'transaction_date' => '2026-04-01',
        ]);

        app(TransactionRealizationService::class)->recalculateForProfileStock($profile, $stock);
        $postSell->refresh();
        $this->assertSame('200.0000', $postSell->realized_pl);
    }

    public function test_bonus_preview_blocks_when_no_eligible_shares(): void
    {
        $user = User::query()->create([
            'name' => 'Bonus Empty',
            'email' => 'bonus-empty-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'EMPTY',
            'exchange' => 'NSE',
            'name' => 'Empty Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $preview = app(CorporateActionService::class)->preview($profile, $stock, [
            'action_type' => 'bonus',
            'ratio_from' => 1,
            'ratio_to' => 1,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertNotEmpty($preview['blocking_errors']);
    }
}
