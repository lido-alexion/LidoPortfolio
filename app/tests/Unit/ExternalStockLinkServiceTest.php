<?php

namespace Tests\Unit;

use App\Services\ExternalStockLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalStockLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_resolve_syrma_examples(): void
    {
        $svc = app(ExternalStockLinkService::class);
        $byId = [];
        foreach ($svc->defaults() as $row) {
            $byId[$row['id']] = $row['url'];
        }

        $this->assertSame(
            'https://chartink.com/stocks/SYRMA.html',
            $svc->resolve($byId['chartink'], 'SYRMA', 'NSE'),
        );
        $this->assertSame(
            'https://in.tradingview.com/symbols/NSE-SYRMA/',
            $svc->resolve($byId['tradingview'], 'syrma', 'NSE'),
        );
        $this->assertSame(
            'https://finance.yahoo.com/quote/SYRMA.NS/',
            $svc->resolve($byId['yahoo'], 'SYRMA', 'NSE'),
        );
        $this->assertSame(
            'https://finance.yahoo.com/quote/SYRMA.BO/',
            $svc->resolve($byId['yahoo'], 'SYRMA', 'BSE'),
        );
        $this->assertSame(
            'https://zerodha.com/markets/stocks/NSE/SYRMA/',
            $svc->resolve($byId['zerodha'], 'SYRMA', 'NSE'),
        );
        $this->assertSame(
            'https://www.screener.in/company/SYRMA/consolidated/',
            $svc->resolve($byId['screener'], 'SYRMA', 'NSE'),
        );
        $this->assertSame(
            'https://www.stockscans.in/company/NSE:SYRMA',
            $svc->resolve($byId['stockscans'], 'SYRMA', 'NSE'),
        );
    }

    public function test_persist_and_enabled_templates(): void
    {
        $svc = app(ExternalStockLinkService::class);
        $svc->persist([
            [
                'id' => 'chartink',
                'label' => 'Chartink',
                'url' => 'https://chartink.com/stocks/{SYMBOL}.html',
                'enabled' => true,
            ],
            [
                'id' => 'off',
                'label' => 'Off',
                'url' => 'https://example.com/{SYMBOL}',
                'enabled' => false,
            ],
            [
                'id' => 'blank',
                'label' => 'Blank',
                'url' => '',
                'enabled' => true,
            ],
        ]);

        $enabled = $svc->enabledTemplates();
        $this->assertCount(1, $enabled);
        $this->assertSame('chartink', $enabled[0]['id']);
        $this->assertSame('Chartink', $enabled[0]['label']);
    }
}
