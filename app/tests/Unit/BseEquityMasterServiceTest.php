<?php

namespace Tests\Unit;

use App\Services\BseEquityMasterService;
use Tests\TestCase;

class BseEquityMasterServiceTest extends TestCase
{
    public function test_parse_api_rows_extracts_symbol_name_isin(): void
    {
        $service = app(BseEquityMasterService::class);
        $rows = $service->parseApiRows([
            [
                'scrip_name' => 'Reliance Industries Ltd',
                'scrip_id' => 'RELIANCE',
                'ISIN' => 'INE002A01018',
                'STATUS' => 'Active',
            ],
            [
                'SCRIP_NAME' => 'Numeric Only',
                'SCRIP_CD' => '500325',
                'ISIN' => 'INEXXX',
                'STATUS' => 'Active',
            ],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('RELIANCE', $rows[0]['symbol']);
        $this->assertSame('INE002A01018', $rows[0]['isin']);
        $this->assertNull($rows[0]['scrip_code']);
    }

    public function test_parse_api_rows_includes_scrip_code_when_present(): void
    {
        $service = app(BseEquityMasterService::class);
        $rows = $service->parseApiRows([
            [
                'scrip_name' => 'Reliance Industries Ltd',
                'scrip_id' => 'RELIANCE',
                'scrip_cd' => '500325',
                'ISIN' => 'INE002A01018',
                'STATUS' => 'Active',
            ],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('RELIANCE', $rows[0]['symbol']);
        $this->assertSame('500325', $rows[0]['scrip_code']);
    }

    public function test_actual_bse_api_schema_preserves_identity_and_filters_status(): void
    {
        $row = [
            'SCRIP_CD' => '500002',
            'Scrip_Name' => 'ABB India Limited',
            'Status' => 'Active',
            'GROUP' => 'A',
            'ISIN_NUMBER' => ' ine117a01022 ',
            'scrip_id' => 'ABB',
            'Segment' => 'Equity',
        ];
        $service = app(BseEquityMasterService::class);

        $this->assertSame([[
            'symbol' => 'ABB',
            'name' => 'ABB India Limited',
            'isin' => 'INE117A01022',
            'scrip_code' => '500002',
        ]], $service->parseApiRows([
            $row,
            array_replace($row, ['Status' => 'Suspended']),
            array_replace($row, ['Status' => 'Inactive']),
        ]));

        foreach (['', 'NA', '-', 'INEXXX'] as $invalid) {
            $parsed = $service->parseApiRows([array_replace($row, ['ISIN_NUMBER' => $invalid])]);
            $this->assertNull($parsed[0]['isin']);
        }
    }

    public function test_csv_isin_number_header_uses_same_identity_validation(): void
    {
        $rows = app(BseEquityMasterService::class)->parseCsvRows(
            "SECURITY ID,NAME,ISIN_NUMBER,SCRIP_CD\nABB,ABB India,ine117a01022,500002\nOTHER,Other,NA,500003"
        );

        $this->assertSame('INE117A01022', $rows[0]['isin']);
        $this->assertSame('500002', $rows[0]['scrip_code']);
        $this->assertNull($rows[1]['isin']);
    }

    public function test_list_api_url_falls_back_when_config_missing(): void
    {
        config(['portfolio.stock_master.bse_list_api_url' => null]);

        $service = app(BseEquityMasterService::class);

        $this->assertSame(BseEquityMasterService::DEFAULT_LIST_API_URL, $service->listApiUrl());
    }
}
