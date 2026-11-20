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

    public function test_dp_fee_is_sell_only_and_has_gst(): void
    {
        $calculator = new FeeCalculatorService;
        $components = array_values(array_filter(FeeCalculatorService::defaultComponents(), fn ($item) => $item['id'] === 'dp_zerodha'));
        $sell = $calculator->calculate(216, 2196.9338, 'sell', 'BSE', $components);
        $buy = $calculator->calculate(216, 2196.9338, 'buy', 'BSE', $components);

        $this->assertSame(15.34, $sell['total']);
        $this->assertSame(0.0, $buy['total']);
        $this->assertSame(13.0, $sell['breakdown'][0]['base']);
        $this->assertSame(2.34, $sell['breakdown'][0]['gst']);
    }

    public function test_stt_base_rounds_to_nearest_rupee(): void
    {
        $calculator = new FeeCalculatorService;
        $components = [[
            'id' => 'stt', 'label' => 'STT', 'value' => '0.1', 'mode' => 'percentage',
            'applies_buy' => true, 'applies_sell' => true, 'exchange' => 'both', 'gst_percent' => '0',
        ]];
        $result = $calculator->calculate(1, 1234.56, 'sell', 'NSE', $components);
        $this->assertSame(1.0, $result['total']);
    }
}
