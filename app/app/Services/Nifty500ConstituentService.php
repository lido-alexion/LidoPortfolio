<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\NseHttpClient;
use Carbon\Carbon;

class Nifty500ConstituentService
{
    public const CACHE_KEY = 'nifty500_constituents_json';

    public const CACHE_AT_KEY = 'nifty500_constituents_cached_at';

    public function __construct(
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * @return list<string> Uppercase NSE symbols (no suffix)
     */
    public function symbols(bool $forceRefresh = false): array
    {
        if (! $forceRefresh) {
            $cached = $this->cachedSymbols();
            if ($cached !== []) {
                return $cached;
            }
        }

        $fetched = $this->fetchFromNse();
        if ($fetched !== []) {
            $this->storeCache($fetched);

            return $fetched;
        }

        $cached = $this->cachedSymbols(ignoreExpiry: true);
        if ($cached !== []) {
            $this->logger->scheduler('warning', 'NIFTY 500 constituent fetch failed; using stale cache', [
                'category' => 'UniversePriceSync',
                'symbol_count' => count($cached),
            ]);

            return $cached;
        }

        return [];
    }

    /**
     * @return list<string>
     */
    protected function cachedSymbols(bool $ignoreExpiry = false): array
    {
        $raw = Setting::getValue(self::CACHE_KEY);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        if (! $ignoreExpiry) {
            $cachedAt = Setting::getValue(self::CACHE_AT_KEY);
            $maxDays = (int) config('portfolio.universe_price_sync.nifty500_cache_days', 7);
            if ($cachedAt && Carbon::parse($cachedAt)->lt(now()->subDays($maxDays))) {
                return [];
            }
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($s) => is_string($s) ? strtoupper(trim($s)) : null,
            $decoded,
        ))));
    }

    /**
     * @param  list<string>  $symbols
     */
    protected function storeCache(array $symbols): void
    {
        Setting::setValue(self::CACHE_KEY, json_encode(array_values($symbols)));
        Setting::setValue(self::CACHE_AT_KEY, now()->toIso8601String());
    }

    /**
     * @return list<string>
     */
    protected function fetchFromNse(): array
    {
        $indexName = config('portfolio.universe_price_sync.nifty500_index_name', 'NIFTY 500');

        try {
            $response = NseHttpClient::create()->get(
                'https://www.nseindia.com/api/equity-stockIndices',
                ['index' => $indexName],
            );

            if (! $response->successful()) {
                throw new \RuntimeException('HTTP '.$response->status());
            }

            $rows = $response->json('data') ?? [];
            $symbols = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
                if ($symbol !== '') {
                    $symbols[] = $symbol;
                }
            }

            $symbols = array_values(array_unique($symbols));
            $this->logger->scheduler('info', 'NIFTY 500 constituents refreshed from NSE', [
                'category' => 'UniversePriceSync',
                'symbol_count' => count($symbols),
                'index' => $indexName,
            ]);

            return $symbols;
        } catch (\Throwable $e) {
            $this->logger->scheduler('error', 'NIFTY 500 constituent fetch failed', [
                'category' => 'UniversePriceSync',
                'failure_reason' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
