<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Stock;
use App\Support\ExternalHttp;
use App\Support\NseHttpClient;
use Carbon\Carbon;

class IndexConstituentService
{
    public function __construct(
        protected IndexCatalogService $catalog,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * @return list<array{symbol: string, name: string|null, stock_id: int|null}>
     */
    public function constituentsForSymbol(string $symbol, bool $forceRefresh = false): array
    {
        $def = $this->catalog->definitionForSymbol($symbol);
        if ($def === null || ! $this->catalog->supportsConstituents($def)) {
            return [];
        }

        $symbols = $this->symbolsForDefinition($def, $forceRefresh);
        if ($symbols === []) {
            return [];
        }

        $stocks = Stock::query()
            ->where('exchange', 'NSE')
            ->whereIn('symbol', $symbols)
            ->get(['id', 'symbol', 'name'])
            ->keyBy('symbol');

        $out = [];
        foreach ($symbols as $sym) {
            $stock = $stocks->get($sym);
            $out[] = [
                'symbol' => $sym,
                'name' => $stock?->name,
                'stock_id' => $stock?->id,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function symbols(string $symbol, bool $forceRefresh = false): array
    {
        $def = $this->catalog->definitionForSymbol($symbol);
        if ($def === null || ! $this->catalog->supportsConstituents($def)) {
            return [];
        }

        return $this->symbolsForDefinition($def, $forceRefresh);
    }

    /**
     * Refresh caches for all NSE indexes that support constituents (broad + sector).
     *
     * @return array{refreshed: int, failed: int}
     */
    public function refreshBroadNseCaches(): array
    {
        return $this->refreshSupportedCaches();
    }

    /**
     * @return array{refreshed: int, failed: int}
     */
    public function refreshSupportedCaches(): array
    {
        $refreshed = 0;
        $failed = 0;

        foreach ($this->catalog->enabledDefinitions() as $def) {
            if (! $this->catalog->supportsConstituents($def)) {
                continue;
            }
            $symbols = $this->symbolsForDefinition($def, forceRefresh: true);
            if ($symbols === []) {
                $failed++;
            } else {
                $refreshed++;
            }
        }

        return compact('refreshed', 'failed');
    }

    /**
     * @param  array<string, mixed>  $def
     * @return list<string>
     */
    protected function symbolsForDefinition(array $def, bool $forceRefresh): array
    {
        if (! $forceRefresh) {
            $cached = $this->cachedSymbols($def['symbol']);
            if ($cached !== []) {
                return $cached;
            }
        }

        $fetched = $this->fetchSymbols($def);
        if ($fetched !== []) {
            $this->storeCache($def['symbol'], $fetched);
            if ($def['symbol'] === 'NIFTY500') {
                $this->storeLegacyNifty500Cache($fetched);
            }

            return $fetched;
        }

        $cached = $this->cachedSymbols($def['symbol'], ignoreExpiry: true);
        if ($cached !== []) {
            $this->logger->scheduler('warning', 'Index constituent fetch failed; using stale cache', [
                'category' => 'IndexConstituents',
                'index_symbol' => $def['symbol'],
                'symbol_count' => count($cached),
            ]);

            return $cached;
        }

        if ($def['symbol'] === 'NIFTY500') {
            $legacy = $this->legacyNifty500Symbols();
            if ($legacy !== []) {
                return $legacy;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $def
     * @return list<string>
     */
    protected function fetchSymbols(array $def): array
    {
        $hints = $this->sourceHints($def);

        foreach ($hints['csv'] as $csvFile) {
            $fromCsv = $this->fetchFromArchivesCsv($csvFile, $def['symbol']);
            if ($fromCsv !== []) {
                return $fromCsv;
            }
        }

        foreach ($hints['api'] as $apiName) {
            $fromApi = $this->fetchFromNseApi($apiName, $def['symbol']);
            if ($fromApi !== []) {
                return $fromApi;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $def
     * @return array{csv: list<string>, api: list<string>}
     */
    protected function sourceHints(array $def): array
    {
        $charting = (string) ($def['nse_charting_name'] ?? '');

        return match ($def['symbol']) {
            'NIFTY50' => [
                'csv' => ['ind_nifty50list.csv'],
                'api' => ['NIFTY 50'],
            ],
            'NIFTYNEXT50' => [
                'csv' => ['ind_niftynext50list.csv'],
                'api' => ['NIFTY NEXT 50'],
            ],
            'NIFTY100' => [
                'csv' => ['ind_nifty100list.csv'],
                'api' => ['NIFTY 100'],
            ],
            'NIFTY200' => [
                'csv' => ['ind_nifty200list.csv'],
                'api' => ['NIFTY 200'],
            ],
            'NIFTY500' => [
                'csv' => ['ind_nifty500list.csv'],
                'api' => ['NIFTY 500'],
            ],
            'NIFTYMIDCAP50' => [
                'csv' => ['ind_niftymidcap50list.csv'],
                'api' => ['NIFTY MIDCAP 50'],
            ],
            'NIFTYMIDCAP100' => [
                'csv' => ['ind_niftymidcap100list.csv'],
                'api' => ['NIFTY MIDCAP 100'],
            ],
            'NIFTYMIDCAP150' => [
                'csv' => ['ind_niftymidcap150list.csv'],
                'api' => ['NIFTY MIDCAP 150'],
            ],
            'NIFTYSMLCAP250' => [
                'csv' => ['ind_niftysmallcap250list.csv', 'ind_niftysmlcap250list.csv'],
                'api' => ['NIFTY SMALLCAP 250', 'NIFTY SMLCAP 250'],
            ],
            'NIFTYBANK' => [
                'csv' => ['ind_niftybanklist.csv'],
                'api' => ['NIFTY BANK'],
            ],
            'NIFTYIT' => [
                'csv' => ['ind_niftyitlist.csv'],
                'api' => ['NIFTY IT'],
            ],
            'NIFTYFINSERVICE' => [
                'csv' => ['ind_niftyfinancelist.csv'],
                'api' => ['NIFTY FIN SERVICE', 'NIFTY FINANCIAL SERVICES'],
            ],
            'NIFTYPHARMA' => [
                'csv' => ['ind_niftypharmalist.csv'],
                'api' => ['NIFTY PHARMA'],
            ],
            'NIFTYAUTO' => [
                'csv' => ['ind_niftyautolist.csv'],
                'api' => ['NIFTY AUTO'],
            ],
            'NIFTYFMCG' => [
                'csv' => ['ind_niftyfmcglist.csv'],
                'api' => ['NIFTY FMCG'],
            ],
            'NIFTYMETAL' => [
                'csv' => ['ind_niftymetallist.csv'],
                'api' => ['NIFTY METAL'],
            ],
            'NIFTYREALTY' => [
                'csv' => ['ind_niftyrealtylist.csv'],
                'api' => ['NIFTY REALTY'],
            ],
            'NIFTYENERGY' => [
                'csv' => ['ind_niftyenergylist.csv'],
                'api' => ['NIFTY ENERGY'],
            ],
            'NIFTYINFRA' => [
                'csv' => ['ind_niftyinfralist.csv'],
                'api' => ['NIFTY INFRA', 'NIFTY INFRASTRUCTURE'],
            ],
            'NIFTYPSUBANK' => [
                'csv' => ['ind_niftypsubanklist.csv'],
                'api' => ['NIFTY PSU BANK'],
            ],
            'NIFTYPVTBANK' => [
                'csv' => ['ind_nifty_privatebanklist.csv'],
                'api' => ['NIFTY PVT BANK', 'NIFTY PRIVATE BANK'],
            ],
            'NIFTYMEDIA' => [
                'csv' => ['ind_niftymedialist.csv'],
                'api' => ['NIFTY MEDIA'],
            ],
            default => [
                'csv' => [],
                'api' => array_values(array_unique(array_filter([$charting]))),
            ],
        };
    }

    /**
     * @return list<string>
     */
    protected function fetchFromArchivesCsv(string $csvFile, string $symbol): array
    {
        $urls = [
            'https://nsearchives.nseindia.com/content/indices/'.$csvFile,
            'https://archives.nseindia.com/content/indices/'.$csvFile,
            'https://www.nseindia.com/content/indices/'.$csvFile,
        ];

        foreach ($urls as $url) {
            try {
                $response = ExternalHttp::client()
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => 'text/csv,text/plain,*/*',
                        'Referer' => 'https://www.nseindia.com/',
                    ])
                    ->timeout(25)
                    ->get($url);

                if (! $response->successful()) {
                    continue;
                }

                $symbols = $this->parseCsvSymbols($response->body());
                if ($symbols === []) {
                    continue;
                }

                $this->logger->scheduler('info', 'Index constituents refreshed from NSE archives CSV', [
                    'category' => 'IndexConstituents',
                    'index_symbol' => $symbol,
                    'csv' => $csvFile,
                    'url' => $url,
                    'symbol_count' => count($symbols),
                ]);

                return $symbols;
            } catch (\Throwable $e) {
                $this->logger->scheduler('warning', 'Index constituent CSV fetch failed', [
                    'category' => 'IndexConstituents',
                    'index_symbol' => $symbol,
                    'csv' => $csvFile,
                    'url' => $url,
                    'failure_reason' => $e->getMessage(),
                ]);
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    protected function parseCsvSymbols(string $body): array
    {
        $body = trim($body);
        if ($body === '' || ! str_contains(strtolower($body), 'symbol')) {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
        if ($lines === []) {
            return [];
        }

        $header = str_getcsv(array_shift($lines));
        $symbolIdx = null;
        foreach ($header as $i => $col) {
            if (strcasecmp(trim((string) $col), 'Symbol') === 0) {
                $symbolIdx = $i;
                break;
            }
        }
        if ($symbolIdx === null) {
            return [];
        }

        $symbols = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $sym = strtoupper(trim((string) ($cols[$symbolIdx] ?? '')));
            if ($sym !== '' && ! str_contains($sym, 'NIFTY') && ! str_contains($sym, 'SENSEX')) {
                $symbols[] = $sym;
            }
        }

        return array_values(array_unique($symbols));
    }

    /**
     * @return list<string>
     */
    protected function fetchFromNseApi(string $indexName, string $symbol): array
    {
        try {
            $client = NseHttpClient::create();
            // Market-data page improves cookie acceptance for equity-stockIndices.
            try {
                $client->get(
                    'https://www.nseindia.com/market-data/live-equity-market',
                    ['symbol' => $indexName],
                );
            } catch (\Throwable) {
                // Continue — API call may still succeed.
            }

            $response = $client->get(
                'https://www.nseindia.com/api/equity-stockIndices',
                ['index' => $indexName],
            );

            if (! $response->successful()) {
                throw new \RuntimeException('HTTP '.$response->status());
            }

            $rows = $response->json('data') ?? [];
            if (! is_array($rows) || $rows === []) {
                throw new \RuntimeException('Empty data payload');
            }

            $symbols = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rowSymbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
                if ($rowSymbol === '' || $rowSymbol === strtoupper($indexName)) {
                    continue;
                }
                // Skip index meta rows that look like index names.
                if (str_starts_with($rowSymbol, 'NIFTY ') || $rowSymbol === 'NIFTY') {
                    continue;
                }
                $symbols[] = $rowSymbol;
            }

            $symbols = array_values(array_unique($symbols));
            if ($symbols === []) {
                throw new \RuntimeException('No equity symbols parsed');
            }

            $this->logger->scheduler('info', 'Index constituents refreshed from NSE API', [
                'category' => 'IndexConstituents',
                'index_symbol' => $symbol,
                'index_name' => $indexName,
                'symbol_count' => count($symbols),
            ]);

            return $symbols;
        } catch (\Throwable $e) {
            $this->logger->scheduler('error', 'Index constituent API fetch failed', [
                'category' => 'IndexConstituents',
                'index_symbol' => $symbol,
                'index_name' => $indexName,
                'failure_reason' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return list<string>
     */
    protected function cachedSymbols(string $symbol, bool $ignoreExpiry = false): array
    {
        $raw = Setting::getValue($this->cacheKey($symbol));
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        if (! $ignoreExpiry) {
            $cachedAt = Setting::getValue($this->cacheAtKey($symbol));
            $maxDays = (int) config('portfolio.universe_price_sync.nifty500_cache_days', 7);
            if ($cachedAt && Carbon::parse($cachedAt)->lt(now()->subDays($maxDays))) {
                return [];
            }
        }

        return $this->decodeSymbolList($raw);
    }

    /**
     * @return list<string>
     */
    protected function legacyNifty500Symbols(): array
    {
        $raw = Setting::getValue(Nifty500ConstituentService::CACHE_KEY);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        return $this->decodeSymbolList($raw);
    }

    /**
     * @param  list<string>  $symbols
     */
    protected function storeLegacyNifty500Cache(array $symbols): void
    {
        Setting::setValue(Nifty500ConstituentService::CACHE_KEY, json_encode(array_values($symbols)));
        Setting::setValue(Nifty500ConstituentService::CACHE_AT_KEY, now()->toIso8601String());
    }

    /**
     * @return list<string>
     */
    protected function decodeSymbolList(string $raw): array
    {
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
    protected function storeCache(string $symbol, array $symbols): void
    {
        Setting::setValue($this->cacheKey($symbol), json_encode(array_values($symbols)));
        Setting::setValue($this->cacheAtKey($symbol), now()->toIso8601String());
    }

    protected function cacheKey(string $symbol): string
    {
        return 'index_constituents_'.strtolower($symbol).'_json';
    }

    protected function cacheAtKey(string $symbol): string
    {
        return 'index_constituents_'.strtolower($symbol).'_cached_at';
    }
}
