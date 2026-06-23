<?php

namespace App\Services;

use App\Contracts\PriceProviderInterface;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\PriceProviders\AlphaVantagePriceProvider;
use App\Services\PriceProviders\NsePriceProvider;
use App\Services\PriceProviders\YahooPriceProvider;
use App\Support\TradingCalendar;
use Carbon\Carbon;

class PriceFetchService
{
    /** @var array<int, PriceProviderInterface> */
    protected array $providers;

    public function __construct(
        NsePriceProvider $nse,
        YahooPriceProvider $yahoo,
        AlphaVantagePriceProvider $alphaVantage,
        protected SystemLogService $logger,
        protected PortfolioLoggerService $portfolioLogger,
        protected ProviderResolverService $providerResolver,
        protected TelegramNotificationService $telegram,
    ) {
        $this->providers = [$nse, $yahoo, $alphaVantage];
    }

    /**
     * @return array{rows: array, provider: string, errors: array<int, string>}
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
        $providerSymbols = $stock ? $this->providerResolver->providerSymbolsForStock($stock) : [];

        foreach ($this->providers as $provider) {
            for ($attempt = 1; $attempt <= $perProviderRetries; $attempt++) {
                $requestedAt = now()->toIso8601String();
                try {
                    $providerSymbol = $providerSymbols[$provider->getName()] ?? null;
                    $rows = $provider->fetchHistorical($symbol, $from, $to, $providerSymbol);
                    if (! empty($rows)) {
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
                        ];
                    }

                    $errors[] = $provider->getName()."(attempt {$attempt}): returned 0 rows";
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

        return ['rows' => [], 'provider' => 'none', 'errors' => $errors];
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
                    'volume' => $row['volume'],
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

    public function syncBenchmark(): int
    {
        $benchmark = app(RelativeStrengthService::class)->benchmarkStock();

        return $this->syncStock($benchmark, now()->subMonths(12), now())['stored_rows'];
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
}
