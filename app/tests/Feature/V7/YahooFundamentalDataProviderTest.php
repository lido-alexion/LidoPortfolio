<?php

namespace Tests\Feature\V7;

use App\Models\Stock;
use App\Services\Fundamentals\YahooFundamentalDataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class YahooFundamentalDataProviderTest extends TestCase
{
    use RefreshDatabase;

    private string $stub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stub = base_path('tests/Fixtures/yahoo_fundamentals_stub.php');
    }

    public function test_provider_invokes_adapter_with_symbol_and_cadence_and_normalizes_success(): void
    {
        $stock = Stock::query()->create(['symbol' => 'TCS', 'exchange' => 'NSE', 'name' => 'TCS']);
        $provider = new YahooFundamentalDataProvider(PHP_BINARY, $this->stub, 5, 1024 * 1024);

        $rows = $provider->fetch($stock, 'quarterly');

        $this->assertSame('yahoo', $rows[0]['provider']);
        $this->assertSame('quarterly', $rows[0]['cadence']);
        $this->assertSame('revenue', $rows[0]['fact_key']);
        $this->assertSame(100.0, $rows[0]['value']);
        $this->assertSame('yfinance', $rows[0]['source_meta']['transport']);
    }

    public function test_non_zero_adapter_exit_is_explicit(): void
    {
        $stock = Stock::query()->create(['symbol' => 'FAIL', 'exchange' => 'NSE', 'name' => 'Fail']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider unavailable');

        (new YahooFundamentalDataProvider(PHP_BINARY, $this->stub))->fetch($stock, 'annual');
    }

    public function test_malformed_json_is_rejected(): void
    {
        $stock = Stock::query()->create(['symbol' => 'MALFORMED', 'exchange' => 'NSE', 'name' => 'Malformed']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');

        (new YahooFundamentalDataProvider(PHP_BINARY, $this->stub))->fetch($stock, 'quarterly');
    }

    public function test_timeout_is_explicit(): void
    {
        $stock = Stock::query()->create(['symbol' => 'TIMEOUT', 'exchange' => 'NSE', 'name' => 'Timeout']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('timed out');

        (new YahooFundamentalDataProvider(PHP_BINARY, $this->stub, 0.1))->fetch($stock, 'quarterly');
    }
}
