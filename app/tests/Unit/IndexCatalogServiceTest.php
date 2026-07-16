<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Services\IndexCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_definitions_include_tier_a_and_b(): void
    {
        $catalog = app(IndexCatalogService::class);
        $symbols = $catalog->enabledSymbolsOrdered();

        $this->assertContains('NIFTY50', $symbols);
        $this->assertContains('NIFTYBANK', $symbols);
        $this->assertContains('INDIAVIX', $symbols);
        $this->assertContains('SENSEX', $symbols);
        $this->assertContains('BSE500', $symbols);
        $this->assertGreaterThanOrEqual(25, count($symbols));
    }

    public function test_ensure_creates_benchmark_stock_with_yahoo_symbol(): void
    {
        $catalog = app(IndexCatalogService::class);
        $stock = $catalog->ensureIndexStock($catalog->definitionForSymbol('NIFTYBANK'));

        $this->assertTrue($stock->is_benchmark);
        $this->assertSame('NSE', $stock->exchange);
        $this->assertSame('^NSEBANK', $stock->yahoo_symbol);
    }

    public function test_primary_benchmark_is_nifty50(): void
    {
        $catalog = app(IndexCatalogService::class);
        $stock = $catalog->primaryBenchmarkStock();

        $this->assertSame('NIFTY50', $stock->symbol);
        $this->assertTrue($stock->is_benchmark);
        $this->assertSame('^NSEI', $stock->yahoo_symbol);
    }

    public function test_sensex_has_no_nse_charting_name(): void
    {
        $catalog = app(IndexCatalogService::class);

        $this->assertNull($catalog->nseChartingNameForSymbol('SENSEX'));
        $this->assertSame('^BSESN', $catalog->definitionForSymbol('SENSEX')['yahoo_symbol']);
    }

    public function test_provider_resolver_maps_configured_indexes(): void
    {
        $resolver = app(\App\Services\ProviderResolverService::class);
        $this->assertSame('^NSEBANK', $resolver->yahooSymbol('NIFTYBANK', 'NSE'));
        $this->assertSame('^BSESN', $resolver->yahooSymbol('SENSEX', 'BSE'));

        $stock = new Stock(['symbol' => 'NIFTYIT', 'exchange' => 'NSE']);
        $updated = $resolver->applyProviderSymbols($stock);
        $this->assertSame('^CNXIT', $updated->yahoo_symbol);
    }
}
