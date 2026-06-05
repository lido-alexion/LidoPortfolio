<?php

namespace App\Services\PriceProviders;

use App\Contracts\PriceProviderInterface;
use Carbon\Carbon;
use App\Support\ExternalHttp;

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

        $response = ExternalHttp::client()->timeout(20)->get('https://query1.finance.yahoo.com/v8/finance/chart/'.$yahooSymbol, [
            'period1' => $period1,
            'period2' => $period2,
            'interval' => '1d',
            'events' => 'history',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Yahoo Finance request failed: '.$response->status());
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

    protected function mapSymbol(string $symbol, string $exchange = 'NSE'): string
    {
        if ($symbol === 'NIFTY50') {
            return '^NSEI';
        }

        if (str_contains($symbol, '.')) {
            return strtoupper($symbol);
        }

        return $exchange === 'BSE'
            ? strtoupper($symbol).'.BO'
            : strtoupper($symbol).'.NS';
    }
}
