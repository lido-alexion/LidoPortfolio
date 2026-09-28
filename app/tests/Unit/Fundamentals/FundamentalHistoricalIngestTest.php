<?php

namespace Tests\Unit\Fundamentals;

use App\Models\Stock;
use App\Services\Fundamentals\FundamentalDataProvider;
use App\Services\Fundamentals\FundamentalHistoricalIngestService;
use App\Services\Fundamentals\Historical\BseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\NseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\YahooFundamentalHistoricalSource;
use Mockery;
use Tests\TestCase;

class FundamentalHistoricalIngestTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_yahoo_rows_used_when_official_sources_disabled(): void
    {
        config(['fundamentals_bootstrap.nse_official_enabled' => false]);

        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn([
            [
                'statement_type' => 'income',
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

        $stock = new Stock(['exchange' => 'NSE', 'symbol' => 'RELIANCE']);
        $rows = $service->fetch($stock, 'quarterly');

        $this->assertCount(1, $rows);
        $this->assertSame('yahoo', $rows[0]['provider']);
    }
}
