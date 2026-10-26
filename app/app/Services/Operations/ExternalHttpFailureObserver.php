<?php

namespace App\Services\Operations;

use App\Services\PortfolioLoggerService;
use App\Support\PriceProviderRequestContext;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Throwable;

class ExternalHttpFailureObserver
{
    public function response(Request $request, Response|int $response): void
    {
        $status = $response instanceof Response ? $response->status() : $response;
        if ($status >= 200 && $status < 300) return;
        $this->observe($request, $status, null, $response instanceof Response ? $response : null);
    }

    public function connectionFailure(Request $request, Throwable $error): void
    {
        $this->observe($request, null, $error);
    }

    private function observe(Request $request, ?int $status, ?Throwable $error, ?Response $response = null): void
    {
        try {
            $url = $request->url();
            $parts = parse_url($url);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '/');
            $skipHeader = array_map('strval', (array) $request->header('X-StoX-Skip-Api-Failure-Reporting'));
            if (! in_array($scheme, ['http', 'https'], true) || $host === '' || in_array('1', $skipHeader, true)) return;
            $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
            $appApex = preg_replace('/^www\./', '', $appHost);
            $runtimeHost = strtolower((string) parse_url((string) config('ai_runtime.base_url'), PHP_URL_HOST));
            if (in_array($host, array_filter([$appHost, $appApex, $appApex ? 'www.'.$appApex : null]), true)
                || ($appApex !== '' && str_ends_with($host, '.'.$appApex))
                || ($runtimeHost !== '' && $host === $runtimeHost)
                || str_ends_with($host, '.internal') || str_ends_with($host, '.local')
                || filter_var($host, FILTER_VALIDATE_IP)) return;
            $providerHost = $this->approvedProviderHost($host);
            if ($providerHost === null) return;
            if (preg_match('~/(?:internal|telemetry|otlp|logs/frontend|ops/api-failures|browser-errors)(?:/|$)~i', $path)) return;
            // These route families contain auth/session credentials, account data,
            // or trading authority. They remain excluded for every HTTP method.
            if (preg_match('~(?:^|/)(?:auth|login|logout|session|token|account|portfolio|holdings?|positions?|margins?|orders?|trades?)(?:/|$)~i', $path)) return;
            // Telegram embeds the bot credential in the path; this host is never
            // eligible even though the HTTP body and query are omitted.
            if (str_contains($host, 'telegram.org')) return;
            // Kite public market reads are eligible; all other broker endpoints
            // are security/financial operations and stay out of issue evidence.
            if ($providerHost === 'kite.trade' && ! preg_match('~^/(?:quote|instruments)(?:/|$)~i', $path)) return;

            $operation = ProviderFailureEvidence::operation($providerHost, $path);
            $safePath = $operation['path'] ?? $this->safePath($path);
            $safeEndpoint = $scheme.'://'.$providerHost.$safePath; // only approved host and route vocabulary survive
            $failureClass = $error ? class_basename($error) : null;
            $providerError = ProviderFailureEvidence::category($operation, $status, $response);
            $privateContext = PriceProviderRequestContext::current();
            $roots = ['nse' => 'nseindia.com', 'bse_bhavcopy' => 'bseindia.com', 'yahoo' => 'yahoo.com', 'alpha_vantage' => 'alphavantage.co'];
            if (($roots[$privateContext['provider'] ?? ''] ?? null) !== $providerHost) {
                $privateContext = null;
            }
            if ($privateContext !== null && ($operation['id'] ?? null) === 'yahoo.chart'
                && strtoupper(rawurldecode(basename($path))) !== $privateContext['provider_symbol']) {
                $privateContext = null;
            }
            try {
                app(ApiFailureReporter::class)->observeExternalResponse(
                    'external-http', $request->method(), $safeEndpoint, $status,
                    [
                        'failure_class' => $failureClass,
                        'error_category' => $error ? 'transport' : 'http',
                        'safe_message' => $operation === null ? null : 'operation='.$operation['id'].'; provider_error='.$providerError,
                        'trace_id' => $privateContext['request_id'] ?? null,
                    ],
                );
            } catch (Throwable) {
                // A capture/container failure must not suppress independent private evidence.
            }
            if ($privateContext !== null) {
                try {
                    if (! app(ApiFailurePolicy::class)->reportable($status, $failureClass, ['endpoint' => $safeEndpoint])) {
                        return;
                    }
                    // These are existing canonical provider inputs, not parsed
                    // request/query/body values. They stay in protected logs.
                    app(PortfolioLoggerService::class)->provider('error', 'Provider HTTP failure evidence', [
                        ...$privateContext,
                        'operation' => $operation['id'] ?? 'provider.http',
                        'endpoint' => $safeEndpoint,
                        'http_status' => $status,
                        'failure_class' => $failureClass,
                        'provider_error' => $providerError,
                    ]);
                } catch (Throwable) {
                    // Logging must not change the request or its incident capture.
                }
            }
        } catch (Throwable) {
            // Observation remains fail-open and never exports raw request data.
        }
    }

    private function safePath(string $path): string
    {
        $known = ['api', 'v1', 'v2', 'v3', 'v4', 'v8', 'finance', 'chart', 'quote', 'ltp', 'instruments', 'nse', 'bse', 'historical', 'candles', 'index', 'indices', 'market', 'data', 'prices', 'holidays', 'search', 'symbol', 'fundamentals', 'events', 'corporate-actions', 'failure', 'success', 'connect'];
        $segments = array_map(static function (string $segment) use ($known): string {
            if ($segment === '') return '';
            return in_array(strtolower($segment), $known, true) ? strtolower($segment) : '{resource}';
        }, explode('/', trim($path, '/')));
        return '/'.implode('/', $segments);
    }

    private function approvedProviderHost(string $host): ?string
    {
        $allowed = config('api_failure_reporting.observed_provider_hosts', []);
        foreach ((array) $allowed as $candidate) {
            $candidate = strtolower(ltrim((string) $candidate, '.'));
            if ($candidate !== '' && ($host === $candidate || str_ends_with($host, '.'.$candidate))) return $candidate;
        }
        return null;
    }
}
