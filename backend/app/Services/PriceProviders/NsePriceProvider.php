<?php

namespace App\Services\PriceProviders;

use App\Contracts\PriceProviderInterface;
use App\Services\SettingsService;
use Carbon\Carbon;
use App\Support\NseHttpClient;

class NsePriceProvider implements PriceProviderInterface
{
    public function __construct(protected SettingsService $settings) {}

    public function getName(): string
    {
        return 'nse';
    }

    public function fetchHistorical(string $symbol, Carbon $from, Carbon $to, ?string $providerSymbol = null): array
    {
        if ($symbol === 'NIFTY50') {
            return $this->fetchIndexHistorical($from, $to);
        }

        $retryCount = (int) $this->settings->get('nse_retry_count', '3');
        $lastException = null;

        for ($attempt = 1; $attempt <= $retryCount; $attempt++) {
            try {
                $rows = $this->fetchEquityHistorical($symbol, $from, $to);
                if (! empty($rows)) {
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

    protected function fetchEquityHistorical(string $symbol, Carbon $from, Carbon $to): array
    {
        $response = $this->client()->get('https://www.nseindia.com/api/historical/cm/equity', [
            'symbol' => strtoupper($symbol),
            'series' => '["EQ"]',
            'from' => $from->format('d-m-Y'),
            'to' => $to->format('d-m-Y'),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('NSE historical request failed: '.$response->status());
        }

        $data = $response->json('data') ?? [];

        return collect($data)->map(function (array $row) {
            $date = Carbon::createFromFormat('d-M-Y', $row['CH_TIMESTAMP'] ?? $row['CH_TRADE_HIGH_DATE'] ?? now()->format('d-M-Y'));

            return [
                'price_date' => $date->toDateString(),
                'open_price' => (float) ($row['CH_OPENING_PRICE'] ?? $row['CH_OPEN_PRICE'] ?? 0),
                'high_price' => (float) ($row['CH_TRADE_HIGH_PRICE'] ?? $row['CH_TRADE_HIGH_PRICE'] ?? 0),
                'low_price' => (float) ($row['CH_TRADE_LOW_PRICE'] ?? 0),
                'close_price' => (float) ($row['CH_CLOSING_PRICE'] ?? $row['CH_LAST_TRADED_PRICE'] ?? 0),
                'volume' => isset($row['CH_TOT_TRADED_QTY']) ? (int) $row['CH_TOT_TRADED_QTY'] : null,
            ];
        })->sortBy('price_date')->values()->all();
    }

    protected function fetchIndexHistorical(Carbon $from, Carbon $to): array
    {
        $response = $this->client()->get('https://www.nseindia.com/api/historical/indicesHistory', [
            'indexType' => 'NIFTY 50',
            'from' => $from->format('d-m-Y'),
            'to' => $to->format('d-m-Y'),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('NSE index historical request failed: '.$response->status());
        }

        $data = $response->json('data') ?? $response->json('data.indexCloseOnlineRecords') ?? [];

        return collect($data)->map(function (array $row) {
            $date = Carbon::parse($row['EOD_TIMESTAMP'] ?? $row['TIMESTAMP'] ?? now());

            return [
                'price_date' => $date->toDateString(),
                'open_price' => isset($row['EOD_OPEN_INDEX_VAL']) ? (float) $row['EOD_OPEN_INDEX_VAL'] : null,
                'high_price' => isset($row['EOD_HIGH_INDEX_VAL']) ? (float) $row['EOD_HIGH_INDEX_VAL'] : null,
                'low_price' => isset($row['EOD_LOW_INDEX_VAL']) ? (float) $row['EOD_LOW_INDEX_VAL'] : null,
                'close_price' => (float) ($row['EOD_CLOSE_INDEX_VAL'] ?? $row['CLOSE_INDEX_VAL'] ?? 0),
                'volume' => null,
            ];
        })->sortBy('price_date')->values()->all();
    }

    protected function client()
    {
        return NseHttpClient::create();
    }
}
