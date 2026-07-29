<?php

namespace App\Services\PriceProviders;

use App\Contracts\PriceProviderInterface;
use App\Services\SettingsService;
use App\Support\IndiaVixScale;
use App\Support\NseChartingHttpClient;
use App\Support\NseHttpClient;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;

class NsePriceProvider implements PriceProviderInterface
{
    public function __construct(protected SettingsService $settings) {}

    public function getName(): string
    {
        return 'nse';
    }

    public function fetchHistorical(string $symbol, Carbon $from, Carbon $to, ?string $providerSymbol = null): array
    {
        $catalog = app(\App\Services\IndexCatalogService::class);
        $chartingName = $catalog->nseChartingNameForSymbol($symbol);
        if ($chartingName !== null) {
            return $this->fetchIndexHistorical($from, $to, $chartingName);
        }

        $tradeSymbol = $providerSymbol ?: $symbol;
        $retryCount = max(1, (int) ($this->settings->get('nse_retry_count') ?: 3));
        $lastException = null;

        for ($attempt = 1; $attempt <= $retryCount; $attempt++) {
            try {
                $rows = $this->fetchEquityHistorical($tradeSymbol, $from, $to);
                if ($rows !== []) {
                    return $rows;
                }
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        if ($lastException) {
            throw $lastException;
        }

        return [];
    }

    public function fetchLatest(string $symbol): ?array
    {
        $rows = $this->fetchHistorical($symbol, now()->subDays(10), now());

        return $rows ? end($rows) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchEquityHistorical(string $symbol, Carbon $from, Carbon $to): array
    {
        $chartingRows = $this->filterRowsToRange(
            $this->fetchEquityHistoricalViaCharting($symbol, $from, $to),
            $from,
            $to,
        );
        if ($chartingRows !== []) {
            return $chartingRows;
        }

        return $this->filterRowsToRange(
            $this->fetchEquityHistoricalViaNextApi($symbol, $from, $to),
            $from,
            $to,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchEquityHistoricalViaCharting(string $symbol, Carbon $from, Carbon $to): array
    {
        $client = NseChartingHttpClient::create();
        $symbolInfo = $this->resolveEquityChartingSymbol($client, $symbol);
        $rows = [];

        foreach ($this->dateChunks($from, $to, 365) as $chunk) {
            $response = $client->get('https://charting.nseindia.com/v1/charts/symbolHistoricalData', [
                'fromDate' => $chunk['from']->copy()->startOfDay()->timestamp,
                'toDate' => $chunk['to']->copy()->endOfDay()->timestamp,
                'symbol' => $symbolInfo['symbol'],
                'token' => $symbolInfo['scripcode'],
                'symbolType' => 'Equity',
                'chartType' => 'D',
                'timeInterval' => '1',
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('NSE charting equity request failed: '.$response->status());
            }

            $data = $response->json('data') ?? [];
            if (! is_array($data)) {
                throw new RuntimeException('NSE charting equity response invalid');
            }

            foreach ($data as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $mapped = $this->mapChartingRow($row);
                if ($mapped !== null) {
                    $rows[$mapped['price_date']] = $mapped;
                }
            }
        }

        if ($rows === []) {
            return [];
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchEquityHistoricalViaNextApi(string $symbol, Carbon $from, Carbon $to): array
    {
        $client = NseHttpClient::create();
        [$baseSymbol, $series] = $this->parseTradeSymbol($symbol);
        $rows = [];

        foreach ($this->dateChunks($from, $to) as $chunk) {
            $response = $client->get(
                'https://www.nseindia.com/api/NextApi/apiClient/GetQuoteApi',
                [
                    'functionName' => 'getHistoricalTradeData',
                    'symbol' => $baseSymbol,
                    'series' => $series,
                    'fromDate' => $chunk['from']->format('d-m-Y'),
                    'toDate' => $chunk['to']->format('d-m-Y'),
                ],
            );

            if (! $response->successful()) {
                throw new RuntimeException('NSE historical request failed: '.$response->status());
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new RuntimeException('NSE historical response was not an array');
            }

            foreach ($data as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $mapped = $this->mapNextApiEquityRow($row);
                if ($mapped !== null) {
                    $rows[$mapped['price_date']] = $mapped;
                }
            }
        }

        if ($rows === []) {
            return [];
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchIndexHistorical(Carbon $from, Carbon $to, string $chartingName = 'NIFTY 50'): array
    {
        $client = NseChartingHttpClient::create();
        $symbolInfo = $this->resolveChartingSymbol($client, $chartingName, 'IDX');
        $rows = [];

        foreach ($this->dateChunks($from, $to, 365) as $chunk) {
            $response = $client->get('https://charting.nseindia.com/v1/charts/symbolHistoricalData', [
                'fromDate' => $chunk['from']->copy()->startOfDay()->timestamp,
                'toDate' => $chunk['to']->copy()->endOfDay()->timestamp,
                'symbol' => $symbolInfo['symbol'],
                'token' => $symbolInfo['scripcode'],
                'symbolType' => 'Index',
                'chartType' => 'D',
                'timeInterval' => '1',
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('NSE index chart request failed: '.$response->status());
            }

            $data = $response->json('data') ?? [];
            if (! is_array($data)) {
                throw new RuntimeException('NSE index chart response invalid');
            }

            foreach ($data as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $mapped = $this->mapChartingRow($row);
                if ($mapped !== null) {
                    if (strcasecmp($chartingName, 'INDIA VIX') === 0) {
                        $mapped = IndiaVixScale::normalizeRow($mapped);
                    }
                    $rows[$mapped['price_date']] = $mapped;
                }
            }
        }

        if ($rows === []) {
            return [];
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * @return array<int, array{from: Carbon, to: Carbon}>
     */
    protected function dateChunks(Carbon $from, Carbon $to, int $maxDays = 66): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $chunks = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $chunkEnd = $cursor->copy()->addDays($maxDays - 1);
            if ($chunkEnd->gt($to)) {
                $chunkEnd = $to->copy();
            }

            $chunks[] = [
                'from' => $cursor->copy(),
                'to' => $chunkEnd->copy(),
            ];

            $cursor = $chunkEnd->copy()->addDay();
        }

        return $chunks;
    }

    /**
     * @return array{symbol: string, scripcode: string}
     */
    protected function resolveEquityChartingSymbol(PendingRequest $client, string $symbol): array
    {
        $upper = strtoupper($symbol);
        $queries = [$upper];
        if (! str_contains($upper, '-')) {
            array_unshift($queries, $upper.'-EQ');
        }

        foreach ($queries as $query) {
            $response = $client->get('https://charting.nseindia.com/v1/exchanges/symbolsDynamic', [
                'symbol' => $query,
                'segment' => '',
            ]);

            if (! $response->successful()) {
                continue;
            }

            $list = $response->json('data') ?? [];
            if (! is_array($list)) {
                continue;
            }

            foreach ($list as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $candidate = strtoupper((string) ($item['symbol'] ?? ''));
                if ($candidate === strtoupper($query)) {
                    return [
                        'symbol' => (string) ($item['symbol'] ?? $query),
                        'scripcode' => (string) ($item['scripcode'] ?? ''),
                    ];
                }
            }
        }

        throw new RuntimeException('NSE charting symbol not found: '.$symbol);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function parseTradeSymbol(string $tradeSymbol): array
    {
        $upper = strtoupper(trim($tradeSymbol));
        if (preg_match('/^([A-Z0-9][A-Z0-9\-&]*)-(EQ|BE|BZ|IV|E1|E2|SM|ST|GS|GB|MF)$/', $upper, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [$upper, 'EQ'];
    }

    /**
     * @return array{symbol: string, scripcode: string}
     */
    protected function resolveChartingSymbol(PendingRequest $client, string $symbol, string $segment): array
    {
        $response = $client->get('https://charting.nseindia.com/v1/exchanges/symbolsDynamic', [
            'symbol' => $symbol,
            'segment' => $segment,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('NSE charting symbol lookup failed: '.$response->status());
        }

        $list = $response->json('data') ?? [];
        if (! is_array($list) || $list === []) {
            throw new RuntimeException('NSE charting symbol not found: '.$symbol);
        }

        $upper = strtoupper($symbol);
        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }

            $candidate = strtoupper((string) ($item['symbol'] ?? ''));
            if ($candidate === $upper || $candidate === "{$upper}-EQ") {
                return [
                    'symbol' => (string) ($item['symbol'] ?? $symbol),
                    'scripcode' => (string) ($item['scripcode'] ?? ''),
                ];
            }
        }

        $first = $list[0];

        return [
            'symbol' => (string) ($first['symbol'] ?? $symbol),
            'scripcode' => (string) ($first['scripcode'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    protected function mapNextApiEquityRow(array $row): ?array
    {
        $timestamp = $row['mtimestamp'] ?? $row['CH_TIMESTAMP'] ?? null;
        if (! is_string($timestamp) || $timestamp === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('d-M-Y', $timestamp);
        } catch (\Throwable) {
            try {
                $date = Carbon::parse($timestamp)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        $close = (float) ($row['chClosingPrice'] ?? $row['CH_CLOSING_PRICE'] ?? $row['chLastTradedPrice'] ?? 0);
        if ($close <= 0) {
            return null;
        }

        return [
            'price_date' => $date->toDateString(),
            'open_price' => (float) ($row['chOpeningPrice'] ?? $row['CH_OPENING_PRICE'] ?? $close),
            'high_price' => (float) ($row['chTradeHighPrice'] ?? $row['CH_TRADE_HIGH_PRICE'] ?? $close),
            'low_price' => (float) ($row['chTradeLowPrice'] ?? $row['CH_TRADE_LOW_PRICE'] ?? $close),
            'close_price' => $close,
            'volume' => isset($row['chTotTradedQty']) ? (int) $row['chTotTradedQty'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    protected function mapChartingRow(array $row): ?array
    {
        $time = $row['time'] ?? null;
        if ($time === null) {
            return null;
        }

        $date = is_numeric($time)
            ? Carbon::createFromTimestampMs((int) $time)->startOfDay()
            : Carbon::parse($time)->startOfDay();

        $close = (float) ($row['close'] ?? 0);
        if ($close <= 0) {
            return null;
        }

        return [
            'price_date' => $date->toDateString(),
            'open_price' => isset($row['open']) ? (float) $row['open'] : $close,
            'high_price' => isset($row['high']) ? (float) $row['high'] : $close,
            'low_price' => isset($row['low']) ? (float) $row['low'] : $close,
            'close_price' => $close,
            'volume' => isset($row['volume']) ? (int) $row['volume'] : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function filterRowsToRange(array $rows, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        return array_values(array_filter($rows, function (array $row) use ($from, $to) {
            if (! isset($row['price_date'])) {
                return false;
            }

            $date = Carbon::parse($row['price_date'])->startOfDay();

            return $date->gte($from) && $date->lte($to);
        }));
    }
}
