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
                'brokerage' => 10,
                'transaction_date' => '2026-01-10',
            ],
            (object) [
                'stock_id' => $stockId,
                'type' => 'sell',
                'quantity' => 4,
                'price' => 120,
                'brokerage' => 0,
                'transaction_date' => '2026-02-01',
            ],
        ]);

        $beforeSell = $service->holdingsAsOf($transactions, Carbon::parse('2026-01-20'));
        $this->assertEqualsWithDelta(10, $beforeSell[$stockId]['quantity'], 0.0001);
        $this->assertEqualsWithDelta(1010, $beforeSell[$stockId]['invested_amount'], 0.0001);

        $afterSell = $service->holdingsAsOf($transactions, Carbon::parse('2026-02-15'));
        $this->assertEqualsWithDelta(6, $afterSell[$stockId]['quantity'], 0.0001);
        $this->assertEqualsWithDelta(606, $afterSell[$stockId]['invested_amount'], 0.0001);
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
                'brokerage' => 0,
                'transaction_date' => '2026-03-01',
            ],
        ]);

        $state = $service->holdingsAsOf($transactions, Carbon::parse('2026-02-01'));
        $this->assertSame([], $state);
    }
}
