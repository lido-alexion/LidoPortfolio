<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

/**
 * Imports StoX's versioned manual fundamentals XLSX template.
 *
 * Only the "Data Sheet" worksheet and explicitly mapped row labels are read.
 * Unmapped sections and labels are ignored; values are never inferred by AI.
 */
class ManualFundamentalSpreadsheetImporter
{
    public const TEMPLATE_VERSION = '2.1';

    private const MAX_UPLOAD_BYTES = 5_000_000;

    private const MAX_UNCOMPRESSED_BYTES = 12_000_000;

    /** @var array<string,string> */
    private const INCOME_FACTS = [
        'sales' => 'revenue',
        'netprofit' => 'net_income',
        'operatingprofit' => 'operating_profit',
        'interest' => 'interest_expense',
    ];

    /** @var array<string,string> */
    private const BALANCE_FACTS = [
        'borrowings' => 'debt',
        'netblock' => 'property_plant_equipment',
        'capitalworkinprogress' => 'capital_work_in_progress',
        'receivables' => 'trade_receivables',
        'inventory' => 'inventory',
        'cashbank' => 'cash_and_equivalents',
        'noofequityshares' => 'shares_outstanding',
    ];

    /** @var array<string,string> */
    private const CASH_FLOW_FACTS = [
        'cashfromoperatingactivity' => 'operating_cash_flow',
        'cashfrominvestingactivity' => 'investing_cash_flow',
        'cashfromfinancingactivity' => 'financing_cash_flow',
    ];

    /**
     * @return array{company_name:string,template_version:string,imported_rows:int,ignored_rows:int,stats:array{inserted:int,deduped:int,restated:int}}
     */
    public function import(
        string $path,
        Stock $stock,
        int $userId,
        string $originalFilename,
        string $statementBasis,
        FundamentalDataService $fundamentals,
    ): array {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['file' => 'Excel workbook support is unavailable on this server.']);
        }
        if (! in_array($statementBasis, ['standalone', 'consolidated'], true)) {
            throw ValidationException::withMessages(['statement_basis' => 'Choose standalone or consolidated statements.']);
        }
        $size = @filesize($path);
        if (! is_int($size) || $size < 1 || $size > self::MAX_UPLOAD_BYTES) {
            throw ValidationException::withMessages(['file' => 'The workbook must be between 1 byte and 5 MB.']);
        }

        $hash = hash_file('sha256', $path);
        if (! is_string($hash)) {
            throw ValidationException::withMessages(['file' => 'The uploaded workbook could not be read.']);
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true || $zip->numFiles < 1 || $zip->numFiles > 500) {
            throw ValidationException::withMessages(['file' => 'Upload a valid, unencrypted .xlsx workbook.']);
        }

        try {
            $expanded = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    throw ValidationException::withMessages(['file' => 'The workbook archive is invalid.']);
                }
                $expanded += (int) ($stat['size'] ?? 0);
                if ($expanded > self::MAX_UNCOMPRESSED_BYTES) {
                    throw ValidationException::withMessages(['file' => 'The workbook expands beyond the allowed size.']);
                }
            }

            $workbook = $this->xml($zip->getFromName('xl/workbook.xml') ?: '');
            $relationships = $this->xml($zip->getFromName('xl/_rels/workbook.xml.rels') ?: '');
            $sheetPath = $this->dataSheetPath($workbook, $relationships);
            $sharedStrings = $this->sharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: '');
            $sheetXml = $zip->getFromName($sheetPath);
            if (! is_string($sheetXml) || strlen($sheetXml) > self::MAX_UNCOMPRESSED_BYTES) {
                throw ValidationException::withMessages(['file' => 'The Data Sheet tab is missing or too large.']);
            }
            $grid = $this->grid($this->xml($sheetXml), $sharedStrings);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        } finally {
            $zip->close();
        }

        $companyName = $this->cell($grid, 1, 2);
        if (strtoupper(trim($this->cell($grid, 1, 1))) !== 'COMPANY NAME' || trim($companyName) === '') {
            throw ValidationException::withMessages(['file' => 'The Data Sheet tab does not contain the expected company name header.']);
        }
        $version = trim($this->cell($grid, 2, 2));
        if ($version !== self::TEMPLATE_VERSION) {
            throw ValidationException::withMessages([
                'file' => 'Unsupported template version. Expected version '.self::TEMPLATE_VERSION.'.',
            ]);
        }

        $rows = [];
        $ignoredRows = 0;
        foreach ([
            ['marker' => 'PROFIT & LOSS', 'cadence' => 'annual', 'kind' => 'income'],
            ['marker' => 'QUARTERS', 'cadence' => 'quarterly', 'kind' => 'income'],
            ['marker' => 'BALANCE SHEET', 'cadence' => 'annual', 'kind' => 'balance'],
            ['marker' => 'CASH FLOW:', 'cadence' => 'annual', 'kind' => 'cash_flow'],
        ] as $section) {
            [$sectionRows, $ignored] = $this->readSection($grid, $section, $stock, $statementBasis, $companyName, $version, $userId, $originalFilename, $hash);
            $rows = array_merge($rows, $sectionRows);
            $ignoredRows += $ignored;
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'No supported, dated fundamental values were found in the Data Sheet tab.']);
        }

        $stats = $fundamentals->storeFacts($stock, $rows);
        return [
            'company_name' => $companyName,
            'template_version' => $version,
            'imported_rows' => count($rows),
            'ignored_rows' => $ignoredRows,
            'stats' => $stats,
        ];
    }

    private function xml(string $content): DOMDocument
    {
        if ($content === '' || strlen($content) > self::MAX_UNCOMPRESSED_BYTES) {
            throw new RuntimeException('The workbook is missing required Excel data or exceeds the allowed size.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (! $document->loadXML($content, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                throw new RuntimeException('The workbook contains invalid Excel XML.');
            }
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function dataSheetPath(DOMDocument $workbook, DOMDocument $relationships): string
    {
        $xpath = new DOMXPath($workbook);
        $sheet = null;
        foreach ($xpath->query('//*[local-name()="sheet"]') ?: [] as $candidate) {
            if ($candidate instanceof DOMElement && strcasecmp(trim($candidate->getAttribute('name')), 'Data Sheet') === 0) {
                $sheet = $candidate;
                break;
            }
        }
        if (! $sheet instanceof DOMElement) {
            throw new RuntimeException('The workbook must contain a tab named "Data Sheet".');
        }

        $relationshipId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        $relXpath = new DOMXPath($relationships);
        foreach ($relXpath->query('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            if (! $relationship instanceof DOMElement || $relationship->getAttribute('Id') !== $relationshipId) {
                continue;
            }
            if (strcasecmp($relationship->getAttribute('TargetMode'), 'External') === 0) {
                break;
            }
            $target = str_replace('\\', '/', $relationship->getAttribute('Target'));
            $parts = str_starts_with($target, '/') ? [] : ['xl'];
            foreach (explode('/', ltrim($target, '/')) as $part) {
                if ($part === '' || $part === '.') {
                    continue;
                }
                if ($part === '..') {
                    if ($parts === []) {
                        break;
                    }
                    array_pop($parts);
                    continue;
                }
                $parts[] = $part;
            }
            $resolved = implode('/', $parts);
            if (str_starts_with($resolved, 'xl/worksheets/') && preg_match('/^[A-Za-z0-9_\/.-]+$/D', $resolved) === 1) {
                return $resolved;
            }
            break;
        }
        throw new RuntimeException('The Data Sheet tab has an invalid worksheet link.');
    }

    /** @return list<string> */
    private function sharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $document = $this->xml($xml);
        $xpath = new DOMXPath($document);
        $result = [];
        foreach ($xpath->query('//*[local-name()="si"]') ?: [] as $item) {
            $text = '';
            foreach ((new DOMXPath($document))->query('.//*[local-name()="t"]', $item) ?: [] as $part) {
                $text .= $part->textContent;
            }
            $result[] = $text;
        }
        return $result;
    }

    /**
     * @param list<string> $sharedStrings
     * @return array<int,array<int,string>>
     */
    private function grid(DOMDocument $sheet, array $sharedStrings): array
    {
        $grid = [];
        $xpath = new DOMXPath($sheet);
        foreach ($xpath->query('//*[local-name()="row"]') ?: [] as $rowNode) {
            if (! $rowNode instanceof DOMElement) {
                continue;
            }
            $rowNumber = (int) $rowNode->getAttribute('r');
            if ($rowNumber < 1 || $rowNumber > 200) {
                continue;
            }
            foreach ($xpath->query('./*[local-name()="c"]', $rowNode) ?: [] as $cell) {
                if (! $cell instanceof DOMElement || preg_match('/^([A-Z]+)[0-9]+$/i', $cell->getAttribute('r'), $match) !== 1) {
                    continue;
                }
                $column = $this->columnNumber(strtoupper($match[1]));
                if ($column > 32) {
                    continue;
                }
                $type = $cell->getAttribute('t');
                $valueNode = $xpath->query('./*[local-name()="v"]', $cell)?->item(0);
                $value = $valueNode?->textContent ?? '';
                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = '';
                    foreach ($xpath->query('.//*[local-name()="t"]', $cell) ?: [] as $textNode) {
                        $value .= $textNode->textContent;
                    }
                }
                $grid[$rowNumber][$column] = trim((string) $value);
            }
        }
        return $grid;
    }

    private function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = $number * 26 + ord($letter) - 64;
        }
        return $number;
    }

    private function cell(array $grid, int $row, int $column): string
    {
        return (string) ($grid[$row][$column] ?? '');
    }

    /**
     * @param array{marker:string,cadence:string,kind:string} $section
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function readSection(
        array $grid,
        array $section,
        Stock $stock,
        string $basis,
        string $companyName,
        string $version,
        int $userId,
        string $originalFilename,
        string $hash,
    ): array {
        $markerRow = null;
        foreach ($grid as $rowNumber => $cells) {
            if (strcasecmp(trim((string) ($cells[1] ?? '')), $section['marker']) === 0) {
                $markerRow = (int) $rowNumber;
                break;
            }
        }
        if ($markerRow === null) {
            return [[], 0];
        }

        $headerRow = null;
        for ($row = $markerRow + 1; $row <= min($markerRow + 5, 200); $row++) {
            if (strcasecmp(trim($this->cell($grid, $row, 1)), 'Report Date') === 0) {
                $headerRow = $row;
                break;
            }
        }
        if ($headerRow === null) {
            return [[], 0];
        }

        $periods = [];
        foreach (($grid[$headerRow] ?? []) as $column => $dateValue) {
            if ($column < 2 || trim((string) $dateValue) === '') {
                continue;
            }
            $date = $this->dateValue((string) $dateValue);
            if ($date !== null) {
                $periods[$column] = $date;
            }
        }
        if ($periods === []) {
            return [[], 0];
        }

        $data = [];
        $ignored = 0;
        $emptyRows = 0;
        for ($row = $headerRow + 1; $row <= 200; $row++) {
            $label = trim($this->cell($grid, $row, 1));
            $hasAnyValue = false;
            foreach (array_keys($periods) as $column) {
                if (trim($this->cell($grid, $row, $column)) !== '') {
                    $hasAnyValue = true;
                    break;
                }
            }
            if ($label === '' && ! $hasAnyValue) {
                if (++$emptyRows >= 2) {
                    break;
                }
                continue;
            }
            $emptyRows = 0;
            if ($label === '') {
                continue;
            }
            if (in_array(strtoupper($label), ['QUARTERS', 'BALANCE SHEET', 'CASH FLOW:', 'DERIVED:', 'PRICE:'], true)) {
                break;
            }

            $key = $this->labelKey($label);
            $factKey = match ($section['kind']) {
                'income' => self::INCOME_FACTS[$key] ?? null,
                'balance' => self::BALANCE_FACTS[$key] ?? null,
                'cash_flow' => self::CASH_FLOW_FACTS[$key] ?? null,
                default => null,
            };
            $specialEquity = $section['kind'] === 'balance' && in_array($key, ['equitysharecapital', 'reserves'], true);
            if ($factKey === null && ! $specialEquity) {
                if ($hasAnyValue) {
                    $ignored++;
                }
                continue;
            }

            foreach ($periods as $column => $periodEnd) {
                $rawValue = trim($this->cell($grid, $row, $column));
                if ($rawValue === '' || ! is_numeric(str_replace(',', '', $rawValue))) {
                    continue;
                }
                $value = (float) str_replace(',', '', $rawValue);
                if ($specialEquity) {
                    $factKey = 'equity';
                    $identity = $section['cadence'].'|'.$periodEnd;
                    $data[$identity] ??= [
                        'sum' => 0.0,
                        'components' => [],
                        'period_end' => $periodEnd,
                        'cadence' => $section['cadence'],
                    ];
                    $data[$identity]['sum'] += $value;
                    $data[$identity]['components'][] = $key;
                    continue;
                }

                $scale = $factKey === 'shares_outstanding' ? 1 : 10_000_000;
                $data[] = $this->factRow(
                    $stock,
                    $basis,
                    $factKey,
                    $section['kind'] === 'income' ? 'income_statement' : ($section['kind'] === 'balance' ? 'balance_sheet' : 'cash_flow'),
                    $section['cadence'],
                    $periodEnd,
                    $value * $scale,
                    $companyName,
                    $version,
                    $userId,
                    $originalFilename,
                    $hash,
                    [],
                );
            }
        }

        $rows = [];
        foreach ($data as $record) {
            if (isset($record['sum'], $record['period_end'])) {
                $rows[] = $this->factRow(
                    $stock,
                    $basis,
                    'equity',
                    'balance_sheet',
                    $record['cadence'],
                    $record['period_end'],
                    $record['sum'] * 10_000_000,
                    $companyName,
                    $version,
                    $userId,
                    $originalFilename,
                    $hash,
                    ['components' => $record['components']],
                );
            } else {
                $rows[] = $record;
            }
        }
        return [$rows, $ignored];
    }

    private function factRow(
        Stock $stock,
        string $basis,
        string $factKey,
        string $statementType,
        string $cadence,
        string $periodEnd,
        float $value,
        string $companyName,
        string $version,
        int $userId,
        string $originalFilename,
        string $hash,
        array $additionalMeta,
    ): array {
        $end = CarbonImmutable::parse($periodEnd);
        $periodStart = $cadence === 'annual'
            ? $end->subYear()->addDay()->toDateString()
            : $end->startOfQuarter()->toDateString();

        return [
            'provider' => 'manual_spreadsheet',
            'statement_type' => $statementType,
            'cadence' => $cadence,
            'statement_basis' => $basis,
            'fact_key' => $factKey,
            'period_start' => $periodStart,
            'period_end' => $end->toDateString(),
            'reported_period' => $end->toDateString(),
            'value' => number_format($value, 6, '.', ''),
            'availability_date' => now()->toDateString(),
            'currency' => $factKey === 'shares_outstanding' ? null : 'INR',
            'source_meta' => array_merge([
                'source' => 'admin_manual_spreadsheet',
                'template_version' => $version,
                'company_name_in_workbook' => $companyName,
                'original_filename' => basename($originalFilename),
                'workbook_sha256' => $hash,
                'uploaded_by_user_id' => $userId,
                'value_unit_in_workbook' => $factKey === 'shares_outstanding' ? 'shares' : 'INR crore converted to INR',
            ], $additionalMeta),
        ];
    }

    private function labelKey(string $label): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $label) ?? '');
    }

    private function dateValue(string $value): ?string
    {
        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial < 1 || $serial > 100_000) {
                return null;
            }
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor($serial))->toDateString();
        }
        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
