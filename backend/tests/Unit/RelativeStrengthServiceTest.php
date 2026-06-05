<?php

namespace Tests\Unit;

use App\Services\RelativeStrengthService;
use App\Services\StockPriceHistoryService;
use Mockery;
use Tests\TestCase;

class RelativeStrengthServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_service_can_be_constructed(): void
    {
        $history = Mockery::mock(StockPriceHistoryService::class);
        $service = new RelativeStrengthService($history);
        $this->assertInstanceOf(RelativeStrengthService::class, $service);
    }
}
