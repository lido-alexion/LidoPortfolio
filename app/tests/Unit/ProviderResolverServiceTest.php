<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Services\ProviderResolverService;
use Tests\TestCase;

class ProviderResolverServiceTest extends TestCase
{
    protected ProviderResolverService $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProviderResolverService;
    }

    public function test_normalize_symbol_strips_suffix(): void
    {
        $this->assertSame(
            ['symbol' => 'INFY', 'exchange' => 'NSE'],
            $this->resolver->normalizeSymbol('INFY.NS'),
        );
        $this->assertSame(
            ['symbol' => 'INFY', 'exchange' => 'BSE'],
            $this->resolver->normalizeSymbol('INFY.BO'),
        );
    }

    public function test_malformed_symbols_are_detected(): void
    {
        $this->assertTrue($this->resolver->isMalformed(''));
        $this->assertTrue($this->resolver->isMalformed('bad symbol!'));
        $this->assertFalse($this->resolver->isMalformed('INFY'));
    }

    public function test_nifty50_yahoo_symbol_is_nsei_index(): void
    {
        $this->assertSame('^NSEI', $this->resolver->yahooSymbol('NIFTY50', 'NSE'));

        $stock = new Stock([
            'symbol' => 'NIFTY50',
            'exchange' => 'NSE',
            'yahoo_symbol' => 'NIFTY50.NS',
        ]);
        $updated = $this->resolver->applyProviderSymbols($stock);
        $this->assertSame('^NSEI', $updated->yahoo_symbol);
    }

    public function test_provider_symbols_for_nse_and_bse(): void
    {
        $this->assertSame('INFY.NS', $this->resolver->yahooSymbol('INFY', 'NSE'));
        $this->assertSame('INFY.BO', $this->resolver->yahooSymbol('INFY', 'BSE'));

        $stock = new Stock([
            'symbol' => 'TCS',
            'exchange' => 'NSE',
            'series' => 'EQ',
        ]);
        $symbols = $this->resolver->providerSymbolsForStock($stock);
        $this->assertSame('TCS', $symbols['nse']);
        $this->assertSame('TCS.NS', $symbols['yahoo']);
    }

    public function test_nse_trade_symbol_appends_be_series_suffix(): void
    {
        $stock = new Stock([
            'symbol' => 'TOKYOPLAST',
            'exchange' => 'NSE',
            'series' => 'BE',
        ]);

        $this->assertSame('TOKYOPLAST-BE', $this->resolver->nseTradeSymbol($stock));
        $this->assertSame('TOKYOPLAST-BE', $this->resolver->providerSymbolsForStock($stock)['nse']);
    }

    public function test_yahoo_symbol_candidates_for_nse_includes_bo_fallback(): void
    {
        $stock = new Stock([
            'symbol' => '3BBLACKBIO',
            'exchange' => 'NSE',
        ]);

        $this->assertSame(
            ['3BBLACKBIO.NS', '3BBLACKBIO.BO'],
            $this->resolver->yahooSymbolCandidates($stock),
        );
    }

    public function test_yahoo_symbol_candidates_for_bse_use_bo_only(): void
    {
        $stock = new Stock([
            'symbol' => 'INFY',
            'exchange' => 'BSE',
        ]);

        $this->assertSame(
            ['INFY.BO'],
            $this->resolver->yahooSymbolCandidates($stock),
        );
    }
}
