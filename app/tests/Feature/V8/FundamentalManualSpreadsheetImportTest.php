<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class FundamentalManualSpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_imports_only_data_sheet_and_accepts_sparse_year_columns(): void
    {
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);
        Stock::query()->create([
            'symbol' => 'ACME',
            'exchange' => 'NSE',
            'name' => 'Acme Limited',
        ]);

        $xlsx = UploadedFile::fake()->createWithContent('manual.xlsx', $this->workbook());
        $this->actingAs($admin)->withProfileHeader($admin)
            ->postJson('/api/v1/admin/fundamentals/manual-import', [
                'stock_symbol' => 'ACME',
                'exchange' => 'NSE',
                'statement_basis' => 'consolidated',
                'confirm_company' => '1',
                'file' => $xlsx,
            ])
            ->assertOk()
            ->assertJsonPath('data.stock.symbol', 'ACME')
            ->assertJsonPath('data.import.imported_rows', 2)
            ->assertJsonPath('data.import.template_version', '2.1');

        $facts = \Illuminate\Support\Facades\DB::table('stox_fundamental_facts')
            ->where('stock_id', Stock::query()->where('symbol', 'ACME')->value('id'))
            ->where('provider', 'manual_spreadsheet')
            ->where('fact_key', 'revenue')
            ->orderBy('period_end')
            ->get();

        $this->assertCount(2, $facts);
        $this->assertSame('2023-03-31', substr($facts[0]->period_end, 0, 10));
        $this->assertSame('10000000.000000', number_format((float) $facts[0]->value, 6, '.', ''));
        $this->assertSame('2025-03-31', substr($facts[1]->period_end, 0, 10));
        $this->assertSame('25000000.000000', number_format((float) $facts[1]->value, 6, '.', ''));
    }

    private function workbook(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fundamentals-xlsx-');
        $zip = new ZipArchive;
        $this->assertSame(true, $zip->open($path, ZipArchive::OVERWRITE));

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>
XML);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Summary" sheetId="1" r:id="rId1"/>
    <sheet name="Data Sheet" sheetId="9" r:id="rId9"/>
  </sheets>
</workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/>
  <Relationship Id="rId9" Target="worksheets/sheet9.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/>
</Relationships>
XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>WRONG TAB</t></is></c></row></sheetData></worksheet>');
        $zip->addFromString('xl/sharedStrings.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="7" uniqueCount="7">
  <si><t>COMPANY NAME</t></si><si><t>ACME LIMITED</t></si><si><t>LATEST VERSION</t></si>
  <si><t>PROFIT &amp; LOSS</t></si><si><t>Report Date</t></si><si><t>Sales</t></si><si><t>CURRENT VERSION</t></si>
</sst>
XML);
        $zip->addFromString('xl/worksheets/sheet9.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    <row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>
    <row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>2.1</v></c></row>
    <row r="3"><c r="A3" t="s"><v>6</v></c><c r="B3"><v>2.1</v></c></row>
    <row r="15"><c r="A15" t="s"><v>3</v></c></row>
    <row r="16"><c r="A16" t="s"><v>4</v></c><c r="B16"><v>45016</v></c><c r="D16"><v>45747</v></c></row>
    <row r="17"><c r="A17" t="s"><v>5</v></c><c r="B17"><v>1</v></c><c r="D17"><v>2.5</v></c></row>
  </sheetData>
</worksheet>
XML);
        $zip->close();
        $contents = file_get_contents($path);
        @unlink($path);
        $this->assertIsString($contents);

        return $contents;
    }
}
