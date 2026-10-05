<?php

namespace App\Services\Fundamentals\Historical;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Parses only explicitly mapped numeric concepts from an NSE XBRL filing.
 *
 * This is deliberately deterministic: unknown/custom concepts are ignored rather
 * than guessed. The accepted concept map is versioned in this class and should
 * be extended only with fixture-backed mappings.
 */
class NseXbrlFactsParser
{
    public const MAPPING_VERSION = 'nse-ind-as-financial-results-v1';

    /** @var array<string, array{statement_type:string,fact_key:string}> */
    private const CONCEPTS = [
        'RevenueFromOperations' => ['statement_type' => 'income_statement', 'fact_key' => 'revenue'],
        'ProfitLossForPeriod' => ['statement_type' => 'income_statement', 'fact_key' => 'net_income'],
        'ProfitLossFromOperatingActivities' => ['statement_type' => 'income_statement', 'fact_key' => 'operating_profit'],
        'OperatingProfit' => ['statement_type' => 'income_statement', 'fact_key' => 'operating_profit'],
        'Equity' => ['statement_type' => 'balance_sheet', 'fact_key' => 'equity'],
        'TotalEquity' => ['statement_type' => 'balance_sheet', 'fact_key' => 'equity'],
        'Assets' => ['statement_type' => 'balance_sheet', 'fact_key' => 'total_assets'],
        'Liabilities' => ['statement_type' => 'balance_sheet', 'fact_key' => 'total_liabilities'],
        'CashFlowsFromUsedInOperatingActivities' => ['statement_type' => 'cash_flow', 'fact_key' => 'operating_cash_flow'],
        'CashFlowsFromUsedInInvestingActivities' => ['statement_type' => 'cash_flow', 'fact_key' => 'investing_cash_flow'],
        'CashFlowsFromUsedInFinancingActivities' => ['statement_type' => 'cash_flow', 'fact_key' => 'financing_cash_flow'],
        'DividendsPaidClassifiedAsFinancingActivities' => ['statement_type' => 'cash_flow', 'fact_key' => 'dividends_paid'],
    ];

    /**
     * @param array<string, mixed> $filing
     * @return list<array<string, mixed>>
     */
    public function parse(string $xml, array $filing, string $cadence): array
    {
        if (! in_array($cadence, ['quarterly', 'annual'], true)) {
            throw new RuntimeException('Unsupported fundamentals cadence.');
        }
        if ($xml === '' || strlen($xml) > 1_500_000) {
            throw new RuntimeException('Official XBRL document is empty or exceeds the size limit.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                throw new RuntimeException('Official filing is not valid XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $rootName = $document->documentElement?->localName;
        $rootNamespace = $document->documentElement?->namespaceURI;
        if ($rootName !== 'xbrl' || $rootNamespace !== 'http://www.xbrl.org/2003/instance') {
            throw new RuntimeException('Official filing is not an XBRL instance document.');
        }

        $xpath = new DOMXPath($document);
        $contexts = $this->contexts($xpath);
        $units = $this->units($xpath);
        $basis = $this->basis((string) ($filing['consolidated'] ?? ''));

        $metadata = [
            'availability_quality' => 'non_pit',
            'provider' => 'nse_official',
            'source' => 'nse_integrated_filing',
            'mapping_version' => self::MAPPING_VERSION,
            'document_id' => (string) ($filing['seq_Id'] ?? ''),
            'index_entry_id' => (string) ($filing['seq_Id'] ?? ''),
            'document_sha256' => hash('sha256', $xml),
            'document_url' => (string) ($filing['xbrl'] ?? ''),
            'source_period_end' => (string) ($filing['qe_Date'] ?? ''),
            'broadcast_time_raw' => $filing['broadcast_Date'] ?? null,
            'revision_time_raw' => $filing['revised_Date'] ?? null,
            'revision_remark' => $filing['revision_Remark'] ?? null,
            'filing_type' => (string) ($filing['type_Sub'] ?? ''),
        ];

        $rows = [];
        foreach ($xpath->query('//*[local-name()="xbrl"]/*') ?: [] as $fact) {
            if (! $fact instanceof DOMElement || ! $fact->hasAttribute('contextRef')) {
                continue;
            }
            $concept = $fact->localName;
            $mapping = self::CONCEPTS[$concept] ?? null;
            if ($mapping === null) {
                continue;
            }
            $context = $contexts[$fact->getAttribute('contextRef')] ?? null;
            if ($context === null || $context['has_dimensions'] || ! $this->matchesCadence($context, $cadence)) {
                continue;
            }
            if (strtolower($fact->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'nil')) === 'true') {
                continue;
            }
            $value = trim($fact->textContent);
            if ($value === '' || ! is_numeric($value)) {
                continue;
            }

            $unit = $units[$fact->getAttribute('unitRef')] ?? null;
            // These catalogued concepts are currency amounts. Unknown and non-INR
            // units are not silently treated as rupees.
            if ($unit !== 'INR') {
                continue;
            }
            $currency = 'INR';

            $availabilityDate = $this->sourceDate($filing);
            $sourceMeta = $metadata + [
                'concept' => $fact->tagName,
                'context_id' => $fact->getAttribute('contextRef'),
                'unit' => $unit,
                'decimals' => $fact->getAttribute('decimals') ?: null,
                'period_kind' => $context['kind'],
                'has_dimensions' => false,
            ];

            $rows[] = [
                'statement_type' => $mapping['statement_type'],
                'cadence' => $cadence,
                'statement_basis' => $basis,
                'fact_key' => $mapping['fact_key'],
                'period_start' => $context['start'],
                'period_end' => $context['end'],
                'value' => $value,
                'availability_date' => $availabilityDate,
                'currency' => $currency,
                'provider' => 'nse_official',
                'source_meta' => $sourceMeta,
            ];
        }

        return $this->deduplicate($rows);
    }

    /**
     * @return array<string, array{start:?string,end:string,kind:string,has_dimensions:bool}>
     */
    private function contexts(DOMXPath $xpath): array
    {
        $result = [];
        foreach ($xpath->query('//*[local-name()="context"]') ?: [] as $context) {
            if (! $context instanceof DOMElement) {
                continue;
            }
            $period = $xpath->query('./*[local-name()="period"]', $context)?->item(0);
            if (! $period instanceof DOMElement) {
                continue;
            }
            $start = $xpath->query('./*[local-name()="startDate"]', $period)?->item(0)?->textContent;
            $end = $xpath->query('./*[local-name()="endDate"]', $period)?->item(0)?->textContent;
            $instant = $xpath->query('./*[local-name()="instant"]', $period)?->item(0)?->textContent;
            $dateEnd = $instant ?: $end;
            if (! is_string($dateEnd) || ! $this->validDate($dateEnd)) {
                continue;
            }
            $hasDimensions = $xpath->query('.//*[local-name()="explicitMember" or local-name()="typedMember"]', $context)->length > 0;
            $result[$context->getAttribute('id')] = [
                'start' => is_string($start) && $this->validDate($start) ? $start : null,
                'end' => $dateEnd,
                'kind' => $instant ? 'instant' : 'duration',
                'has_dimensions' => $hasDimensions,
            ];
        }

        return $result;
    }

    /** @return array<string, ?string> */
    private function units(DOMXPath $xpath): array
    {
        $result = [];
        foreach ($xpath->query('//*[local-name()="unit"]') ?: [] as $unit) {
            if (! $unit instanceof DOMElement) {
                continue;
            }
            $measure = $xpath->query('.//*[local-name()="measure"]', $unit)?->item(0)?->textContent;
            if (! is_string($measure)) {
                continue;
            }
            $measure = trim($measure);
            $result[$unit->getAttribute('id')] = strtoupper(str_contains($measure, ':') ? substr($measure, strrpos($measure, ':') + 1) : $measure);
        }

        return $result;
    }

    /**
     * @param array{start:?string,end:string,kind:string,has_dimensions:bool} $context
     */
    private function matchesCadence(array $context, string $cadence): bool
    {
        $end = CarbonImmutable::parse($context['end']);
        if ($context['kind'] === 'instant') {
            return $cadence === 'quarterly'
                ? in_array($end->format('m-d'), ['03-31', '06-30', '09-30', '12-31'], true)
                : $end->format('m-d') === '03-31';
        }
        if ($context['start'] === null) {
            return false;
        }

        $start = CarbonImmutable::parse($context['start']);
        $expectedQuarterEnd = $start->copy()->addMonthsNoOverflow(3)->subDay();
        $isQuarter = $start->day === 1
            && $expectedQuarterEnd->toDateString() === $end->toDateString()
            && in_array($end->format('m-d'), ['03-31', '06-30', '09-30', '12-31'], true);
        if ($cadence === 'quarterly') {
            return $isQuarter;
        }

        return $start->day === 1
            && $start->month === 4
            && $end->month === 3
            && $end->day === 31
            && $start->year + 1 === $end->year;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function deduplicate(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $key = implode('|', [
                $row['statement_type'], $row['cadence'], $row['statement_basis'],
                $row['fact_key'], $row['period_end'], $row['period_start'] ?? '',
            ]);
            if (! isset($unique[$key])) {
                $unique[$key] = $row;
                continue;
            }
            if ((string) $unique[$key]['value'] !== (string) $row['value']) {
                unset($unique[$key]);
                // Conflicting duplicate concept/context values are ambiguous; do not guess.
                $unique[$key.'|conflict'] = ['_conflict' => true];
            }
        }

        return array_values(array_filter($unique, static fn (array $row): bool => ! isset($row['_conflict'])));
    }

    private function basis(string $value): string
    {
        return match (strtolower(trim($value))) {
            'standalone' => 'standalone',
            'consolidated' => 'consolidated',
            default => 'unknown',
        };
    }

    private function sourceDate(array $filing): ?string
    {
        $raw = trim((string) ($filing['broadcast_Date'] ?? ''));
        if ($raw === '') {
            return null;
        }

        // Preserve source time separately; use the exchange's stated broadcast date
        // only as a display date. PIT consumers exclude this row until timezone/session
        // semantics are reviewed and availability_quality is upgraded from non_pit.
        try {
            return CarbonImmutable::createFromFormat('d-M-Y H:i:s', $raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function validDate(string $date): bool
    {
        try {
            return CarbonImmutable::parse($date)->format('Y-m-d') === $date;
        } catch (\Throwable) {
            return false;
        }
    }
}
