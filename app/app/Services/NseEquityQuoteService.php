<?php

namespace App\Services;

use App\Support\NseHttpClient;

/** Current official equity identity protocol; callers own persistence and classification policy. */
class NseEquityQuoteService
{
    public const SOURCE_URL = 'https://www.nseindia.com/api/NextApi/apiClient/GetQuoteApi';

    /** Return original decoded evidence after the selected quote identity agrees. */
    public function fetch(string $symbol, ?string $series = null, ?string $expectedIsin = null): array
    {
        $symbol = strtoupper($symbol);
        $client = NseHttpClient::create();
        $metadata = $this->requestPayload($client, ['functionName' => 'getMetaData', 'symbol' => $symbol]);
        [$selectedSeries, $marketType] = $this->validateMetadata($symbol, $series, $expectedIsin, $metadata);
        $quote = $this->requestPayload($client, $this->quoteQuery($symbol, $selectedSeries, $marketType));
        $payload = ['quote_metadata' => $metadata, 'equity_quote' => $quote];
        $this->inspect($payload, $symbol, $series, $expectedIsin);

        return $payload;
    }

    /** Pure identity validation; a caller decides whether classification fields are required. */
    public function inspect(array $payload, string $symbol, ?string $series = null, ?string $expectedIsin = null): array
    {
        $symbol = strtoupper($symbol);
        $metadata = $payload['quote_metadata'] ?? null;
        $quote = $payload['equity_quote'] ?? null;
        if (! is_array($metadata) || ! is_array($quote)) {
            throw new \RuntimeException('NSE equity quote evidence envelope was incomplete.');
        }
        [$selectedSeries, $marketType] = $this->validateMetadata($symbol, $series, $expectedIsin, $metadata);
        $responses = $quote['equityResponse'] ?? null;
        if (! is_array($responses) || ! array_is_list($responses) || count($responses) !== 1
            || ! is_array($responses[0]['metaData'] ?? null)) {
            throw new \RuntimeException('NSE equity quote response shape was invalid or ambiguous.');
        }
        $identity = $responses[0]['metaData'];
        if (($identity['symbol'] ?? null) !== $metadata['symbol']
            || ($identity['isinCode'] ?? null) !== $metadata['isin']
            || ($identity['series'] ?? null) !== $selectedSeries
            || ($identity['marketType'] ?? null) !== $marketType) {
            throw new \RuntimeException('NSE equity quote identity did not match the requested security.');
        }

        return [
            'identity' => $identity,
            'security_info' => $responses[0]['secInfo'] ?? null,
            'source_url' => self::SOURCE_URL.'?'.http_build_query(
                $this->quoteQuery($symbol, $selectedSeries, $marketType), '', '&', PHP_QUERY_RFC3986,
            ),
        ];
    }

    private function requestPayload(\Illuminate\Http\Client\PendingRequest $client, array $query): array
    {
        try {
            $response = $client->get(self::SOURCE_URL, $query);
        } catch (\Illuminate\Http\Client\RequestException $error) {
            throw new \RuntimeException('NSE equity quote request failed with HTTP '.$error->response->status(), 0, $error);
        }
        if (! $response->successful()) {
            throw new \RuntimeException('NSE equity quote request failed with HTTP '.$response->status());
        }
        $payload = $response->json();
        if (! is_array($payload) || array_is_list($payload)) {
            throw new \RuntimeException('NSE equity quote response was not a JSON object.');
        }

        return $payload;
    }

    /** Metadata is source identity/selection evidence, not permission to change lifecycle. */
    private function validateMetadata(string $symbol, ?string $storedSeries, ?string $expectedIsin, array $metadata): array
    {
        $series = strtoupper(trim((string) $storedSeries));
        $isin = $metadata['isin'] ?? null;
        $marketType = $metadata['marketType'] ?? null;
        $activeSeries = $metadata['activeSeries'] ?? null;
        if (($metadata['symbol'] ?? null) !== $symbol
            || ! is_string($isin) || preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/', $isin) !== 1
            || (trim((string) $expectedIsin) !== '' && strtoupper(trim((string) $expectedIsin)) !== $isin)
            || ! is_string($marketType) || preg_match('/^[A-Z0-9]{1,8}$/', $marketType) !== 1
            || ! is_array($activeSeries) || ! array_is_list($activeSeries) || count($activeSeries) > 20
            || $activeSeries === []) {
            throw new \RuntimeException('NSE equity quote metadata identity or series did not match the requested stock.');
        }
        foreach ($activeSeries as $offered) {
            if (! is_string($offered) || preg_match('/^[A-Z0-9]{1,4}$/', $offered) !== 1) {
                throw new \RuntimeException('NSE equity quote offered series was malformed.');
            }
        }
        if ($series === '') {
            // EQ remains the ordinary default when offered. A single explicit
            // alternative is unambiguous; never guess among other choices.
            $choices = array_values(array_unique($activeSeries));
            $series = in_array('EQ', $choices, true) ? 'EQ' : (count($choices) === 1 ? $choices[0] : '');
        }
        if (preg_match('/^[A-Z0-9]{1,4}$/', $series) !== 1 || ! in_array($series, $activeSeries, true)) {
            throw new \RuntimeException('NSE equity quote metadata did not offer the requested stock series.');
        }

        return [$series, $marketType];
    }

    private function quoteQuery(string $symbol, string $series, string $marketType): array
    {
        return [
            'functionName' => 'getSymbolData', 'marketType' => $marketType,
            'series' => $series, 'symbol' => $symbol,
        ];
    }

}
