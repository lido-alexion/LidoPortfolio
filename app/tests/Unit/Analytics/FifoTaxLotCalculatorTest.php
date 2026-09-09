<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\FifoTaxLotCalculator;
use PHPUnit\Framework\TestCase;

class FifoTaxLotCalculatorTest extends TestCase
{
    public function test_sells_match_fifo_without_changing_wavg_accounting(): void
    {
        $result = (new FifoTaxLotCalculator)->calculate([
            ['id' => 1, 'type' => 'buy', 'date' => '2024-01-01', 'quantity' => 10, 'price' => 100, 'fees' => 10],
            ['id' => 2, 'type' => 'buy', 'date' => '2025-12-01', 'quantity' => 10, 'price' => 200, 'fees' => 0],
            ['id' => 3, 'type' => 'sell', 'date' => '2026-01-10', 'quantity' => 15, 'price' => 300, 'fees' => 15],
        ]);

        $this->assertSame('fifo', $result['method']);
        $this->assertSame('complete', $result['completeness']);
        $this->assertCount(2, $result['realized_disposals']);
        $this->assertSame('long_term', $result['realized_disposals'][0]['term']);
        $this->assertSame('short_term', $result['realized_disposals'][1]['term']);
        $this->assertSame(5.0, $result['open_lots'][0]['remaining_quantity']);
    }

    public function test_opening_lot_restores_lineage_and_transfer_out_is_not_a_disposal(): void
    {
        $result = (new FifoTaxLotCalculator)->calculate([
            ['id' => 7, 'type' => 'transfer_out', 'date' => '2026-01-01', 'quantity' => 2],
        ], [
            ['id' => 4, 'acquired_on' => '2020-01-01', 'quantity' => 5, 'cost_basis' => 500],
        ]);

        $this->assertSame([], $result['realized_disposals']);
        $this->assertSame(3.0, $result['open_lots'][0]['remaining_quantity']);
    }

    public function test_missing_or_unsupported_lineage_is_incomplete_not_fabricated(): void
    {
        $result = (new FifoTaxLotCalculator)->calculate([
            ['id' => 8, 'type' => 'sell', 'date' => '2026-01-01', 'quantity' => 1, 'price' => 100],
            ['id' => 9, 'type' => 'buy', 'date' => '2026-01-02', 'quantity' => 1, 'price' => 90, 'corporate_action_supported' => false],
        ]);

        $this->assertSame('incomplete', $result['completeness']);
        $this->assertSame([], $result['realized_disposals']);
        $this->assertContains('missing_acquisition_lineage:8', $result['limitations']);
        $this->assertContains('unsupported_corporate_action:9', $result['limitations']);
    }
}
