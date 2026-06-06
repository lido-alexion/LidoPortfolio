<?php

namespace App\Services\PriceProviders;

use App\Contracts\PriceProviderInterface;
use App\Services\SettingsService;
use Carbon\Carbon;
use App\Support\ExternalHttp;

class AlphaVantagePriceProvider implements PriceProviderInterface
{
    public function __construct(protected SettingsService $settings) {}

    public function getName(): string
    {
        return 'alpha_vantage';
    }

    public function fetchHistorical(string $symbol, Carbon $from, Carbon $to, ?string $providerSymbol = null): array
    {
        $apiKey = $this->settings->get('alpha_vantage_api_key');
        if (! $apiKey) {
            throw new \RuntimeException('Alpha Vantage API key not configured');
        }

        $function = $symbol === 'NIFTY50' ? 'TIME_SERIES_DAILY' : 'TIME_SERIES_DAILY';
        $querySymbol = $providerSymbol ?? ($symbol === 'NIFTY50' ? 'NSEI' : $this->mapSymbol($symbol));

        $response = ExternalHttp::client()->timeout(25)->get('https://www.alphavantage.co/query', [
            'function' => $function,
            'symbol' => $querySymbol,
            'apikey' => $apiKey,
            'outputsize' => 'full',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Alpha Vantage request failed: '.$response->status());
        }

        $series = $response->json('Time Series (Daily)') ?? [];
        $rows = [];

        foreach ($series as $date => $values) {
            $priceDate = Carbon::parse($date);
            if ($priceDate->lt($from) || $priceDate->gt($to)) {
                continue;
            }

            $rows[] = [
                'price_date' => $priceDate->toDateString(),
                'open_price' => (float) ($values['1. open'] ?? 0),
                'high_price' => (float) ($values['2. high'] ?? 0),
                'low_price' => (float) ($values['3. low'] ?? 0),
                'close_price' => (float) ($values['4. close'] ?? 0),
                'volume' => isset($values['5. volume']) ? (int) $values['5. volume'] : null,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['price_date'], $b['price_date']));

        return $rows;
    }

    public function fetchLatest(string $symbol): ?array
    {
        $rows = $this->fetchHistorical($symbol, now()->subDays(30), now());

        return $rows ? end($rows) : null;
    }

    protected function mapSymbol(string $symbol): string
    {
        return strtoupper($symbol).'.BSE';
    }
}
