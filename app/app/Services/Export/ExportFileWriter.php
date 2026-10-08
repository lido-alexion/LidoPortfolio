<?php

namespace App\Services\Export;

use RuntimeException;
use ZipArchive;

class ExportFileWriter
{
    public function csv(array $columns, iterable $rows, string $path, array $metadata = [], ?callable $shouldCancel = null, ?int $expectedRows = null): void
    {
        $started = hrtime(true);
        $this->assertLimits($columns, $expectedRows ?? (is_array($rows) ? count($rows) : null));
        $writtenRows = 0;
        $handle = fopen($path, 'wb');
        if ($handle === false) throw new RuntimeException('Unable to create export file.');
        try {
            $writeRecord = function (array $record) use ($handle): void {
                if (fputcsv($handle, $record) === false) throw new RuntimeException('Unable to write export file.');
                $position = ftell($handle);
                if ($position === false || $position > config('exports.max_file_bytes', 52428800)) {
                    throw new RuntimeException('Export exceeds the maximum file size. Narrow the scope and try again.');
                }
            };
            if ($metadata !== []) {
                $writeRecord(['# StoX export metadata']);
                foreach ($metadata as $key => $value) $writeRecord(['# '.$key, $this->safeCell(is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_SLASHES))]);
                $writeRecord([]);
            }
            $writeRecord($columns);
            foreach ($rows as $row) {
                if ($shouldCancel && $shouldCancel()) throw new ExportCancelledException('Export was cancelled.');
                if (++$writtenRows > config('exports.max_rows', 50000)) throw new RuntimeException('Export exceeds the maximum row count. Narrow the scope and try again.');
                $writeRecord(array_map(fn ($column) => $this->safeCell(data_get($row, $column)), $columns));
                $this->assertRuntime($started);
            }
        } finally { fclose($handle); }
    }

    public function xlsx(array $sheets, string $path, ?callable $shouldCancel = null): void
    {
        $started = hrtime(true);
        if (count($sheets) > config('exports.max_sheets', 10)) throw new RuntimeException('Export exceeds the maximum workbook sheet count. Narrow the scope and try again.');
        if (! class_exists(ZipArchive::class)) throw new RuntimeException('XLSX export is unavailable on this runtime.');
        $temporaryPath = $path.'.'.bin2hex(random_bytes(8)).'.partial';
        $zip = new ZipArchive();
        if ($zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to create XLSX file.');
        $completed = false;
        $zipOpen = true;
        $worksheetPaths = [];
        $temporaryBytes = 0;
        try {
        $usedNames = [];
        $estimatedRows = 0;
        $fieldCount = 0;
        $estimatedCells = 0;
        foreach (array_values($sheets) as $sheet) {
            $sheetRows = $sheet['row_count'] ?? (is_array($sheet['rows']) ? count($sheet['rows']) : null);
            $this->assertLimits($sheet['columns'], $sheetRows);
            if ($sheetRows !== null) {
                $estimatedRows += $sheetRows;
                $estimatedCells += ($sheetRows + 1) * count($sheet['columns']);
            }
            $fieldCount += count($sheet['columns']);
            $estimatedCells += (count($sheet['metadata'] ?? []) * 2) + (empty($sheet['metadata']) ? 0 : 4);
        }
        if ($estimatedRows > config('exports.max_rows', 50000)) throw new RuntimeException('Export exceeds the maximum row count. Narrow the scope and try again.');
        if ($fieldCount > config('exports.max_fields', 100)) throw new RuntimeException('Export exceeds the maximum field count. Narrow the selected fields.');
        if ($estimatedCells > config('exports.max_workbook_cells', 500000)) throw new RuntimeException('Export exceeds the maximum workbook complexity. Remove sheets or fields and try again.');
        $writtenRows = 0;
        $writtenCells = 0;
        foreach (array_values($sheets) as $index => $sheet) {
            $worksheetPath = $temporaryPath.'.sheet'.($index + 1).'.xml';
            $worksheet = fopen($worksheetPath, 'xb');
            if ($worksheet === false) throw new RuntimeException('Unable to create XLSX worksheet temporary file.');
            $worksheetPaths[] = $worksheetPath;
            try {
                $this->writeWorksheetChunk($worksheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>', $temporaryBytes);
                $rowIndex = 0;
                $writeRow = function (array $row, bool $isData = false) use ($worksheet, &$temporaryBytes, &$rowIndex, &$writtenRows, &$writtenCells, $shouldCancel, $started): void {
                    if ($shouldCancel && $shouldCancel()) throw new ExportCancelledException('Export was cancelled.');
                    if ($isData && ++$writtenRows > config('exports.max_rows', 50000)) throw new RuntimeException('Export exceeds the maximum row count. Narrow the scope and try again.');
                    $writtenCells += count($row);
                    if ($writtenCells > config('exports.max_workbook_cells', 500000)) throw new RuntimeException('Export exceeds the maximum workbook complexity. Remove sheets or fields and try again.');
                    $xml = '<row r="'.($rowIndex + 1).'">';
                    foreach ($row as $columnIndex => $value) {
                        $numeric = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && ! preg_match('/^0\\d/', $value));
                        $safe = $numeric ? (string) $value : $this->safeCell($value);
                        $cell = htmlspecialchars($safe, ENT_XML1);
                        $ref = $this->column($columnIndex).($rowIndex + 1);
                        $xml .= $numeric ? '<c r="'.$ref.'"><v>'.$cell.'</v></c>' : '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$cell.'</t></is></c>';
                    }
                    $this->writeWorksheetChunk($worksheet, $xml.'</row>', $temporaryBytes);
                    $rowIndex++;
                    $this->assertRuntime($started);
                };
                $writeRow($sheet['columns']);
                foreach ($sheet['rows'] as $sourceRow) {
                    $row = [];
                    foreach ($sheet['columns'] as $column) $row[] = data_get($sourceRow, $column);
                    $writeRow($row, true);
                }
                if (! empty($sheet['metadata'])) {
                    $writeRow([]);
                    $writeRow(['Metadata', 'Value']);
                    foreach ($sheet['metadata'] as $key => $value) $writeRow([$key, is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_SLASHES)]);
                }
                $this->writeWorksheetChunk($worksheet, '</sheetData></worksheet>', $temporaryBytes);
            } finally {
                fclose($worksheet);
            }
            if (! $zip->addFile($worksheetPath, 'xl/worksheets/sheet'.($index + 1).'.xml')) throw new RuntimeException('Unable to add XLSX worksheet.');
        }
        $rels = ''; $workbookSheets = '';
        foreach (array_keys($sheets) as $index) {
            $id = $index + 1;
            $name = htmlspecialchars($this->uniqueSheetName((string) ($sheets[$index]['name'] ?? 'Sheet'.$id), $usedNames, $id), ENT_XML1);
            $workbookSheets .= '<sheet name="'.$name.'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
            $rels .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.implode('', array_map(fn ($index) => '<Override PartName="/xl/worksheets/sheet'.($index + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($sheets))).'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$workbookSheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $closed = $zip->close();
        $zipOpen = false;
        if (! $closed) throw new RuntimeException('Unable to finalize XLSX file.');
        if (filesize($temporaryPath) > config('exports.max_file_bytes', 52428800)) throw new RuntimeException('Export exceeds the maximum file size. Narrow the scope and try again.');
        if (! rename($temporaryPath, $path)) throw new RuntimeException('Unable to finalize XLSX file.');
        $completed = true;
        } finally {
            if ($zipOpen) $zip->close();
            foreach ($worksheetPaths as $worksheetPath) if (is_file($worksheetPath)) @unlink($worksheetPath);
            if (! $completed && is_file($temporaryPath)) @unlink($temporaryPath);
        }
    }

    private function writeWorksheetChunk($handle, string $chunk, int &$temporaryBytes): void
    {
        $temporaryBytes += strlen($chunk);
        if ($temporaryBytes > config('exports.max_temporary_bytes', 268435456)) {
            throw new RuntimeException('XLSX worksheet data exceeds the temporary storage limit. Narrow the scope and try again.');
        }
        $offset = 0;
        $length = strlen($chunk);
        while ($offset < $length) {
            $written = fwrite($handle, substr($chunk, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Unable to write XLSX worksheet temporary file.');
            $offset += $written;
        }
    }

    private function safeCell(mixed $value): string
    {
        $numeric = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && ! preg_match('/^0\d/', $value));
        $value = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES);
        return ! $numeric && preg_match('/^[\s\x00-\x20]*[=+\-@]/', $value) ? "'".$value : $value;
    }

    private function assertLimits(array $columns, ?int $rowCount): void
    {
        if (count($columns) > config('exports.max_fields', 100)) throw new RuntimeException('Export exceeds the maximum field count. Narrow the selected fields.');
        if ($rowCount !== null && $rowCount > config('exports.max_rows', 50000)) throw new RuntimeException('Export exceeds the maximum row count. Narrow the scope and try again.');
    }

    private function assertRuntime(int $started): void
    {
        if ((hrtime(true) - $started) / 1_000_000_000 > config('exports.max_runtime_seconds', 120)) throw new RuntimeException('Export exceeded the maximum generation time. Narrow the scope and try again.');
        if (memory_get_usage(false) > config('exports.max_memory_bytes', 268435456)) throw new RuntimeException('Export exceeded the maximum memory use. Narrow the scope and try again.');
    }

    private function uniqueSheetName(string $name, array &$used, int $number): string
    {
        $name = trim(preg_replace('/[\\\\\/\?\*\[\]:]/u', '-', $name) ?? '');
        $name = trim($name, "' ");
        if ($name === '') $name = 'Sheet '.$number;
        $base = mb_substr($name, 0, 31); $candidate = $base; $suffix = 2;
        while (in_array(mb_strtolower($candidate), $used, true)) {
            $tail = ' ('.$suffix++.')';
            $candidate = mb_substr($base, 0, 31 - mb_strlen($tail)).$tail;
        }
        $used[] = mb_strtolower($candidate);
        return $candidate;
    }

    private function column(int $index): string { $name = ''; do { $name = chr(65 + ($index % 26)).$name; $index = intdiv($index, 26) - 1; } while ($index >= 0); return $name; }
}
