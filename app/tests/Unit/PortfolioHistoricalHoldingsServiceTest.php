<?php

namespace Tests\Unit;

use App\Services\PortfolioHistoricalHoldingsService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class PortfolioHistoricalHoldingsServiceTest extends TestCase
{
    public function test_holdings_as_of_reflects_buys_and_partial_sells(): void
    {
        $service = new PortfolioHistoricalHoldingsService();
        $stockId = 1;

        $transactions = new Collection([
            (object) [
                'stock_id' => $stockId,
                'type' => 'buy',
                'quantity' => 10,
                'price' => 100,
                'fees' => 10,
                'transaction_date' => '2026-01-10',
            ],
            (object) [
                'stock_id' => $stockId,
                'type' => 'sell',
                'quantity' => 4,
                'price' => 120,
                'fees' => 0,
                'transaction_date' => '2026-02-01',
            ],
        ]);

        $beforeSell = $service->holdingsAsOf($transactions, Carbon::parse('2026-01-20'));
        $this->assertEqualsWithDelta(10, $beforeSell[$stockId]['quantity'], 0.0001);
        $this->assertEqualsWithDelta(1000, $beforeSell[$stockId]['invested_amount'], 0.0001);

        $afterSell = $service->holdingsAsOf($transactions, Carbon::parse('2026-02-15'));
        $this->assertEqualsWithDelta(6, $afterSell[$stockId]['quantity'], 0.0001);
        $this->assertEqualsWithDelta(600, $afterSell[$stockId]['invested_amount'], 0.0001);
    }

    public function test_future_transactions_are_excluded(): void
    {
        $service = new PortfolioHistoricalHoldingsService();
        $transactions = new Collection([
            (object) [
                'stock_id' => 2,
                'type' => 'buy',
                'quantity' => 5,
                'price' => 50,
                'fees' => 0,
                'transaction_date' => '2026-03-01',
            ],
        ]);

        $state = $service->holdingsAsOf($transactions, Carbon::parse('2026-02-01'));
        $this->assertSame([], $state);
    }

    public function test_fees_are_excluded_from_cost_basis(): void
    {
        $service = new PortfolioHistoricalHoldingsService();
        $transactions = new Collection([
            (object) [
                'id' => 1,
                'stock_id' => 3,
                'type' => 'buy',
                'quantity' => 2,
                'price' => 100,
                'fees' => 50,
                'transaction_date' => '2026-01-01',
            ],
        ]);

        $state = $service->holdingsAsOf($transactions, Carbon::parse('2026-01-01'));
        $this->assertEqualsWithDelta(200, $state[3]['invested_amount'], 0.0001);
        $this->assertEqualsWithDelta(100, $state[3]['avg_buy_price'], 0.0001);
    }

    public function test_oversell_produces_warning_in_detailed_result(): void
    {
        $service = new PortfolioHistoricalHoldingsService();
        $transactions = new Collection([
            (object) [
                'id' => 10,
                'stock_id' => 4,
                'type' => 'sell',
                'quantity' => 1,
                'price' => 10,
                'transaction_date' => '2026-01-01',
            ],
        ]);

        $detailed = $service->holdingsAsOfDetailed($transactions, Carbon::parse('2026-01-01'));
        $this->assertSame([], $detailed['holdings']);
        $this->assertCount(1, $detailed['warnings']);
        $this->assertSame('historical_oversell', $detailed['warnings'][0]['code']);
        $this->assertSame(10, $detailed['warnings'][0]['transaction_id']);
    }
}
