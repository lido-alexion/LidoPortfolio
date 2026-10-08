<?php

namespace Tests\Unit\Export;

use App\Services\Export\ExportFileWriter;
use Tests\TestCase;

class ExportFileWriterTest extends TestCase
{
    public function test_csv_preserves_precision_and_sanitizes_formula_cells(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stox-export-');
        app(ExportFileWriter::class)->csv(['symbol', 'value'], [['symbol' => 'ABC', 'value' => '123.4567890123'], ['symbol' => '=BAD()', 'value' => 0]], $path);
        $contents = file_get_contents($path);
        @unlink($path);
        $this->assertStringContainsString('123.4567890123', $contents);
        $this->assertStringContainsString("'=BAD()", $contents);
    }

    public function test_csv_keeps_negative_numbers_numeric_and_neutralizes_formula_text_with_leading_whitespace(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stox-export-');
        app(ExportFileWriter::class)->csv(['amount', 'label'], [['amount' => '-123.450067', 'label' => "  =HYPERLINK('https://bad')"]], $path);
        $contents = file_get_contents($path);
        @unlink($path);
        $this->assertStringContainsString('-123.450067', $contents);
        $this->assertStringContainsString("'  =HYPERLINK", $contents);
    }

    public function test_xlsx_writer_sanitizes_duplicate_sheet_titles_and_never_emits_formula_cells(): void
    {
        if (! class_exists(\ZipArchive::class)) $this->markTestSkipped('ZipArchive is unavailable.');
        $path = tempnam(sys_get_temp_dir(), 'stox-export-');
        app(ExportFileWriter::class)->xlsx([
            ['name' => "bad/name", 'columns' => ['value', 'label'], 'rows' => [['value' => '-42.001', 'label' => '=1+1']]],
            ['name' => "bad/name", 'columns' => ['value'], 'rows' => [['value' => 7]]],
        ], $path);
        $zip = new \ZipArchive(); $zip->open($path);
        $workbook = $zip->getFromName('xl/workbook.xml');
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close(); @unlink($path);
        $this->assertStringContainsString('bad-name', $workbook);
        $this->assertStringContainsString('bad-name (2)', $workbook);
        $this->assertStringContainsString('<v>-42.001</v>', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString("'=1+1", $sheet);
    }

    public function test_xlsx_basket_sheet_keeps_dataset_metadata_in_the_same_sheet(): void
    {
        if (! class_exists(\ZipArchive::class)) $this->markTestSkipped('ZipArchive is unavailable.');
        $path = tempnam(sys_get_temp_dir(), 'stox-export-');
        app(ExportFileWriter::class)->xlsx([[
            'name' => 'Daily snapshots', 'columns' => ['snapshot_date'], 'rows' => [['snapshot_date' => '2026-10-07']],
            'metadata' => ['dataset' => 'portfolio-snapshots', 'scope' => 'full', 'source' => 'portfolio snapshots'],
        ]], $path);
        $zip = new \ZipArchive(); $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close(); @unlink($path);
        $this->assertStringContainsString('portfolio-snapshots', $sheet);
        $this->assertStringContainsString('Daily snapshots', $workbook);
        $this->assertSame(1, substr_count($workbook, '<sheet '));
        $this->assertStringContainsString('Metadata', $sheet);
        $this->assertStringContainsString('scope', $sheet);
    }

    public function test_writer_enforces_the_configured_row_limit(): void
    {
        config(['exports.max_rows' => 1]);
        $path = tempnam(sys_get_temp_dir(), 'stox-export-');
        try {
            app(ExportFileWriter::class)->csv(['value'], [['value' => 1], ['value' => 2]], $path);
            $this->fail('Expected the row limit to reject the export.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('maximum row count', $exception->getMessage());
        } finally { @unlink($path); }
    }

    public function test_xlsx_closes_and_removes_output_when_cancelled(): void
    {
        if (! class_exists(\ZipArchive::class)) $this->markTestSkipped('ZipArchive is unavailable.');
        $path = tempnam(sys_get_temp_dir(), 'stox-export-');
        @unlink($path);
        try {
            app(ExportFileWriter::class)->xlsx([['columns' => ['value'], 'rows' => [['value' => 1]]]], $path, fn () => true);
            $this->fail('Expected cancellation to stop the export.');
        } catch (\App\Services\Export\ExportCancelledException) {
            $this->assertFileDoesNotExist($path);
            $this->assertSame([], glob($path.'.*.partial') ?: []);
        } finally { @unlink($path); foreach (glob($path.'.*.partial') ?: [] as $partial) @unlink($partial); }
    }

    public function test_xlsx_enforces_aggregate_row_and_field_limits(): void
    {
        if (! class_exists(\ZipArchive::class)) $this->markTestSkipped('ZipArchive is unavailable.');
        $path = tempnam(sys_get_temp_dir(), 'stox-export-');
        @unlink($path);
        config(['exports.max_rows' => 1]);
        try {
            app(ExportFileWriter::class)->xlsx([
                ['columns' => ['value'], 'rows' => [['value' => 1]]],
                ['columns' => ['value'], 'rows' => [['value' => 2]]],
            ], $path);
            $this->fail('Expected aggregate rows to exceed the limit.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('maximum row count', $exception->getMessage());
            $this->assertFileDoesNotExist($path);
        } finally { @unlink($path); foreach (glob($path.'.*.partial') ?: [] as $partial) @unlink($partial); }
    }

    public function test_xlsx_enforces_aggregate_field_and_cell_limits(): void
    {
        if (! class_exists(\ZipArchive::class)) $this->markTestSkipped('ZipArchive is unavailable.');
        $writer = app(ExportFileWriter::class);
        $path = tempnam(sys_get_temp_dir(), 'stox-export-');
        @unlink($path);
        config(['exports.max_fields' => 1]);
        try {
            $writer->xlsx([
                ['columns' => ['one'], 'rows' => []],
                ['columns' => ['two'], 'rows' => []],
            ], $path);
            $this->fail('Expected aggregate fields to exceed the limit.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('maximum field count', $exception->getMessage());
            $this->assertFileDoesNotExist($path);
        }
        config(['exports.max_fields' => 100, 'exports.max_workbook_cells' => 2]);
        try {
            $writer->xlsx([['columns' => ['one'], 'rows' => [['one' => 1], ['one' => 2]]]], $path);
            $this->fail('Expected workbook cells to exceed the limit.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('maximum workbook complexity', $exception->getMessage());
            $this->assertFileDoesNotExist($path);
        } finally { @unlink($path); foreach (glob($path.'.*.partial') ?: [] as $partial) @unlink($partial); }
    }

    public function test_xlsx_removes_output_when_runtime_limit_is_exceeded(): void
    {
        if (! class_exists(\ZipArchive::class)) $this->markTestSkipped('ZipArchive is unavailable.');
        $path = tempnam(sys_get_temp_dir(), 'stox-export-');
        @unlink($path);
        config(['exports.max_memory_bytes' => 0]);
        try {
            app(ExportFileWriter::class)->xlsx([['columns' => ['value'], 'rows' => [['value' => 1]]]], $path);
            $this->fail('Expected runtime limits to stop the export.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('maximum memory use', $exception->getMessage());
            $this->assertFileDoesNotExist($path);
        } finally { @unlink($path); foreach (glob($path.'.*.partial') ?: [] as $partial) @unlink($partial); }
    }
}
