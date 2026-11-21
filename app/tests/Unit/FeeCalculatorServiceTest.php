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

    public function test_grouped_note_rounding_aggregates_stt_stamp_and_taxable_gst(): void
    {
        $calculator = new FeeCalculatorService;
        $rows = [
            ['quantity' => 1, 'price' => 82500, 'type' => 'buy', 'execution_exchange' => 'NSE', 'transaction_date' => '2026-06-19'],
            ['quantity' => 1, 'price' => 83200, 'type' => 'buy', 'execution_exchange' => 'NSE', 'transaction_date' => '2026-06-19'],
            ['quantity' => 1, 'price' => 83700, 'type' => 'buy', 'execution_exchange' => 'NSE', 'transaction_date' => '2026-06-19'],
            ['quantity' => 1, 'price' => 90000, 'type' => 'sell', 'execution_exchange' => 'NSE', 'transaction_date' => '2026-10-07'],
        ];
        $grouped = $calculator->calculateGrouped($rows, FeeCalculatorService::defaultComponents());
        $sum = static fn (string $id, int $count = 4): float => array_sum(array_map(
            fn (array $result) => array_sum(array_column(array_values(array_filter($result['breakdown'], fn ($line) => $line['id'] === $id)), 'base')),
            array_slice($grouped, 0, $count),
        ));

        $this->assertSame(249.0, $sum('stt', 3));
        $this->assertSame(37.0, $sum('stamp', 3));
        $mixed = $calculator->calculateGrouped([
            ['quantity' => 1, 'price' => 145400, 'type' => 'buy', 'execution_exchange' => 'NSE', 'transaction_date' => '2026-10-07'],
            ['quantity' => 1, 'price' => 132400, 'type' => 'sell', 'execution_exchange' => 'NSE', 'transaction_date' => '2026-10-07'],
        ], FeeCalculatorService::defaultComponents());
        $this->assertSame(278.0, array_sum(array_map(fn ($result) => array_sum(array_column(array_values(array_filter($result['breakdown'], fn ($line) => $line['id'] === 'stt')), 'base')), $mixed)));
        $venueMixed = $calculator->calculateGrouped([
            ['quantity' => 1, 'price' => 49877, 'type' => 'buy', 'execution_exchange' => 'NSE', 'transaction_date' => '2026-06-15'],
            ['quantity' => 1, 'price' => 49474.1, 'type' => 'buy', 'execution_exchange' => 'BSE', 'transaction_date' => '2026-06-15'],
        ], FeeCalculatorService::defaultComponents());
        $this->assertSame(15.0, array_sum(array_map(fn ($result) => array_sum(array_column(array_values(array_filter($result['breakdown'], fn ($line) => $line['id'] === 'stamp')), 'base')), $venueMixed)));
        $txn = array_values(array_filter(FeeCalculatorService::defaultComponents(), fn ($item) => $item['id'] === 'txn_nse'))[0];
        $modern = $calculator->calculate(100, 10000, 'buy', 'NSE', [$txn], '2026-06-19');
        $historic = $calculator->calculate(100, 10000, 'buy', 'NSE', [$txn], '2024-08-22');
        $this->assertSame(30.7, $modern['breakdown'][0]['base']);
        $this->assertGreaterThan($modern['total'], $historic['total']);
    }

    public function test_grouped_note_totals_reduce_anonymized_multi_fill_day_errors(): void
    {
        $calculator = new FeeCalculatorService;
        $days = [
            ['date' => '2026-06-19', 'invoice' => 295.33, 'rows' => [
                ['quantity' => 1, 'price' => 82500, 'type' => 'buy', 'execution_exchange' => 'NSE'],
                ['quantity' => 1, 'price' => 83200, 'type' => 'buy', 'execution_exchange' => 'NSE'],
                ['quantity' => 1, 'price' => 83700, 'type' => 'buy', 'execution_exchange' => 'NSE'],
            ]],
            ['date' => '2026-10-07', 'invoice' => 324.81, 'rows' => [
                ['quantity' => 1, 'price' => 190000, 'type' => 'buy', 'execution_exchange' => 'NSE'],
                ['quantity' => 1, 'price' => 50000, 'type' => 'buy', 'execution_exchange' => 'BSE'],
                ['quantity' => 1, 'price' => 38000, 'type' => 'sell', 'execution_exchange' => 'NSE'],
            ]],
        ];
        $noteComponents = array_values(array_filter(FeeCalculatorService::defaultComponents(), fn ($component) => ($component['aggregation'] ?? null) !== 'isin_day_sell'));
        $beforeError = 0.0;
        $afterError = 0.0;
        foreach ($days as $day) {
            $rows = array_map(fn ($row) => [...$row, 'transaction_date' => $day['date']], $day['rows']);
            $before = array_sum(array_map(fn ($row) => $calculator->calculate($row['quantity'], $row['price'], $row['type'], $row['execution_exchange'], $noteComponents, $day['date'])['total'], $rows));
            $after = array_sum(array_column($calculator->calculateGrouped($rows, $noteComponents), 'total'));
            $beforeError += abs($before - $day['invoice']);
            $afterError += abs($after - $day['invoice']);
        }

        $this->assertGreaterThan(1.0, $beforeError);
        $this->assertLessThan(0.03, $afterError);
        $this->assertLessThan($beforeError, $afterError);
    }
}
