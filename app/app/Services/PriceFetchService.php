<?php

namespace App\Services;

use App\Contracts\PriceProviderInterface;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\PriceProviders\AlphaVantagePriceProvider;
use App\Services\PriceProviders\BseBhavcopyPriceProvider;
use App\Services\PriceProviders\NsePriceProvider;
use App\Services\PriceProviders\YahooPriceProvider;
use App\Support\TradingCalendar;
use Carbon\Carbon;

class PriceFetchService
{
    /** MySQL INT UNSIGNED max — production may still use INT until migration widens the column. */
    public const MAX_VOLUME_INT_UNSIGNED = 4294967295;
    /** @var array<int, PriceProviderInterface> */
    protected array $providers;

    public function __construct(
        NsePriceProvider $nse,
        BseBhavcopyPriceProvider $bseBhavcopy,
        YahooPriceProvider $yahoo,
        AlphaVantagePriceProvider $alphaVantage,
        protected SystemLogService $logger,
        protected PortfolioLoggerService $portfolioLogger,
        protected ProviderResolverService $providerResolver,
        protected TelegramNotificationService $telegram,
    ) {
        $this->providers = [$nse, $bseBhavcopy, $yahoo, $alphaVantage];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    public function fetchFromProvider(
        string $providerName,
        string $symbol,
        Carbon $from,
        Carbon $to,
        ?Stock $stock = null,
    ): array {
        if ($stock && $providerName === 'nse' && strtoupper((string) $stock->exchange) === 'BSE') {
            return ['rows' => [], 'errors' => ['nse: skipped (BSE-only symbol)']];
        }

        if ($stock && $providerName === 'bse_bhavcopy' && strtoupper((string) $stock->exchange) !== 'BSE') {
            return ['rows' => [], 'errors' => ['bse_bhavcopy: skipped (non-BSE symbol)']];
        }

        $provider = $this->resolveProvider($providerName);

        if ($providerName === 'yahoo' && $stock) {
            return $this->fetchYahooWithCandidates($provider, $symbol, $from, $to, $stock);
        }

        try {
            $providerSymbols = $stock ? $this->providerResolver->providerSymbolsForStock($stock) : [];
            $providerSymbol = $providerSymbols[$providerName] ?? null;
            $rows = $provider->fetchHistorical($symbol, $from, $to, $providerSymbol);
            $rows = $this->filterRowsToRange($rows, $from, $to);

            if ($rows === []) {
                return ['rows' => [], 'errors' => ["{$providerName}: returned 0 rows in requested range"]];
            }

            return ['rows' => $rows, 'errors' => []];
        } catch (\Throwable $e) {
            return ['rows' => [], 'errors' => ["{$providerName}: ".$e->getMessage()]];
        }
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    protected function fetchYahooWithCandidates(
        PriceProviderInterface $provider,
        string $symbol,
        Carbon $from,
        Carbon $to,
        Stock $stock,
    ): array {
        $candidateErrors = [];

        foreach ($this->providerResolver->yahooSymbolCandidates($stock) as $yahooSymbol) {
            try {
                $rows = $provider->fetchHistorical($symbol, $from, $to, $yahooSymbol);
                $rows = $this->filterRowsToRange($rows, $from, $to);

                if ($rows !== []) {
                    return ['rows' => $rows, 'errors' => []];
                }

                $candidateErrors[] = "yahoo ({$yahooSymbol}): returned 0 rows in requested range";
            } catch (\Throwable $e) {
                $candidateErrors[] = "yahoo ({$yahooSymbol}): ".$e->getMessage();
            }
        }

        return [
            'rows' => [],
            'errors' => [$candidateErrors !== [] ? implode(' · ', $candidateErrors) : 'yahoo: returned 0 rows in requested range'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function providerChainForStock(?Stock $stock = null): array
    {
        if ($stock && $stock->is_benchmark) {
            if (strtoupper((string) $stock->exchange) === 'BSE') {
                return ['yahoo', 'alpha_vantage'];
            }

            return ['nse', 'yahoo', 'alpha_vantage'];
        }

        if ($stock && strtoupper((string) $stock->exchange) === 'BSE') {
            return ['bse_bhavcopy', 'yahoo', 'alpha_vantage'];
        }

        return ['nse', 'yahoo', 'alpha_vantage'];
    }

    /**
     * @return array{rows: array, provider: string, errors: array<int, string>, providers_tried: array<int, string>}
     */
    public function fetchHistoricalWithFallback(
        string $symbol,
        Carbon $from,
        Carbon $to,
        ?Stock $stock = null,
        bool $notifyTelegramOnFailure = true,
    ): array
    {
        $errors = [];
        $perProviderRetries = 2;
        $previousProvider = null;
        $providersTried = [];
        $providerSymbols = $stock ? $this->providerResolver->providerSymbolsForStock($stock) : [];

        foreach ($this->providers as $provider) {
            $providerName = $provider->getName();
            if ($stock && $providerName === 'nse' && strtoupper((string) $stock->exchange) === 'BSE') {
                continue;
            }

            if ($stock && $providerName === 'bse_bhavcopy' && strtoupper((string) $stock->exchange) !== 'BSE') {
                continue;
            }

            if (! $stock && $providerName === 'bse_bhavcopy') {
                continue;
            }

            if (! in_array($providerName, $providersTried, true)) {
                $providersTried[] = $providerName;
            }

            for ($attempt = 1; $attempt <= $perProviderRetries; $attempt++) {
                $requestedAt = now()->toIso8601String();
                try {
                    if ($providerName === 'yahoo' && $stock) {
                        $yahooResult = $this->fetchYahooWithCandidates($provider, $symbol, $from, $to, $stock);
                        $rows = $yahooResult['rows'];
                        if ($rows === [] && ($yahooResult['errors'] ?? []) !== []) {
                            $errors = array_merge($errors, $yahooResult['errors']);
                            $this->portfolioLogger->provider('warning', 'Provider returned no rows', [
                                'provider' => $providerName,
                                'symbol' => $symbol,
                                'attempt' => $attempt,
                                'request_time' => $requestedAt,
                                'from_date' => $from->toDateString(),
                                'to_date' => $to->toDateString(),
                                'failure_reason' => implode('; ', $yahooResult['errors']),
                            ]);
                            usleep(150000);
                            continue;
                        }
                    } else {
                        $providerSymbol = $providerSymbols[$provider->getName()] ?? null;
                        $rows = $provider->fetchHistorical($symbol, $from, $to, $providerSymbol);
                        $rows = $this->filterRowsToRange($rows, $from, $to);
                    }

                    if ($rows !== []) {
                        if ($previousProvider !== null) {
                            $this->portfolioLogger->provider('info', ucfirst($provider->getName()).' fallback activated', [
                                'symbol' => $symbol,
                                'from_provider' => $previousProvider,
                                'to_provider' => $provider->getName(),
                                'request_time' => $requestedAt,
                                'row_count' => count($rows),
                            ]);
                        }

                        return [
                            'rows' => $rows,
                            'provider' => $provider->getName(),
                            'errors' => $errors,
                            'providers_tried' => $providersTried,
                        ];
                    }

                    $errors[] = $provider->getName()."(attempt {$attempt}): returned 0 rows in requested range";
                    $this->portfolioLogger->provider('warning', 'Provider returned no rows', [
                        'provider' => $provider->getName(),
                        'symbol' => $symbol,
                        'attempt' => $attempt,
                        'request_time' => $requestedAt,
                        'from_date' => $from->toDateString(),
                        'to_date' => $to->toDateString(),
                    ]);
                } catch (\Throwable $e) {
                    $errors[] = $provider->getName()."(attempt {$attempt}): ".$e->getMessage();
                    $this->portfolioLogger->provider('error', 'Provider request failed', [
                        'provider' => $provider->getName(),
                        'symbol' => $symbol,
                        'attempt' => $attempt,
                        'request_time' => $requestedAt,
                        'failure_reason' => $e->getMessage(),
                    ]);
                    $this->logger->log('api_failure', 'Provider failed', [
                        'provider' => $provider->getName(),
                        'symbol' => $symbol,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                }

                usleep(150000);
            }

            $previousProvider = $provider->getName();
        }

        $message = 'All providers failed for '.$symbol.' ('.implode('; ', $errors).')';
        $this->logger->log('api_failure', $message, ['symbol' => $symbol]);
        $this->logger->log('invalid_symbol', 'Unable to resolve symbol from any provider', ['symbol' => $symbol], 'warning');
        if (PriceSyncNotificationContext::shouldNotifyTelegramOnFailure($notifyTelegramOnFailure)) {
            $this->telegram->sendSyncFailureAlert($message);
        }

        return ['rows' => [], 'provider' => 'none', 'errors' => $errors, 'providers_tried' => $providersTried];
    }

    public function storeHistoricalRows(Stock $stock, array $rows, string $provider): int
    {
        $stored = 0;

        foreach ($rows as $row) {
            $priceDate = Carbon::parse($row['price_date'])->startOfDay();
            if (! TradingCalendar::isEquitySessionDate($priceDate)) {
                continue;
            }

            StockPrice::query()->updateOrCreate(
                [
                    'stock_id' => $stock->id,
                    'price_date' => $row['price_date'],
                ],
                [
                    'open_price' => $row['open_price'],
                    'high_price' => $row['high_price'],
                    'low_price' => $row['low_price'],
                    'close_price' => $row['close_price'],
                    'volume' => $this->normalizeVolumeForStorage($row['volume'] ?? null, $stock),
                    'adjusted_close_price' => $row['adjusted_close_price'] ?? $row['close_price'],
                    'provider_source' => $provider,
                    'data_source' => $provider,
                    'created_at' => now(),
                ],
            );
            $stored++;
        }

        return $stored;
    }

    /**
     * Index/benchmark volume from NSE charting is an aggregate across constituents and can exceed
     * legacy INT UNSIGNED columns. Benchmark rows store null volume; equities clamp overflow to null.
     */
    public function normalizeVolumeForStorage(mixed $volume, ?Stock $stock = null): ?int
    {
        if ($stock?->is_benchmark) {
            return null;
        }

        if ($volume === null || $volume === '') {
            return null;
        }

        if (! is_numeric($volume)) {
            return null;
        }

        $normalized = (int) $volume;
        if ($normalized < 0) {
            return null;
        }

        if ($normalized > self::MAX_VOLUME_INT_UNSIGNED) {
            return null;
        }

        return $normalized;
    }

    /**
     * @return array{
     *   stored_rows: int,
     *   fetched_rows: int,
     *   provider: string,
     *   from_date: string,
     *   to_date: string,
     *   errors: array<int, string>,
     *   success: bool
     * }
     */
    public function syncStock(
        Stock $stock,
        ?Carbon $from = null,
        ?Carbon $to = null,
        bool $notifyTelegramOnFailure = true,
    ): array {
        $from = $from ?? $this->determineBackfillStart($stock);
        $to = ($to ?? now())->copy()->startOfDay();

        $history = app(StockPriceHistoryService::class);
        $sync = $history->fetchMissingHistory($stock, $from, $to, $notifyTelegramOnFailure);

        return [
            'stored_rows' => $sync['stored_rows'],
            'fetched_rows' => $sync['fetched_rows'],
            'provider' => $sync['ranges_fetched'][0]['provider'] ?? ($sync['cache_hit'] ? 'cache' : 'none'),
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'errors' => $sync['errors'],
            'success' => $sync['success'],
            'cache_hit' => $sync['cache_hit'],
        ];
    }

    /**
     * @deprecated Use BenchmarkPriceSyncService::syncIfNeeded()
     */
    public function syncBenchmark(): int
    {
        $result = app(BenchmarkPriceSyncService::class)->syncIfNeeded(force: true);

        return (int) ($result['stored_rows'] ?? 0);
    }

    protected function determineBackfillStart(Stock $stock): Carbon
    {
        $firstTransactionDate = $stock->transactions()->min('transaction_date');

        if ($firstTransactionDate) {
            return Carbon::parse($firstTransactionDate)->subMonths(3)->startOfDay();
        }

        $firstStoredDate = StockPrice::query()->where('stock_id', $stock->id)->min('price_date');
        if ($firstStoredDate) {
            return Carbon::parse($firstStoredDate)->startOfDay();
        }

        return now()->subMonths(6)->startOfDay();
    }

    protected function resolveProvider(string $providerName): PriceProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->getName() === $providerName) {
                return $provider;
            }
        }

        throw new \InvalidArgumentException('Unknown price provider: '.$providerName);
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
