<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Services\Fundamentals\FundamentalDataProvider;
use App\Services\Fundamentals\FundamentalHistoricalIngestService;
use App\Services\Fundamentals\Historical\BseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\NseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\YahooFundamentalHistoricalSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class BseOfficialFundamentalHistoricalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_bse_official_rows_used_for_bse_listed_stock(): void
    {
        config([
            'fundamentals_bootstrap.bse_official_enabled' => true,
            'fundamentals_bootstrap.bse_official_feed_url' => 'https://feeds.example/bse-fundamentals',
            'fundamentals_bootstrap.nse_official_enabled' => false,
        ]);

        Http::fake([
            'feeds.example/*' => Http::response([
                'facts' => [[
                    'statement_type' => 'income_statement',
                    'cadence' => 'quarterly',
                    'fact_key' => 'revenue',
                    'period_end' => '2024-03-31',
                    'value' => 250,
                ]],
            ], 200),
        ]);

        $provider = Mockery::mock(FundamentalDataProvider::class);
        $provider->shouldReceive('fetch')->once()->andReturn([]);

        $service = new FundamentalHistoricalIngestService(
            new NseOfficialFundamentalHistoricalSource,
            new BseOfficialFundamentalHistoricalSource,
            new YahooFundamentalHistoricalSource($provider),
        );

        $stock = Stock::query()->create(['symbol' => 'BSECO', 'exchange' => 'BSE', 'name' => 'BSE Co']);
        $rows = $service->fetch($stock, 'quarterly');

        $this->assertCount(1, $rows);
        $this->assertSame('bse_official', $rows[0]['provider']);
    }
}
