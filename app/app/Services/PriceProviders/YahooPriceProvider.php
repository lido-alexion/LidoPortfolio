<?php

namespace App\Services\PriceProviders;

use App\Contracts\PriceProviderInterface;
use App\Support\ExternalHttp;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use RuntimeException;

class YahooPriceProvider implements PriceProviderInterface
{
    public function getName(): string
    {
        return 'yahoo';
    }

    public function fetchHistorical(string $symbol, Carbon $from, Carbon $to, ?string $providerSymbol = null): array
    {
        $yahooSymbol = $providerSymbol ?? $this->mapSymbol($symbol);
        $period1 = $from->copy()->startOfDay()->timestamp;
        $period2 = $to->copy()->endOfDay()->timestamp;

        $response = $this->requestChart($yahooSymbol, $period1, $period2);

        if ($response->json('chart.error')) {
            return [];
        }

        if (! $response->successful()) {
            throw new RuntimeException('Yahoo Finance request failed: '.$response->status());
        }

        $result = $response->json('chart.result.0');
        if (! $result) {
            return [];
        }

        $timestamps = $result['timestamp'] ?? [];
        $quotes = $result['indicators']['quote'][0] ?? [];

        $rows = [];
        foreach ($timestamps as $index => $timestamp) {
            $close = $quotes['close'][$index] ?? null;
            if ($close === null) {
                continue;
            }

            $rows[] = [
                'price_date' => Carbon::createFromTimestamp($timestamp)->toDateString(),
                'open_price' => isset($quotes['open'][$index]) ? (float) $quotes['open'][$index] : null,
                'high_price' => isset($quotes['high'][$index]) ? (float) $quotes['high'][$index] : null,
                'low_price' => isset($quotes['low'][$index]) ? (float) $quotes['low'][$index] : null,
                'close_price' => (float) $close,
                'volume' => isset($quotes['volume'][$index]) ? (int) $quotes['volume'][$index] : null,
            ];
        }

        return $rows;
    }

    public function fetchLatest(string $symbol): ?array
    {
        $rows = $this->fetchHistorical($symbol, now()->subDays(7), now());

        return $rows ? end($rows) : null;
    }

    protected function requestChart(string $yahooSymbol, int $period1, int $period2): Response
    {
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'application/json,text/plain,*/*',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Referer' => 'https://finance.yahoo.com/quote/'.rawurlencode($yahooSymbol),
        ];

        $query = [
            'period1' => $period1,
            'period2' => $period2,
            'interval' => '1d',
            'events' => 'history',
        ];

        $lastResponse = null;
        foreach (['query1.finance.yahoo.com', 'query2.finance.yahoo.com'] as $host) {
            $lastResponse = ExternalHttp::client()
                ->timeout(25)
                ->withHeaders($headers)
                ->get('https://'.$host.'/v8/finance/chart/'.rawurlencode($yahooSymbol), $query);

            if ($lastResponse->successful()) {
                return $lastResponse;
            }
        }

        if ($lastResponse && $this->isMissingSymbolResponse($lastResponse)) {
            return $lastResponse;
        }

        return $lastResponse ?? throw new RuntimeException('Yahoo Finance request failed');
    }

    protected function isMissingSymbolResponse(Response $response): bool
    {
        $description = (string) $response->json('chart.error.description', '');

        return $description !== ''
            && (stripos($description, "doesn't exist") !== false
                || stripos($description, 'not found') !== false);
    }

    protected function mapSymbol(string $symbol, string $exchange = 'NSE'): string
    {
        if ($symbol === 'NIFTY50' || app(\App\Services\IndexCatalogService::class)->isConfiguredIndex($symbol)) {
            $def = app(\App\Services\IndexCatalogService::class)->definitionForSymbol($symbol);
            if ($def !== null && ($def['yahoo_symbol'] ?? '') !== '') {
                return (string) $def['yahoo_symbol'];
            }
            if ($symbol === 'NIFTY50') {
                return '^NSEI';
            }
        }

        if (str_contains($symbol, '.')) {
            return strtoupper($symbol);
        }

        return $exchange === 'BSE'
            ? strtoupper($symbol).'.BO'
            : strtoupper($symbol).'.NS';
    }
}
