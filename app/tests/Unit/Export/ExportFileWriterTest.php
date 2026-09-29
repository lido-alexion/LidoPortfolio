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
}
