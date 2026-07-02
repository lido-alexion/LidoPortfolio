<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Services\EquityUniverseService;
use App\Services\PortfolioLoggerService;
use App\Services\ProviderResolverService;
use App\Services\SettingsService;
use App\Services\StockValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class StockValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_valid_symbol_found_in_local_master_without_http(): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'NSE',
            'name' => 'Infosys Ltd',
            'is_active' => true,
            'is_benchmark' => false,
            'last_verified_at' => now(),
            'yahoo_symbol' => 'INFY.NS',
        ]);

        Http::fake();

        $service = $this->makeService();
        $result = $service->validate('INFY', 'NSE');

        $this->assertTrue($result->valid);
        $this->assertSame($stock->id, $result->stock?->id);
        $this->assertSame('local', $result->source);
        Http::assertNothingSent();
    }

    public function test_invalid_malformed_symbol_is_rejected(): void
    {
        $service = $this->makeService();
        $result = $service->validate('!!!', 'NSE', false);

        $this->assertFalse($result->valid);
    }

    public function test_provider_fallback_accepts_yahoo_when_nse_fails(): void
    {
        Http::fake([
            'https://www.nseindia.com' => Http::response('<html></html>', 200),
            'https://www.nseindia.com/api/quote-equity*' => Http::response([], 500),
            'https://query1.finance.yahoo.com/v8/finance/chart/NEWCO.NS*' => Http::response([
                'chart' => [
                    'result' => [[
                        'meta' => [
                            'shortName' => 'New Company',
                            'regularMarketPrice' => 100.5,
                        ],
                    ]],
                ],
            ], 200),
        ]);

        $service = $this->makeService();
        $result = $service->validate('NEWCO', 'NSE');

        $this->assertTrue($result->valid);
        $this->assertSame('yahoo', $result->source);
        $this->assertDatabaseHas('portfolio_stocks', [
            'symbol' => 'NEWCO',
            'exchange' => 'NSE',
        ]);
    }

    public function test_all_providers_fail_returns_invalid(): void
    {
        Http::fake([
            'https://www.nseindia.com' => Http::response('<html></html>', 200),
            'https://www.nseindia.com/api/quote-equity*' => Http::response([], 500),
            'https://query1.finance.yahoo.com/*' => Http::response([], 404),
            'https://www.alphavantage.co/query*' => Http::response(['Note' => 'rate limit'], 200),
        ]);

        $service = $this->makeService();
        $result = $service->validate('ZZZZZ', 'NSE');

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);
    }

    protected function makeService(): StockValidationService
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('nse_retry_count', '3')->andReturn('1');
        $settings->shouldReceive('get')->with('alpha_vantage_api_key')->andReturn('test-key');

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('validation')->andReturnNull();
        $logger->shouldReceive('provider')->andReturnNull();
        $logger->shouldReceive('api')->andReturnNull();

        return new StockValidationService(
            new ProviderResolverService,
            $logger,
            $settings,
            app(EquityUniverseService::class),
        );
    }
}
