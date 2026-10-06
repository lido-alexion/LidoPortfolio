<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Services\Fundamentals\Historical\NseIntegratedFilingClient;
use App\Services\Fundamentals\Historical\NseOfficialFundamentalHistoricalSource;
use App\Services\Fundamentals\Historical\NseXbrlFactsParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NseIntegratedFilingClientTest extends TestCase
{
    use RefreshDatabase;

    private function xbrl(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<xbrli:xbrl xmlns:xbrli="http://www.xbrl.org/2003/instance" xmlns:iso4217="http://www.xbrl.org/2003/iso4217" xmlns:ind="http://www.sebi.gov.in/xbrl/2026-01-31/in-capmkt">
<xbrli:context id="q"><xbrli:entity><xbrli:identifier scheme="t">A</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:startDate>2026-01-01</xbrli:startDate><xbrli:endDate>2026-03-31</xbrli:endDate></xbrli:period></xbrli:context>
<xbrli:unit id="INR"><xbrli:measure>iso4217:INR</xbrli:measure></xbrli:unit>
<ind:RevenueFromOperations contextRef="q" unitRef="INR" decimals="-3">858634000</ind:RevenueFromOperations>
</xbrli:xbrl>
XML;
    }

    public function test_direct_website_access_stays_blocked_without_explicit_authorization(): void
    {
        config([
            'fundamentals_bootstrap.nse_official_enabled' => true,
            'fundamentals_bootstrap.nse_official_direct_enabled' => true,
            'fundamentals_bootstrap.nse_official_direct_access_authorized' => false,
            'fundamentals_bootstrap.nse_official_feed_url' => null,
        ]);
        Http::fake();

        $source = new NseOfficialFundamentalHistoricalSource(new NseIntegratedFilingClient(new NseXbrlFactsParser));
        $rows = $source->fetch(new Stock(['symbol' => 'ALBERTDAVD', 'exchange' => 'NSE']), 'quarterly');

        $this->assertSame([], $rows);
        Http::assertNothingSent();
    }

    public function test_enabled_source_fetches_official_index_and_allowlisted_xbrl_then_reuses_bytes_for_annual_check(): void
    {
        config([
            'fundamentals_bootstrap.nse_official_enabled' => true,
            'fundamentals_bootstrap.nse_official_direct_enabled' => true,
            'fundamentals_bootstrap.nse_official_direct_access_authorized' => true,
            'fundamentals_bootstrap.nse_official_feed_url' => null,
            'fundamentals_bootstrap.nse_official_timeout_seconds' => 10,
        ]);
        $filing = [
            'seq_Id' => '183083',
            'symbol' => 'ALBERTDAVD',
            'qe_Date' => '31-MAR-2026',
            'consolidated' => 'Standalone',
            'broadcast_Date' => '13-May-2026 13:20:09',
            'revised_Date' => null,
            'revision_Remark' => null,
            'type_Sub' => 'Original',
            'xbrl' => 'https://nsearchives.nseindia.com/corporate/xbrl/sample.xml',
        ];
        Http::fake([
            'www.nseindia.com/api/integrated-filing-results*' => Http::response(['data' => [$filing]], 200),
            'nsearchives.nseindia.com/corporate/xbrl/sample.xml' => Http::response($this->xbrl(), 200, ['Content-Type' => 'application/xml']),
        ]);

        $client = new NseIntegratedFilingClient(new NseXbrlFactsParser);
        $source = new NseOfficialFundamentalHistoricalSource($client);
        $stock = new Stock(['symbol' => 'ALBERTDAVD', 'exchange' => 'NSE']);

        $quarterly = $source->fetch($stock, 'quarterly');
        $this->assertCount(1, $quarterly);
        $this->assertSame('revenue', $quarterly[0]['fact_key']);
        $this->assertSame('858634000', $quarterly[0]['value']);
        $this->assertSame('nse_official', $quarterly[0]['provider']);
        Http::assertSentCount(2);

        $annual = $source->fetch($stock, 'annual');
        $this->assertSame([], $annual);
        Http::assertSentCount(2);
    }

    public function test_direct_nse_route_discovers_and_parses_inline_xbrl_html_filing_links(): void
    {
        config([
            'fundamentals_bootstrap.nse_official_direct_enabled' => true,
            'fundamentals_bootstrap.nse_official_direct_access_authorized' => true,
            'fundamentals_bootstrap.nse_official_feed_url' => null,
        ]);
        $filing = [
            'seq_Id' => '170642',
            'symbol' => 'ANTGRAPHIC',
            'qe_Date' => '31-MAR-2026',
            'consolidated' => 'Standalone',
            'broadcast_Date' => '26-Jun-2026 20:22:17',
            'type_Sub' => 'Original',
            'xbrl' => 'https://nsearchives.nseindia.com/corporate/ixbrl/sample_iXBRL_WEB.html',
        ];
        $html = <<<'HTML'
<html xmlns:ix="http://www.xbrl.org/2013/inlineXBRL" xmlns:xbrli="http://www.xbrl.org/2003/instance" xmlns:iso4217="http://www.xbrl.org/2003/iso4217" xmlns:ind="http://www.sebi.gov.in/xbrl/2026-01-31/in-capmkt">
<head><meta charset="utf-8"></head><body><ix:header><ix:resources>
<xbrli:context id="q"><xbrli:entity><xbrli:identifier scheme="test">ISSUER</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:startDate>2026-01-01</xbrli:startDate><xbrli:endDate>2026-03-31</xbrli:endDate></xbrli:period></xbrli:context>
<xbrli:unit id="INR"><xbrli:measure>iso4217:INR</xbrli:measure></xbrli:unit>
</ix:resources></ix:header><table><tr><td><ix:nonFraction name="ind:RevenueFromOperations" contextRef="q" unitRef="INR" scale="3">858,634</ix:nonFraction></td></tr></table></body></html>
HTML;
        Http::fake([
            'www.nseindia.com/api/integrated-filing-results*' => Http::response(['data' => [$filing]], 200),
            'nsearchives.nseindia.com/corporate/ixbrl/sample_iXBRL_WEB.html' => Http::response($html, 200, ['Content-Type' => 'text/html']),
        ]);

        $source = new NseOfficialFundamentalHistoricalSource(
            new NseIntegratedFilingClient(new NseXbrlFactsParser),
        );
        $rows = $source->fetch(new Stock(['symbol' => 'ANTGRAPHIC', 'exchange' => 'NSE']), 'quarterly');

        $this->assertCount(1, $rows);
        $this->assertSame('revenue', $rows[0]['fact_key']);
        $this->assertSame('858634000', $rows[0]['value']);
        $this->assertSame('https://nsearchives.nseindia.com/corporate/ixbrl/sample_iXBRL_WEB.html', $rows[0]['source_meta']['document_url']);
        Http::assertSentCount(2);
    }

    public function test_disables_source_for_other_exchanges_and_rejects_untrusted_document_hosts(): void
    {
        config([
            'fundamentals_bootstrap.nse_official_enabled' => true,
            'fundamentals_bootstrap.nse_official_direct_enabled' => true,
            'fundamentals_bootstrap.nse_official_direct_access_authorized' => true,
            'fundamentals_bootstrap.nse_official_feed_url' => null,
        ]);
        $filing = [
            'seq_Id' => 'bad-url',
            'symbol' => 'ALBERTDAVD',
            'consolidated' => 'Standalone',
            'xbrl' => 'https://127.0.0.1/private.xml',
        ];
        Http::fake([
            'www.nseindia.com/api/integrated-filing-results*' => Http::response(['data' => [$filing]], 200),
        ]);

        $source = new NseOfficialFundamentalHistoricalSource(new NseIntegratedFilingClient(new NseXbrlFactsParser));
        $this->assertFalse($source->supports(new Stock(['symbol' => 'BSECO', 'exchange' => 'BSE'])));
        $rows = $source->fetch(new Stock(['symbol' => 'ALBERTDAVD', 'exchange' => 'NSE']), 'quarterly');

        $this->assertSame([], $rows);
        Http::assertSentCount(1);
    }
}
