<?php

namespace Tests\Unit\Fundamentals;

use App\Services\Fundamentals\Historical\NseXbrlFactsParser;
use RuntimeException;
use Tests\TestCase;

class NseXbrlFactsParserTest extends TestCase
{
    private function document(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<xbrli:xbrl xmlns:xbrli="http://www.xbrl.org/2003/instance"
    xmlns:xbrldi="http://xbrl.org/2006/xbrldi"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:ind="http://www.sebi.gov.in/xbrl/2026-01-31/in-capmkt"
    xmlns:iso4217="http://www.xbrl.org/2003/iso4217">
  <xbrli:context id="quarter">
    <xbrli:entity><xbrli:identifier scheme="test">ISSUER</xbrli:identifier></xbrli:entity>
    <xbrli:period><xbrli:startDate>2026-01-01</xbrli:startDate><xbrli:endDate>2026-03-31</xbrli:endDate></xbrli:period>
  </xbrli:context>
  <xbrli:context id="ytd">
    <xbrli:entity><xbrli:identifier scheme="test">ISSUER</xbrli:identifier></xbrli:entity>
    <xbrli:period><xbrli:startDate>2025-04-01</xbrli:startDate><xbrli:endDate>2026-03-31</xbrli:endDate></xbrli:period>
  </xbrli:context>
  <xbrli:context id="instant">
    <xbrli:entity><xbrli:identifier scheme="test">ISSUER</xbrli:identifier></xbrli:entity>
    <xbrli:period><xbrli:instant>2026-03-31</xbrli:instant></xbrli:period>
  </xbrli:context>
  <xbrli:context id="dimensioned">
    <xbrli:entity><xbrli:identifier scheme="test">ISSUER</xbrli:identifier>
      <xbrli:segment><xbrldi:explicitMember dimension="ind:OperatingSegmentsAxis">ind:SegmentA</xbrldi:explicitMember></xbrli:segment>
    </xbrli:entity>
    <xbrli:period><xbrli:startDate>2026-01-01</xbrli:startDate><xbrli:endDate>2026-03-31</xbrli:endDate></xbrli:period>
  </xbrli:context>
  <xbrli:unit id="INR"><xbrli:measure>iso4217:INR</xbrli:measure></xbrli:unit>
  <ind:RevenueFromOperations contextRef="quarter" unitRef="INR" decimals="-3">858634000</ind:RevenueFromOperations>
  <ind:ProfitLossForPeriod contextRef="quarter" unitRef="INR" decimals="-3">-214344000</ind:ProfitLossForPeriod>
  <ind:RevenueFromOperations contextRef="ytd" unitRef="INR" decimals="-3">3335981000</ind:RevenueFromOperations>
  <ind:Equity contextRef="instant" unitRef="INR" decimals="-3">3915363000</ind:Equity>
  <ind:RevenueFromOperations contextRef="dimensioned" unitRef="INR" decimals="-3">999</ind:RevenueFromOperations>
</xbrli:xbrl>
XML;
    }

    public function test_parses_only_comparable_quarter_and_instant_facts_without_dimensions(): void
    {
        $rows = (new NseXbrlFactsParser)->parse($this->document(), [
            'seq_Id' => '157153',
            'consolidated' => 'Standalone',
            'xbrl' => 'https://nsearchives.nseindia.com/corporate/xbrl/sample.xml',
            'qe_Date' => '31-MAR-2026',
            'broadcast_Date' => '13-May-2026 13:20:09',
        ], 'quarterly');

        $this->assertCount(3, $rows);
        $byKey = collect($rows)->keyBy('fact_key');
        $this->assertSame('858634000', $byKey['revenue']['value']);
        $this->assertSame('2026-01-01', $byKey['revenue']['period_start']);
        $this->assertSame('2026-03-31', $byKey['revenue']['period_end']);
        $this->assertSame('standalone', $byKey['revenue']['statement_basis']);
        $this->assertSame('2026-05-13', $byKey['revenue']['availability_date']);
        $this->assertSame('non_pit', $byKey['revenue']['source_meta']['availability_quality']);
        $this->assertSame('157153', $byKey['revenue']['source_meta']['index_entry_id']);
        $this->assertSame(64, strlen($byKey['revenue']['source_meta']['document_sha256']));
        $this->assertSame('3915363000', $byKey['equity']['value']);
        $this->assertNull($byKey['equity']['period_start']);
    }

    public function test_annual_mode_keeps_full_year_duration_and_march_instant_only(): void
    {
        $rows = (new NseXbrlFactsParser)->parse($this->document(), [
            'seq_Id' => '157153',
            'consolidated' => 'Consolidated',
            'broadcast_Date' => '13-May-2026 13:20:09',
        ], 'annual');

        $this->assertCount(2, $rows);
        $byKey = collect($rows)->keyBy('fact_key');
        $this->assertSame('3335981000', $byKey['revenue']['value']);
        $this->assertSame('2025-04-01', $byKey['revenue']['period_start']);
        $this->assertSame('consolidated', $byKey['revenue']['statement_basis']);
        $this->assertSame('3915363000', $byKey['equity']['value']);
        $this->assertNull($byKey['equity']['period_start']);
    }

    public function test_parses_inline_xbrl_html_values_with_scale_and_sign(): void
    {
        $html = <<<'HTML'
<html xmlns:ix="http://www.xbrl.org/2013/inlineXBRL" xmlns:xbrli="http://www.xbrl.org/2003/instance" xmlns:iso4217="http://www.xbrl.org/2003/iso4217" xmlns:ind="http://www.sebi.gov.in/xbrl/2026-01-31/in-capmkt">
<head><meta charset="utf-8"></head>
<body>
<ix:header><ix:resources>
<xbrli:context id="q"><xbrli:entity><xbrli:identifier scheme="test">ISSUER</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:startDate>2026-01-01</xbrli:startDate><xbrli:endDate>2026-03-31</xbrli:endDate></xbrli:period></xbrli:context>
<xbrli:unit id="INR"><xbrli:measure>iso4217:INR</xbrli:measure></xbrli:unit>
</ix:resources></ix:header>
<table><tr><td><ix:nonFraction name="ind:RevenueFromOperations" contextRef="q" unitRef="INR" scale="3" decimals="-3">858,634</ix:nonFraction></td></tr>
<tr><td><ix:nonFraction name="ind:ProfitLossForPeriod" contextRef="q" unitRef="INR" scale="3" sign="-" decimals="-3">214,344</ix:nonFraction></td></tr></table>
</body></html>
HTML;
        $rows = (new NseXbrlFactsParser)->parse($html, [
            'seq_Id' => 'inline-1',
            'consolidated' => 'Standalone',
            'xbrl' => 'https://nsearchives.nseindia.com/corporate/ixbrl/example_iXBRL_WEB.html',
            'broadcast_Date' => '13-May-2026 13:20:09',
        ], 'quarterly');

        $byKey = collect($rows)->keyBy('fact_key');
        $this->assertSame('858634000', $byKey['revenue']['value']);
        $this->assertSame('-214344000', $byKey['net_income']['value']);
        $this->assertSame('2026-03-31', $byKey['revenue']['period_end']);
    }

    public function test_rejects_non_xbrl_and_unknown_cadence(): void
    {
        $parser = new NseXbrlFactsParser;
        $this->expectException(RuntimeException::class);
        $parser->parse('<html>not a filing</html>', ['seq_Id' => 'x'], 'quarterly');
    }

    public function test_rejects_non_inr_and_oversized_documents_without_guessing(): void
    {
        $xml = str_replace('<xbrli:measure>iso4217:INR</xbrli:measure>', '<xbrli:measure>iso4217:USD</xbrli:measure>', $this->document());
        $this->assertSame([], (new NseXbrlFactsParser)->parse($xml, ['seq_Id' => '157153'], 'quarterly'));

        $this->expectException(RuntimeException::class);
        (new NseXbrlFactsParser)->parse(str_repeat('x', 1_500_001), [], 'quarterly');
    }
}
