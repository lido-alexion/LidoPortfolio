<?php

namespace Tests\Unit;

use App\Services\FeeCalculatorService;
use PHPUnit\Framework\TestCase;

class FeeCalculatorServiceTest extends TestCase
{
    public function test_nse_buy_includes_stamp_not_bse_txn(): void
    {
        $calculator = new FeeCalculatorService;
        $result = $calculator->calculate(100, 1000, 'buy', 'NSE', FeeCalculatorService::defaultComponents());

        $ids = array_column($result['breakdown'], 'id');
        $this->assertContains('stamp', $ids);
        $this->assertContains('txn_nse', $ids);
        $this->assertNotContains('txn_bse', $ids);
        $this->assertGreaterThan(0, $result['total']);
    }

    public function test_fixed_fee_is_constant(): void
    {
        $calculator = new FeeCalculatorService;
        $components = [
            [
                'id' => 'flat',
                'label' => 'Flat',
                'value' => '5',
                'mode' => FeeCalculatorService::MODE_FIXED,
                'applies_buy' => true,
                'applies_sell' => true,
                'exchange' => 'both',
                'gst_percent' => '0',
            ],
        ];

        $small = $calculator->calculate(1, 100, 'buy', 'NSE', $components);
        $large = $calculator->calculate(100, 10000, 'buy', 'NSE', $components);

        $this->assertSame(5.0, $small['total']);
        $this->assertSame(5.0, $large['total']);
    }
}
