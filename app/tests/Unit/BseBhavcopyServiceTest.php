<?php

namespace Tests\Unit;

use App\Services\BseBhavcopyService;
use Tests\TestCase;

class BseBhavcopyServiceTest extends TestCase
{
    public function test_parse_udiff_csv_maps_equity_row(): void
    {
        $csv = <<<'CSV'
TradDt,BizDt,Sgmt,FinInstrmTp,FinInstrmId,ISIN,TckrSymb,SctySrs,OpnPric,HghPric,LwPric,ClsPric,TtlTradgVol
2025-07-10,2025-07-10,CM,STK,532540,INE467B01029,TCS,EQ,3500.00,3520.00,3490.00,3510.00,12000
CSV;

        $service = app(BseBhavcopyService::class);
        $rows = $service->parseUdiffCsv($csv);

        $this->assertCount(1, $rows);
        $this->assertSame('2025-07-10', $rows[0]['price_date']);
        $this->assertSame('532540', $rows[0]['scrip_code']);
        $this->assertSame('TCS', $rows[0]['symbol']);
        $this->assertSame(3510.0, $rows[0]['close_price']);
        $this->assertSame(12000, $rows[0]['volume']);
    }

    public function test_parse_udiff_csv_skips_non_equity_series(): void
    {
        $csv = <<<'CSV'
TradDt,BizDt,FinInstrmId,TckrSymb,SctySrs,ClsPric
2025-07-10,2025-07-10,123456,ABC,MF,10.00
CSV;

        $service = app(BseBhavcopyService::class);
        $rows = $service->parseUdiffCsv($csv);

        $this->assertSame([], $rows);
    }

    public function test_download_url_uses_udiff_filename(): void
    {
        $service = app(BseBhavcopyService::class);
        $url = $service->downloadUrlForDate(\Carbon\Carbon::parse('2025-07-10'));

        $this->assertStringContainsString('BhavCopy_BSE_CM_0_0_0_20250710_F_0000.CSV', $url);
    }
}
