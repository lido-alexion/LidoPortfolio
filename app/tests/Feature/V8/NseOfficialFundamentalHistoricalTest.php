<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Services\Fundamentals\FundamentalHistoricalIngestService;
use App\Services\Fundamentals\FundamentalDataProvider;
use App\Services\Fundamentals\Historical\BseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\NseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\YahooFundamentalHistoricalSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class NseOfficialFundamentalHistoricalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_nse_official_rows_take_priority_over_yahoo(): void
    {
        config([
            'fundamentals_bootstrap.nse_official_enabled' => true,
            'fundamentals_bootstrap.nse_official_feed_url' => 'https://feeds.example/nse-fundamentals',
        ]);

        Http::fake([
            'feeds.example/*' => Http::response([
                'facts' => [[
                    'statement_type' => 'income_statement',
                    'cadence' => 'quarterly',
                    'fact_key' => 'revenue',
                    'period_end' => '2024-03-31',
                    'value' => 500,
                    'availability_date' => '2024-05-15',
                ]],
            ], 200),
        ]);

        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn([
            [
                'statement_type' => 'income_statement',
                'cadence' => 'quarterly',
                'fact_key' => 'revenue',
                'period_end' => '2024-03-31',
                'value' => 100,
            ],
        ]);

        $service = new FundamentalHistoricalIngestService(
            new NseOfficialFundamentalHistoricalSource,
            new BseOfficialFundamentalHistoricalSource,
            new YahooFundamentalHistoricalSource($provider),
        );

        $stock = Stock::query()->create(['symbol' => 'RELIANCE', 'exchange' => 'NSE', 'name' => 'Reliance']);
        $rows = $service->fetch($stock, 'quarterly');

        $this->assertCount(1, $rows);
        $this->assertSame('nse_official', $rows[0]['provider']);
        $this->assertSame(500.0, (float) $rows[0]['value']);
    }
}
