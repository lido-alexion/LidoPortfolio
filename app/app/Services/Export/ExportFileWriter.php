<?php

namespace App\Services\Export;

use RuntimeException;
use ZipArchive;

class ExportFileWriter
{
    public function csv(array $columns, array $rows, string $path): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) throw new RuntimeException('Unable to create export file.');
        fputcsv($handle, $columns);
        foreach ($rows as $row) fputcsv($handle, array_map([$this, 'safeCell'], array_map(fn ($column) => data_get($row, $column), $columns)));
        fclose($handle);
    }

    public function xlsx(array $sheets, string $path): void
    {
        if (! class_exists(ZipArchive::class)) throw new RuntimeException('XLSX export is unavailable on this runtime.');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to create XLSX file.');
        $sheetXml = [];
        foreach (array_values($sheets) as $index => $sheet) {
            $rows = [$sheet['columns'], ...array_map(fn ($row) => array_map(fn ($column) => data_get($row, $column), $sheet['columns']), $sheet['rows'])];
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach ($rows as $rowIndex => $row) { $xml .= '<row r="'.($rowIndex + 1).'">'; foreach ($row as $columnIndex => $value) { $cell = htmlspecialchars($this->safeCell($value), ENT_XML1); $xml .= '<c r="'.($this->column($columnIndex).($rowIndex + 1)).'" t="inlineStr"><is><t>'.$cell.'</t></is></c>'; } $xml .= '</row>'; }
            $sheetXml[] = $xml.'</sheetData></worksheet>';
            $zip->addFromString("xl/worksheets/sheet".($index + 1).'.xml', end($sheetXml));
        }
        $rels = ''; $workbookSheets = '';
        foreach (array_keys($sheets) as $index) { $id = $index + 1; $name = htmlspecialchars((string) ($sheets[$index]['name'] ?? 'Sheet'.$id), ENT_XML1); $workbookSheets .= '<sheet name="'.$name.'" sheetId="'.$id.'" r:id="rId'.$id.'"/>'; $rels .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>'; }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.implode('', array_map(fn ($index) => '<Override PartName="/xl/worksheets/sheet'.($index + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($sheets))).'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$workbookSheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->close();
    }

    private function safeCell(mixed $value): string
    {
        $value = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES);
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }

    private function column(int $index): string { $name = ''; do { $name = chr(65 + ($index % 26)).$name; $index = intdiv($index, 26) - 1; } while ($index >= 0); return $name; }
}
