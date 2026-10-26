<?php

namespace App\Services\Operations;

use Illuminate\Http\Client\Response;

/** Fixed public vocabulary only. Unknown response strings, paths and query values never escape. */
class ProviderFailureEvidence
{
    private const NSE = [
        '/api/marketstatus' => ['nse.market_status', 'NSE market-status'],
        '/api/quote-equity' => ['nse.quote_equity', 'NSE quote-equity'],
        '/api/holiday-master' => ['nse.holiday_master', 'NSE holiday-master'],
        '/api/equity-stockindices' => ['nse.equity_stock_indices', 'NSE equity-stock-indices'],
        '/api/integrated-filing-results' => ['nse.integrated_filings', 'NSE integrated-filings'],
        '/api/historical/cm/equity' => ['nse.equity_history', 'NSE equity-history'],
        '/v1/charts/symbolhistoricaldata' => ['nse.chart_history', 'NSE chart-history'],
    ];

    public static function operation(string $providerHost, string $path): ?array
    {
        $path = strtolower($path);
        if ($providerHost === 'nseindia.com' && isset(self::NSE[$path])) {
            return ['id' => self::NSE[$path][0], 'label' => self::NSE[$path][1], 'path' => $path];
        }
        if ($providerHost === 'yahoo.com' && preg_match('~\A/v8/finance/chart/[^/]+\z~', $path)) {
            return ['id' => 'yahoo.chart', 'label' => 'Yahoo chart', 'path' => '/v8/finance/chart/{resource}'];
        }

        return null;
    }

    public static function labelForEndpoint(string $endpoint): ?string
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts)) {
            return null;
        }

        return self::operation(strtolower((string) ($parts['host'] ?? '')), (string) ($parts['path'] ?? ''))['label'] ?? null;
    }

    public static function category(?array $operation, ?int $status, ?Response $response): ?string
    {
        if ($operation === null) {
            return null;
        }
        if ($status === null) {
            return 'transport_failure';
        }
        if ($operation['id'] === 'yahoo.chart') {
            try {
                $payload = self::boundedJson($response);
            } catch (\Throwable) {
                $payload = [];
            }
            $chart = $payload['chart'] ?? null;
            $error = is_array($chart) ? ($chart['error'] ?? null) : null;
            $code = is_array($error) ? ($error['code'] ?? null) : null;

            return match ($code) {
                'Not Found' => 'chart_not_found',
                'Bad Request' => 'chart_bad_request',
                'Unauthorized' => 'chart_unauthorized',
                'Forbidden' => 'chart_forbidden',
                'Too Many Requests' => 'chart_rate_limited',
                'Unprocessable Entity' => 'chart_unprocessable',
                default => 'chart_unclassified',
            };
        }

        return match ($status) {
            400 => 'http_bad_request',
            401 => 'http_unauthorized',
            403 => 'http_forbidden',
            404 => 'http_not_found',
            429 => 'http_rate_limited',
            default => 'http_error',
        };
    }

    private static function boundedJson(?Response $response): array
    {
        if ($response === null) {
            return [];
        }
        $stream = $response->toPsrResponse()->getBody();
        // Do not consume a non-seekable body, materialize oversized bodies, or
        // alter the stream position used by the originating provider.
        if (! $stream->isSeekable() || ($stream->getSize() !== null && $stream->getSize() > 8192)) {
            return [];
        }
        $position = $stream->tell();
        try {
            $stream->rewind();
            $body = $stream->read(8193);
            if (strlen($body) > 8192) {
                return [];
            }
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);

            return is_array($payload) ? $payload : [];
        } catch (\Throwable) {
            return [];
        } finally {
            $stream->seek($position);
        }
    }
}
