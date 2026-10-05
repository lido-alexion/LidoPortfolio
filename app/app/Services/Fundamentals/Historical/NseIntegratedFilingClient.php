<?php

namespace App\Services\Fundamentals\Historical;

use App\Models\Stock;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bounded client for the NSE Integrated Filing page's first-party filing index
 * and its official XBRL archive documents. It does not accept arbitrary hosts.
 */
class NseIntegratedFilingClient
{
    private const INDEX_URL = 'https://www.nseindia.com/api/integrated-filing-results';
    private const PAGE_URL = 'https://www.nseindia.com/companies-listing/corporate-integrated-filing';
    private const ARCHIVE_HOSTS = ['nsearchives.nseindia.com', 'archives.nseindia.com'];
    private const MAX_INDEX_BYTES = 2_000_000;
    private const MAX_DOCUMENT_BYTES = 1_500_000;
    private const MAX_DOCUMENTS = 4;

    /** @var array<string, list<array<string,mixed>>> */
    private array $cache = [];

    /** @var array<string, string> */
    private array $documentCache = [];

    public function __construct(private readonly NseXbrlFactsParser $parser)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fetch(Stock $stock, string $cadence): array
    {
        $symbol = strtoupper(trim((string) $stock->symbol));
        if ($symbol === '' || ! in_array($cadence, ['quarterly', 'annual'], true)) {
            return [];
        }

        $cacheKey = $symbol;
        $filings = $this->cache[$cacheKey] ??= $this->filings($symbol);
        $maxDocuments = max(1, min(self::MAX_DOCUMENTS, (int) config('fundamentals_bootstrap.nse_official_max_documents', self::MAX_DOCUMENTS)));
        $facts = [];
        foreach (array_slice($filings, 0, $maxDocuments) as $filing) {
            $url = $this->allowedArchiveUrl($filing['xbrl'] ?? null);
            if ($url === null) {
                continue;
            }

            try {
                $xml = $this->documentCache[$url] ?? null;
                if ($xml === null) {
                    $response = app(ExchangeRequestGate::class)->request(fn () => Http::timeout((float) config('fundamentals_bootstrap.nse_official_timeout_seconds', 30))
                        ->withHeaders([
                            'User-Agent' => 'StoX/1.0 (+https://stoxla.in)',
                            'Referer' => self::PAGE_URL,
                            'Accept' => 'application/xml,text/xml,*/*',
                        ])
                        ->get($url));
                    if ($response === null || ! $response->successful() || strlen($response->body()) > self::MAX_DOCUMENT_BYTES) {
                        continue;
                    }
                    $xml = $response->body();
                    $this->documentCache[$url] = $xml;
                }

                foreach ($this->parser->parse($xml, $filing, $cadence) as $row) {
                    $facts[] = $row;
                }
            } catch (ExchangeRequestDeferred $deferred) {
                throw $deferred;
            } catch (\Throwable) {
                // A bad/temporarily unavailable filing must not stop Yahoo fallback
                // or expose provider response bodies in application logs.
                continue;
            }
        }

        return $facts;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function filings(string $symbol): array
    {
        try {
            $response = app(ExchangeRequestGate::class)->request(fn () => Http::timeout((float) config('fundamentals_bootstrap.nse_official_timeout_seconds', 30))
                ->withHeaders([
                    'User-Agent' => 'StoX/1.0 (+https://stoxla.in)',
                    'Referer' => self::PAGE_URL,
                    'Accept' => 'application/json,text/plain,*/*',
                ])
                ->get(self::INDEX_URL, [
                    'index' => 'equities',
                    'type' => 'Integrated Filing- Financials',
                    'symbol' => $symbol,
                    'page' => 1,
                    'size' => 100,
                ]));
            if ($response === null || ! $response->successful() || strlen($response->body()) > self::MAX_INDEX_BYTES) {
                return [];
            }

            $payload = $response->json();
            $rows = is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $filings = [];
            foreach ($rows as $row) {
                if (! is_array($row) || strtoupper((string) ($row['symbol'] ?? '')) !== $symbol) {
                    continue;
                }
                if ($this->allowedArchiveUrl($row['xbrl'] ?? null) === null) {
                    continue;
                }
                $filings[] = $row;
            }

            return $filings;
        } catch (ExchangeRequestDeferred $deferred) {
            throw $deferred;
        } catch (\Throwable) {
            return [];
        }
    }

    private function allowedArchiveUrl(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2048) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! in_array(strtolower((string) ($parts['host'] ?? '')), self::ARCHIVE_HOSTS, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])) {
            return null;
        }

        return $value;
    }
}
