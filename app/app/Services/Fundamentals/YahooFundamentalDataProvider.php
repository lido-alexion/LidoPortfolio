<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YahooFundamentalDataProvider implements FundamentalDataProvider
{
    private const COOKIE_URL = 'https://fc.yahoo.com';

    private const CRUMB_URL = 'https://query1.finance.yahoo.com/v1/test/getcrumb';

    private const USER_AGENT = 'StoX-Fundamentals/1.0 (+https://stoxla.in)';

    private const SESSION_TTL_MINUTES = 15;

    private ?CookieJar $cookieJar = null;

    private ?string $crumb = null;

    private ?CarbonImmutable $sessionExpiresAt = null;

    public function fetch(Stock $stock, string $cadence): array
    {
        $symbol = $stock->yahoo_symbol ?: $stock->symbol.($stock->exchange === 'BSE' ? '.BO' : '.NS');
        $modules = $cadence === FundamentalDataService::CADENCE_ANNUAL
            ? 'incomeStatementHistory,balanceSheetHistory,cashflowStatementHistory'
            : 'incomeStatementHistoryQuarterly,balanceSheetHistoryQuarterly,cashflowStatementHistoryQuarterly';

        $query = [
            'modules' => $modules,
            'corsDomain' => 'finance.yahoo.com',
            'formatted' => 'false',
        ];

        for ($refreshes = 0; $refreshes <= 1; $refreshes++) {
            $this->ensureSession($refreshes > 0);
            $response = $this->requestQuoteSummary($symbol, $query);
            $payload = $response->json();
            $error = (string) ($payload['quoteSummary']['error']['description'] ?? '');

            if ($this->isAuthenticationFailure($response, $error)) {
                if ($refreshes === 0) {
                    continue;
                }

                throw new RuntimeException('Yahoo fundamentals authentication failed after session refresh.');
            }

            if (! $response->successful()) {
                throw new RuntimeException('Yahoo fundamentals request failed with HTTP '.$response->status());
            }

            $result = $payload['quoteSummary']['result'][0] ?? null;
            if (! is_array($result)) {
                throw new RuntimeException('Yahoo returned no fundamental data.');
            }

            return (new YahooFundamentalNormalizer)->normalize($result, $cadence);
        }

        throw new RuntimeException('Yahoo fundamentals request failed.');
    }

    private function ensureSession(bool $forceRefresh = false): void
    {
        if (! $forceRefresh
            && $this->cookieJar !== null
            && $this->crumb !== null
            && $this->sessionExpiresAt?->isFuture()) {
            return;
        }

        $jar = new CookieJar();
        $client = $this->client($jar);
        $bootstrap = $client->get(self::COOKIE_URL);
        if (! in_array($bootstrap->status(), [200, 301, 302, 404], true) || $jar->count() === 0) {
            throw new RuntimeException('Yahoo fundamentals session cookie could not be established.');
        }

        $crumbResponse = $client->get(self::CRUMB_URL);
        $crumb = trim($crumbResponse->body());
        if (! $crumbResponse->successful() || $crumb === '' || str_contains(strtolower($crumb), 'invalid')) {
            throw new RuntimeException('Yahoo fundamentals crumb could not be established.');
        }

        $this->cookieJar = $jar;
        $this->crumb = $crumb;
        $this->sessionExpiresAt = CarbonImmutable::now()->addMinutes(self::SESSION_TTL_MINUTES);
    }

    /** @param array<string,string> $query */
    private function requestQuoteSummary(string $symbol, array $query): Response
    {
        return $this->client($this->cookieJar)
            ->retry(1, 500)
            ->get("https://query2.finance.yahoo.com/v10/finance/quoteSummary/{$symbol}", array_merge(
                ['crumb' => $this->crumb],
                $query,
            ));
    }

    private function client(?CookieJar $jar): PendingRequest
    {
        return Http::timeout(20)
            ->withHeaders(['User-Agent' => self::USER_AGENT])
            ->withOptions(['cookies' => $jar ?? new CookieJar()]);
    }

    private function isAuthenticationFailure(Response $response, string $error): bool
    {
        return in_array($response->status(), [401, 403], true)
            || str_contains(strtolower($error), 'invalid crumb')
            || str_contains(strtolower($response->body()), 'invalid crumb');
    }
}
