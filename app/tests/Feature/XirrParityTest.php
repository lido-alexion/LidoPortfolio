<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockMetric;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HoldingPresentationService;
use App\Services\HoldingsCalculationService;
use App\Services\PortfolioCalculationService;
use App\Services\XirrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class XirrParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_portfolio_xirr_matches_single_open_holding_when_only_one_stock_traded(): void
    {
        $user = User::query()->create([
            'name' => 'Xirr User',
            'email' => 'xirr-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'XIRR1',
            'exchange' => 'NSE',
            'name' => 'Xirr Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'brokerage' => 10,
            'transaction_date' => '2024-01-15',
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2025-05-20',
            'open_price' => 150,
            'high_price' => 150,
            'low_price' => 150,
            'close_price' => 150,
            'volume' => 0,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);

        app(HoldingsCalculationService::class)->recalculateForUser($user);

        $summary = app(PortfolioCalculationService::class)->calculateForUser($user);
        $holding = $user->holdings()->with('stock.metrics')->first();
        $enriched = app(HoldingPresentationService::class)->enrichHolding($user, $holding);

        $this->assertNotNull($summary['xirr']);
        $this->assertNotNull($enriched['xirr']);
        $this->assertEqualsWithDelta(
            $summary['xirr'],
            $enriched['xirr'],
            0.01,
            'Portfolio XIRR should match the only open holding when all cash flows are for that stock.',
        );
        $this->assertEqualsWithDelta(
            $summary['portfolio_value'],
            10 * 150,
            0.01,
        );
    }

    public function test_portfolio_xirr_uses_same_terminal_as_portfolio_value(): void
    {
        $user = User::query()->create([
            'name' => 'Terminal User',
            'email' => 'terminal-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'XIRR2',
            'exchange' => 'NSE',
            'name' => 'Terminal Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 200,
            'brokerage' => 0,
            'transaction_date' => '2024-03-01',
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2025-05-01',
            'open_price' => 240,
            'high_price' => 240,
            'low_price' => 240,
            'close_price' => 240,
            'volume' => 0,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);

        app(HoldingsCalculationService::class)->recalculateForUser($user);

        $summary = app(PortfolioCalculationService::class)->calculateForUser($user);
        $xirr = app(XirrService::class)->calculateFromTransactions(
            Transaction::query()->where('user_id', $user->id)->orderBy('transaction_date')->get(),
            $summary['portfolio_value'],
            now()->startOfDay(),
        );

        $this->assertEqualsWithDelta($summary['xirr'], $xirr, 0.0001);
    }

    public function test_holding_xirr_uses_displayed_latest_close_not_older_global_price(): void
    {
        $user = User::query()->create([
            'name' => 'Holding Xirr User',
            'email' => 'holding-xirr-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'MISMATCH',
            'exchange' => 'NSE',
            'name' => 'Mismatch Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 200,
            'brokerage' => 0,
            'transaction_date' => '2025-05-10',
        ]);

        // OHLCV since buy (180) plus older row before buy (250) — XIRR must not use the pre-buy close.
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2025-05-05',
            'open_price' => 250,
            'high_price' => 250,
            'low_price' => 250,
            'close_price' => 250,
            'volume' => 0,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2025-05-20',
            'open_price' => 180,
            'high_price' => 180,
            'low_price' => 180,
            'close_price' => 180,
            'volume' => 0,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);

        app(HoldingsCalculationService::class)->recalculateForUser($user);

        $holding = $user->holdings()->with('stock.metrics')->first();
        $enriched = app(HoldingPresentationService::class)->enrichHolding($user, $holding);

        $this->assertEqualsWithDelta(180, $enriched['stoploss_summary']['latest_close'], 0.0001);
        $this->assertLessThan((float) $holding->avg_buy_price, 180.0);
        $this->assertNotNull($enriched['xirr']);
        $this->assertLessThan(0, $enriched['xirr'], 'XIRR should be negative when latest close is below avg buy (single buy).');

        $terminalValue = (float) $holding->quantity * 180;
        $xirrAtDisplayedClose = app(XirrService::class)->calculateStockXirr(
            $user,
            (int) $stock->id,
            now()->startOfDay(),
            $terminalValue,
        );
        $this->assertEqualsWithDelta($enriched['xirr'], $xirrAtDisplayedClose, 0.01);
        $this->assertLessThan(0, $xirrAtDisplayedClose);
    }
}
