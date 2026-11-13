<?php

declare(strict_types=1);

use App\Services\Fundamentals\Historical\NseXbrlFactsParser;
/**
 * Bounded, synthetic-only DOM/XPath diagnostic for NSE inline XBRL parsing.
 * This intentionally performs no network access and emits no fixture source.
 */

const INLINE_FACT_XPATH = '//*[contains(translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "nonfraction") and @name]';
const CONTEXT_XPATH = '//*[translate(substring-after(concat(":", local-name()), ":"), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="context"]';
const UNIT_XPATH = '//*[translate(substring-after(concat(":", local-name()), ":"), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="unit"]';

$resources = <<<'RESOURCES'
<xbrli:context id="q"><xbrli:entity><xbrli:identifier scheme="test">ISSUER</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:startDate>2026-01-01</xbrli:startDate><xbrli:endDate>2026-03-31</xbrli:endDate></xbrli:period></xbrli:context>
<xbrli:unit id="INR"><xbrli:measure>iso4217:INR</xbrli:measure></xbrli:unit>
RESOURCES;
$tableFacts = <<<'FACTS'
<table><tr><td><ix:nonFraction name="ind:RevenueFromOperations" contextRef="q" unitRef="INR" scale="3" decimals="-3">858,634</ix:nonFraction></td></tr>
<tr><td><ix:nonFraction name="ind:ProfitLossForPeriod" contextRef="q" unitRef="INR" scale="3" sign="-" decimals="-3">214,344</ix:nonFraction></td></tr></table>
FACTS;

$namespaceAttributes = 'xmlns:ix="http://www.xbrl.org/2013/inlineXBRL" xmlns:xbrli="http://www.xbrl.org/2003/instance" xmlns:iso4217="http://www.xbrl.org/2003/iso4217" xmlns:ind="http://www.sebi.gov.in/xbrl/2026-01-31/in-capmkt"';
$htmlFixture = '<html '.$namespaceAttributes.'><head><meta charset="utf-8"></head><body><ix:header><ix:resources>'.$resources.'</ix:resources></ix:header>'.$tableFacts.'</body></html>';
$xmlFixture = '<?xml version="1.0" encoding="UTF-8"?><html xmlns="http://www.w3.org/1999/xhtml" '.$namespaceAttributes.'><head><meta charset="utf-8" /></head><body><ix:header><ix:resources>'.$resources.'</ix:resources></ix:header>'.$tableFacts.'</body></html>';

/** @return array<string, mixed> */
function probeDocument(string $source, string $variant): array
{
    $result = ['variant' => $variant];
    $previous = libxml_use_internal_errors(true);

    try {
        foreach (['loadXML', 'loadHTML'] as $loader) {
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            try {
                $loaded = $loader === 'loadXML'
                    ? $document->loadXML($source, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)
                    : $document->loadHTML($source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
                $result[$loader] = ['success' => $loaded];
                if (! $loaded) {
                    continue;
                }

                $result[$loader] += inspectDocument($document);
            } catch (Throwable $exception) {
                $result[$loader] = ['exception' => ['type' => get_class($exception), 'message' => $exception->getMessage()]];
            } finally {
                libxml_clear_errors();
            }
        }

        $preferredLoader = ($result['loadXML']['success'] ?? false) ? 'loadXML' : 'loadHTML';
        if (isset($result[$preferredLoader]['success']) && $result[$preferredLoader]['success']) {
            $result['parserPath'] = $preferredLoader;
            $result['parserFactKeys'] = parserFactKeys($source);
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    return $result;
}

/** @return array<string, mixed> */
function inspectDocument(DOMDocument $document): array
{
    $xpath = new DOMXPath($document);
    $result = ['root' => nodeMetadata($document->documentElement)];

    foreach (['contexts' => CONTEXT_XPATH, 'units' => UNIT_XPATH, 'facts' => INLINE_FACT_XPATH] as $key => $expression) {
        try {
            $nodes = $xpath->query($expression);
            $result['xpath'][$key] = ['count' => $nodes === false ? 0 : $nodes->length];
            if ($nodes === false) {
                continue;
            }
            $result['xpath'][$key]['nodes'] = [];
            foreach ($nodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $metadata = nodeMetadata($node);
                if ($key === 'facts') {
                    $metadata['rawAttributeNames'] = [];
                    foreach ($node->attributes as $attributeNode) {
                        $metadata['rawAttributeNames'][] = $attributeNode->nodeName;
                    }
                    $metadata['attributes'] = [];
                    foreach (['name', 'contextRef', 'unitRef'] as $attribute) {
                        $metadata['attributes'][$attribute] = [
                            'exact' => $node->getAttribute($attribute),
                            'lowercaseFallback' => $node->getAttribute(strtolower($attribute)),
                        ];
                    }
                }
                $result['xpath'][$key]['nodes'][] = $metadata;
                if (count($result['xpath'][$key]['nodes']) >= 8) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            $result['xpath'][$key] = ['exception' => ['type' => get_class($exception), 'message' => $exception->getMessage()]];
        }
    }

    return $result;
}

/** @return array{name:?string,localName:?string} */
function nodeMetadata(?DOMElement $element): array
{
    return ['name' => $element?->nodeName, 'localName' => $element?->localName];
}

/** @return array{status:string,factKeys?:list<string>,exception?:array{type:string,message:string}} */
function parserFactKeys(string $source): array
{
    $autoload = dirname(__DIR__).'/app/vendor/autoload.php';
    if (! is_file($autoload)) {
        return ['status' => 'composer_autoload_unavailable'];
    }

    try {
        require_once $autoload;
        $rows = (new NseXbrlFactsParser)->parse($source, [
            'seq_Id' => 'synthetic-inline-diagnostic',
            'consolidated' => 'Standalone',
            'broadcast_Date' => '13-May-2026 13:20:09',
        ], 'quarterly');
        $keys = array_values(array_unique(array_map(static fn (array $row): string => (string) ($row['fact_key'] ?? ''), $rows)));
        sort($keys);

        return ['status' => 'available', 'factKeys' => $keys];
    } catch (Throwable $exception) {
        return ['status' => 'exception', 'exception' => ['type' => get_class($exception), 'message' => $exception->getMessage()]];
    }
}

$osRelease = 'unavailable';
if (is_readable('/etc/os-release')) {
    $release = parse_ini_file('/etc/os-release', false, INI_SCANNER_RAW);
    if (is_array($release)) {
        $osRelease = (string) ($release['PRETTY_NAME'] ?? $release['NAME'] ?? 'unknown');
    }
}

$report = [
    'runtime' => [
        'phpVersion' => PHP_VERSION,
        'libxmlVersion' => LIBXML_DOTTED_VERSION,
        'os' => $osRelease,
    ],
    'probes' => [
        probeDocument($htmlFixture, 'malformed_html_unclosed_meta'),
        probeDocument($xmlFixture, 'well_formed_xhtml_same_facts'),
    ],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
