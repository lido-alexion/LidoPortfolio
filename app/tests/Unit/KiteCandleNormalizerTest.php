<?php

namespace Tests\Unit;

use App\Services\PriceProviders\KiteCandleNormalizer;
use Tests\TestCase;

class KiteCandleNormalizerTest extends TestCase
{
    public function test_maps_raw_ohlcv_without_inventing_adjusted_close(): void
    {
        $row = app(KiteCandleNormalizer::class)->normalize([
            '2026-11-20T09:15:00+0530', 100.25, 104, 99.5, 102.75, 123456,
        ]);

        $this->assertSame([
            'price_date' => '2026-11-20',
            'open_price' => 100.25,
            'high_price' => 104.0,
            'low_price' => 99.5,
            'close_price' => 102.75,
            'volume' => 123456,
        ], $row);
        $this->assertArrayNotHasKey('adjusted_close_price', $row);
    }

    public function test_rejects_incomplete_or_inconsistent_candles(): void
    {
        $normalizer = app(KiteCandleNormalizer::class);

        $this->assertNull($normalizer->normalize(['2026-11-20T09:15:00+0530', 1, 2]));
        $this->assertNull($normalizer->normalize(['2026-11-20T09:15:00+0530', 100, 90, 95, 98, 10]));
        $this->assertNull($normalizer->normalize(['2026-11-20T09:15:00+0530', 100, 105, 95, 102]));
        $this->assertNull($normalizer->normalize(['2026-11-20T09:15:00+0530', 100, 105, 95, 102, -1]));
        $this->assertNull($normalizer->normalize(['2026-11-20 09:15:00', 100, 105, 95, 102, 10]));
        $specialSaturdaySession = $normalizer->normalize(['2023-02-25T09:15:00+0530', 100, 105, 95, 102, 10]);
        $this->assertSame('2023-02-25', $specialSaturdaySession['price_date']);
    }

    public function test_converts_offset_timestamp_to_exchange_local_session_date(): void
    {
        $row = app(KiteCandleNormalizer::class)->normalize([
            '2026-11-19T20:00:00Z', 100, 105, 95, 102, 10,
        ]);

        $this->assertSame('2026-11-20', $row['price_date']);
    }
}
