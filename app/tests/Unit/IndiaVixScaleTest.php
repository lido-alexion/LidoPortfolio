<?php

namespace Tests\Unit;

use App\Support\IndiaVixScale;
use PHPUnit\Framework\TestCase;

class IndiaVixScaleTest extends TestCase
{
    public function test_leaves_normal_vix_unchanged(): void
    {
        $row = [
            'price_date' => '2026-07-28',
            'open_price' => 12.66,
            'high_price' => 12.82,
            'low_price' => 11.72,
            'close_price' => 12.56,
        ];

        $this->assertSame($row, IndiaVixScale::normalizeRow($row));
        $this->assertFalse(IndiaVixScale::needsRescale(12.56));
    }

    public function test_divides_hundredfold_scaled_ohlc(): void
    {
        $row = IndiaVixScale::normalizeRow([
            'price_date' => '2026-07-28',
            'open_price' => 1266.0,
            'high_price' => 1282.0,
            'low_price' => 1172.25,
            'close_price' => 1264.5,
            'adjusted_close_price' => 1264.5,
        ]);

        $this->assertEqualsWithDelta(12.66, $row['open_price'], 0.0001);
        $this->assertEqualsWithDelta(12.82, $row['high_price'], 0.0001);
        $this->assertEqualsWithDelta(11.7225, $row['low_price'], 0.0001);
        $this->assertEqualsWithDelta(12.645, $row['close_price'], 0.0001);
        $this->assertEqualsWithDelta(12.645, $row['adjusted_close_price'], 0.0001);
    }

    public function test_rejects_nonsensical_mega_values(): void
    {
        $this->assertFalse(IndiaVixScale::needsRescale(25000.0));
        $row = IndiaVixScale::normalizeRow(['close_price' => 25000.0]);
        $this->assertSame(25000.0, $row['close_price']);
    }

    public function test_symbol_helper(): void
    {
        $this->assertTrue(IndiaVixScale::isIndiaVixSymbol('INDIAVIX'));
        $this->assertTrue(IndiaVixScale::isIndiaVixSymbol('indiavix'));
        $this->assertFalse(IndiaVixScale::isIndiaVixSymbol('NIFTY50'));
    }
}
