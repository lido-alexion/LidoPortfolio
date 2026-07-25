<?php

namespace Tests\Unit;

use App\Services\Analytics\StockAnalyticsService;
use Tests\TestCase;

class StockAnalyticsServiceTest extends TestCase
{
    public function test_service_is_resolvable(): void
    {
        $svc = app(StockAnalyticsService::class);
        $this->assertInstanceOf(StockAnalyticsService::class, $svc);
    }
}
