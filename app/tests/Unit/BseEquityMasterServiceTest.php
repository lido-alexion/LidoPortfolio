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
    }

    public function test_list_api_url_falls_back_when_config_missing(): void
    {
        config(['portfolio.stock_master.bse_list_api_url' => null]);

        $service = app(BseEquityMasterService::class);

        $this->assertSame(BseEquityMasterService::DEFAULT_LIST_API_URL, $service->listApiUrl());
    }
}
