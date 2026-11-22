<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

/** Strict, bounded, value-only parser for user supplied Zerodha Console tradebooks. */
class KiteConsoleTradebookParser
{
    private const MAX_BYTES = 10_485_760;

    private const MAX_EXPANDED_BYTES = 25_000_000;

    private const MAX_ROWS = 20_000;

    private const MAX_COLUMNS = 64;

    public function parse(string $path, string $extension): array
    {
        $size = @filesize($path);
        if (! is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
            $this->invalid('The tradebook must be between 1 byte and 10 MB.');
        }

        try {
            $rows = match (strtolower($extension)) {
                'csv', 'txt' => $this->csv($path),
                'xlsx' => $this->xlsx($path),
                'xls' => $this->spreadsheetXml($path),
                default => throw new RuntimeException('Upload a CSV or TXT file, an XLSX workbook, or Excel 2003 XML with an .xls extension.'),
            };

            return $this->trades($rows);
        } catch (RuntimeException $exception) {
            $this->invalid($exception->getMessage());
        }
    }

    private function csv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('The uploaded tradebook could not be read.');
        }
        try {
            $header = fgetcsv($handle, 65536);
            if (! is_array($header)) {
                throw new RuntimeException('The tradebook is empty.');
            }
            $rows = [$header];
            $count = 0;
            while (($row = fgetcsv($handle, 65536)) !== false) {
                if (++$count > self::MAX_ROWS) {
                    throw new RuntimeException('The tradebook exceeds 20,000 data rows.');
                }
                if (count($row) > self::MAX_COLUMNS) {
                    throw new RuntimeException('The tradebook exceeds 64 columns.');
                }
                if (count(array_filter($row, fn ($cell) => trim((string) $cell) !== '')) > 0) {
                    $rows[] = $row;
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function xlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('XLSX support is unavailable on this server.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true || $zip->numFiles < 1 || $zip->numFiles > 500) {
            throw new RuntimeException('Upload a valid, unencrypted XLSX workbook.');
        }
        try {
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! is_array($stat) || str_contains(strtolower($stat['name'] ?? ''), 'vbaproject') || str_starts_with($stat['name'] ?? '', 'xl/externalLinks/')) {
                    throw new RuntimeException('Macro-enabled or externally linked workbooks are not supported.');
                }
                $expanded += (int) ($stat['size'] ?? 0);
                if ($expanded > self::MAX_EXPANDED_BYTES) {
                    throw new RuntimeException('The workbook expands beyond the allowed size.');
                }
            }
            $workbook = $this->xml($zip->getFromName('xl/workbook.xml') ?: '');
            $rels = $this->xml($zip->getFromName('xl/_rels/workbook.xml.rels') ?: '');
            foreach ((new DOMXPath($rels))->query('//*[local-name()="Relationship"]') ?: [] as $relationship) {
                if ($relationship instanceof DOMElement && strcasecmp($relationship->getAttribute('TargetMode'), 'External') === 0) {
                    throw new RuntimeException('Externally linked workbooks are not supported.');
                }
            }
            $sheet = null;
            foreach ((new DOMXPath($workbook))->query('//*[local-name()="sheet"]') ?: [] as $candidate) {
                if ($candidate instanceof DOMElement) {
                    $sheet = $candidate;
                    break;
                }
            }
            if (! $sheet) {
                throw new RuntimeException('The workbook has no worksheet.');
            }
            $relId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $target = null;
            foreach ((new DOMXPath($rels))->query('//*[local-name()="Relationship"]') ?: [] as $rel) {
                if ($rel instanceof DOMElement && $rel->getAttribute('Id') === $relId) {
                    $target = $rel->getAttribute('Target');
                }
            }
            if (! $target || str_contains($target, '..')) {
                throw new RuntimeException('The worksheet reference is invalid.');
            }
            $sheetPath = 'xl/'.ltrim($target, '/');
            $sheetPath = str_replace('xl/xl/', 'xl/', $sheetPath);
            $shared = $this->sharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: '');
            $dateStyles = $this->dateStyles($zip->getFromName('xl/styles.xml') ?: '');
            $doc = $this->xml($zip->getFromName($sheetPath) ?: '');
            $rows = [];
            $xpath = new DOMXPath($doc);
            foreach ($xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $rowNode) {
                if (count($rows) > self::MAX_ROWS) {
                    throw new RuntimeException('The workbook exceeds 20,000 rows.');
                }
                $row = [];
                foreach ((new DOMXPath($doc))->query('./*[local-name()="c"]', $rowNode) ?: [] as $cell) {
                    if (! $cell instanceof DOMElement) {
                        continue;
                    }
                    if ($cell->getElementsByTagName('f')->length > 0) {
                        throw new RuntimeException('Formula cells are not accepted in a tradebook.');
                    }
                    $column = $this->columnIndex($cell->getAttribute('r'));
                    if ($column >= self::MAX_COLUMNS) {
                        throw new RuntimeException('The workbook exceeds 64 columns.');
                    }
                    $value = $cell->getElementsByTagName('v')->item(0)?->textContent ?? '';
                    if ($cell->getAttribute('t') === 's') {
                        $value = $shared[(int) $value] ?? '';
                    } elseif ($cell->getAttribute('t') === 'inlineStr') {
                        $value = $cell->getElementsByTagName('t')->item(0)?->textContent ?? '';
                    } elseif (($dateStyles[(int) $cell->getAttribute('s')] ?? false) === true && is_numeric($value)) {
                        $value = gmdate('Y-m-d', (int) floor(((float) $value - 25569) * 86400));
                    }
                    $row[$column] = $value;
                }
                if ($row !== []) {
                    ksort($row);
                    $rows[] = array_replace(array_fill(0, max(array_keys($row)) + 1, ''), $row);
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /** Supports the non-executable Excel 2003 SpreadsheetML .xls format only. */
    private function spreadsheetXml(string $path): array
    {
        $content = @file_get_contents($path);
        if (! is_string($content) || strlen($content) > self::MAX_BYTES || ! str_contains($content, '<Workbook')) {
            throw new RuntimeException('Legacy binary .xls files are not supported. Open the workbook in Excel or LibreOffice and use Save As to create an .xlsx copy. An .xls file must be Excel 2003 XML.');
        }
        $doc = $this->xml($content);
        $rows = [];
        foreach ((new DOMXPath($doc))->query('//*[local-name()="Worksheet"]/*[local-name()="Table"]/*[local-name()="Row"]') ?: [] as $rowNode) {
            if (count($rows) >= self::MAX_ROWS) {
                throw new RuntimeException('The workbook exceeds 20,000 rows.');
            }
            $row = [];
            $column = 0;
            foreach ((new DOMXPath($doc))->query('./*[local-name()="Cell"]', $rowNode) ?: [] as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }
                if ($cell->getAttributeNS('urn:schemas-microsoft-com:office:spreadsheet', 'Formula') !== '' || $cell->getElementsByTagNameNS('*', 'Formula')->length > 0) {
                    throw new RuntimeException('Formula cells are not accepted in a tradebook.');
                }
                $index = (int) ($cell->getAttributeNS('urn:schemas-microsoft-com:office:spreadsheet', 'Index') ?: ($column + 1)) - 1;
                if ($index >= self::MAX_COLUMNS) {
                    throw new RuntimeException('The workbook exceeds 64 columns.');
                }
                $row[$index] = $cell->getElementsByTagNameNS('*', 'Data')->item(0)?->textContent ?? '';
                $column = $index + 1;
            }
            if ($row !== []) {
                ksort($row);
                $rows[] = array_replace(array_fill(0, max(array_keys($row)) + 1, ''), $row);
            }
        }

        return $rows;
    }

    private function xml(string $content): DOMDocument
    {
        if ($content === '' || strlen($content) > self::MAX_EXPANDED_BYTES || stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
            throw new RuntimeException('The workbook contains invalid or unsafe XML.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            $doc->resolveExternals = false;
            $doc->substituteEntities = false;
            if (! $doc->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                throw new RuntimeException('The workbook contains invalid XML.');
            }

            return $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function sharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $strings = [];
        $doc = $this->xml($xml);
        foreach ((new DOMXPath($doc))->query('//*[local-name()="si"]') ?: [] as $item) {
            $strings[] = $item->textContent;
        }

        return $strings;
    }

    private function dateStyles(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $custom = [];
        $doc = $this->xml($xml);
        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//*[local-name()="numFmt"]') ?: [] as $format) {
            if ($format instanceof DOMElement && preg_match('/[ymdhis]/i', preg_replace('/"[^"]*"|\\\\./', '', $format->getAttribute('formatCode')))) {
                $custom[(int) $format->getAttribute('numFmtId')] = true;
            }
        }
        $builtIn = range(14, 22);
        $styles = [];
        foreach ($xpath->query('//*[local-name()="cellXfs"]/*[local-name()="xf"]') ?: [] as $index => $format) {
            if ($format instanceof DOMElement && (in_array((int) $format->getAttribute('numFmtId'), $builtIn, true) || isset($custom[(int) $format->getAttribute('numFmtId')]))) {
                $styles[] = true;
            } else {
                $styles[] = false;
            }
        }

        return $styles;
    }

    private function columnIndex(string $reference): int
    {
        if (! preg_match('/^([A-Z]+)/i', $reference, $match)) {
            throw new RuntimeException('The workbook contains an invalid cell reference.');
        }
        $index = 0;
        foreach (str_split(strtoupper($match[1])) as $char) {
            $index = $index * 26 + ord($char) - 64;
        }

        return $index - 1;
    }

    private function trades(array $rows): array
    {
        if (count($rows) < 2) {
            throw new RuntimeException('The tradebook contains no data rows.');
        }
        $header = array_map(fn ($v) => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $v), '_')), $rows[0]);
        $find = fn (array $names) => array_reduce($names, fn ($found, $name) => $found ?? array_search($name, $header, true), null);
        $columns = ['symbol' => $find(['symbol', 'tradingsymbol', 'trading_symbol']), 'side' => $find(['trade_type', 'transaction_type', 'type', 'buy_sell']), 'quantity' => $find(['quantity', 'qty']), 'price' => $find(['price', 'average_price', 'trade_price']), 'date' => $find(['trade_date', 'trade_timestamp', 'exchange_timestamp', 'date', 'order_execution_time']), 'id' => $find(['trade_id', 'broker_trade_id', 'exchange_trade_id'])];
        foreach (['symbol', 'side', 'quantity', 'price', 'date'] as $key) {
            if ($columns[$key] === false || $columns[$key] === null) {
                throw new RuntimeException('The tradebook is missing a required column: '.$key.'.');
            }
        }
        $out = [];
        foreach (array_slice($rows, 1) as $index => $row) {
            $value = fn ($key) => trim((string) ($row[$columns[$key]] ?? ''));
            $symbol = strtoupper($value('symbol'));
            $side = strtoupper($value('side'));
            $quantity = $value('quantity');
            $price = $value('price');
            $date = $this->date($value('date'));
            if ($symbol === '' || ! preg_match('/^[A-Z0-9&._-]{1,32}$/', $symbol) || ! in_array($side, ['BUY', 'SELL'], true) || ! is_numeric($quantity) || (float) $quantity <= 0 || ! is_numeric($price) || (float) $price <= 0 || $date === null) {
                throw new RuntimeException('Tradebook row '.($index + 2).' has an invalid symbol, side, quantity, price, or date.');
            }
            $id = $columns['id'] !== false && $columns['id'] !== null ? $value('id') : '';
            if (strlen($id) > 100) {
                throw new RuntimeException('Tradebook row '.($index + 2).' has an overlong trade ID.');
            }
            $out[$symbol][] = ['type' => strtolower($side), 'quantity' => (float) $quantity, 'price' => (float) $price, 'date' => $date, 'date_placeholder' => false, 'provenance' => 'console_tradebook', 'broker_trade_id' => $id !== '' ? $id : null];
        }
        if ($out === []) {
            throw new RuntimeException('The tradebook contains no supported trades.');
        }

        return $out;
    }

    private function date(string $value): ?string
    {
        foreach (['!Y-m-d', '!d-m-Y', '!d/m/Y', '!Y-m-d H:i:s', '!d-m-Y H:i:s', '!d/m/Y H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['tradebook' => [$message]]);
    }
}
