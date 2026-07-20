<?php

namespace Tests\Feature;

use App\Services\IndexConstituentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshIndexConstituentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_refreshes_supported_caches(): void
    {
        config(['portfolio.indexes.enabled' => true]);

        $mock = $this->createMock(IndexConstituentService::class);
        $mock->expects($this->once())
            ->method('refreshSupportedCaches')
            ->willReturn(['refreshed' => 3, 'failed' => 0]);
        $this->app->instance(IndexConstituentService::class, $mock);

        $this->artisan('portfolio:refresh-index-constituents')
            ->assertSuccessful();
    }

    public function test_command_can_refresh_one_symbol(): void
    {
        config(['portfolio.indexes.enabled' => true]);

        $mock = $this->createMock(IndexConstituentService::class);
        $mock->expects($this->once())
            ->method('constituentsForSymbol')
            ->with('NIFTYBANK', true)
            ->willReturn([
                ['symbol' => 'HDFCBANK', 'name' => 'HDFC Bank', 'stock_id' => 1],
            ]);
        $this->app->instance(IndexConstituentService::class, $mock);

        $this->artisan('portfolio:refresh-index-constituents', ['--symbol' => 'NIFTYBANK'])
            ->assertSuccessful();
    }
}
