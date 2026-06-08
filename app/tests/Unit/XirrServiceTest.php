<?php

namespace Tests\Unit;

use App\Services\StockQuoteService;
use App\Services\XirrService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class XirrServiceTest extends TestCase
{
    public function test_calculate_from_transactions_returns_numeric_xirr(): void
    {
        $service = new XirrService(new StockQuoteService());
        $transactions = new Collection([
            (object) [
                'quantity' => 10,
                'price' => 100,
                'fees' => 0,
                'type' => 'buy',
                'transaction_date' => '2024-01-01',
            ],
            (object) [
                'quantity' => 5,
                'price' => 130,
                'fees' => 0,
                'type' => 'sell',
                'transaction_date' => '2024-06-01',
            ],
        ]);

        $xirr = $service->calculateFromTransactions($transactions, 650, Carbon::parse('2024-12-31'));

        $this->assertNotNull($xirr);
        $this->assertIsFloat($xirr);
    }
}
