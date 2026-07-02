<?php

namespace App\Services;

use App\Jobs\BackfillHistoricalDataJob;
use App\Models\Stock;
use App\Support\NseHttpClient;
use App\Support\ExternalHttp;
use App\Support\StockValidationResult;
use Carbon\Carbon;
use InvalidArgumentException;

class StockValidationService
{
  public function __construct(
    protected ProviderResolverService $resolver,
    protected PortfolioLoggerService $portfolioLogger,
    protected SettingsService $settings,
    protected EquityUniverseService $equityUniverse,
  ) {}

  public function validate(string $inputSymbol, ?string $exchange = 'NSE', bool $allowProvider = true): StockValidationResult
  {
    if ($this->resolver->isMalformed($inputSymbol)) {
      $this->portfolioLogger->validation('warning', 'Malformed stock symbol rejected', [
        'symbol' => $inputSymbol,
        'exchange' => $exchange,
      ]);

      return StockValidationResult::invalid(['Symbol format is invalid.']);
    }

    try {
      $normalized = $this->resolver->normalizeSymbol($inputSymbol, $exchange);
    } catch (InvalidArgumentException $e) {
      return StockValidationResult::invalid([$e->getMessage()]);
    }

    $local = $this->equityUniverse->resolveCanonicalStock($normalized['symbol'], $normalized['exchange']);
    if ($local) {
        return StockValidationResult::valid($local, 'local', ['cached' => true]);
    }

    if (! $allowProvider) {
        return StockValidationResult::invalid([
            "Stock {$normalized['symbol']} ({$normalized['exchange']}) is not in the local master list.",
        ]);
    }

    $providerResult = $this->validateViaProviders(
        $normalized['symbol'],
        $normalized['exchange'],
    );

    if ($providerResult->valid) {
      return $providerResult;
    }

    $this->portfolioLogger->validation('warning', 'Stock validation failed for all providers', [
      'symbol' => $normalized['symbol'],
      'exchange' => $normalized['exchange'],
      'errors' => $providerResult->errors,
    ]);

    return $providerResult;
  }

  public function validateAndPersist(
    string $inputSymbol,
    ?string $exchange = 'NSE',
    ?string $name = null,
    ?string $isin = null,
    ?string $sector = null,
  ): StockValidationResult {
    $result = $this->validate($inputSymbol, $exchange, true);

    if (! $result->valid) {
      return $result;
    }

    if ($result->stock) {
      $stock = $result->stock;
      $dirty = false;

      if ($name && $stock->name !== $name) {
        $stock->name = $name;
        $dirty = true;
      }
      if ($isin && ! $stock->isin) {
        $stock->isin = $isin;
        $dirty = true;
      }
      if ($sector && ! $stock->sector) {
        $stock->sector = $sector;
        $dirty = true;
      }

      if ($dirty) {
        $stock->save();
      }

      if ($result->source !== 'local' || ! $this->isRecentlyVerified($stock)) {
        $this->markVerified($stock);
        $this->triggerHistoricalBackfill($stock);
      }

      return StockValidationResult::valid($stock->fresh(), $result->source, $result->meta);
    }

    return $result;
  }

  public function validateViaNSE(string $symbol, string $exchange = 'NSE'): StockValidationResult
  {
    if ($exchange !== 'NSE') {
      return StockValidationResult::invalid(['NSE validation only applies to NSE listings.']);
    }

    try {
      $response = NseHttpClient::create()->get('https://www.nseindia.com/api/quote-equity', [
        'symbol' => strtoupper($symbol),
      ]);

      if (! $response->successful()) {
        $status = $response->status();
        if ($status === 403) {
          throw new \RuntimeException('HTTP 403 (NSE blocked automated access — session or anti-bot)');
        }
        throw new \RuntimeException('HTTP '.$status);
      }

      $info = $response->json('info') ?? [];
      $companyName = $info['companyName'] ?? $info['symbol'] ?? null;

      if (! $companyName) {
        return StockValidationResult::invalid(['NSE returned no company name.']);
      }

      return StockValidationResult::valid(
        $this->upsertFromProvider($symbol, $exchange, (string) $companyName, $info['isin'] ?? null, null, 'nse'),
        'nse',
        ['company_name' => $companyName],
      );
    } catch (\Throwable $e) {
      $this->portfolioLogger->provider('warning', 'NSE stock validation failed', [
        'provider' => 'nse',
        'symbol' => $symbol,
        'exchange' => $exchange,
        'failure_reason' => $e->getMessage(),
      ]);

      return StockValidationResult::invalid(['NSE: '.$e->getMessage()]);
    }
  }

  public function validateViaYahoo(string $symbol, string $exchange = 'NSE'): StockValidationResult
  {
    $yahooSymbol = $this->resolver->yahooSymbol($symbol, $exchange);

    try {
      $response = ExternalHttp::client()->timeout(15)->get(
        'https://query1.finance.yahoo.com/v8/finance/chart/'.$yahooSymbol,
        ['interval' => '1d', 'range' => '5d'],
      );

      if (! $response->successful()) {
        throw new \RuntimeException('HTTP '.$response->status());
      }

      $meta = $response->json('chart.result.0.meta') ?? [];
      $shortName = $meta['shortName'] ?? $meta['longName'] ?? null;
      $price = $meta['regularMarketPrice'] ?? null;

      if (! $shortName && $price === null) {
        return StockValidationResult::invalid(['Yahoo returned no quote metadata.']);
      }

      return StockValidationResult::valid(
        $this->upsertFromProvider($symbol, $exchange, (string) ($shortName ?: $symbol), null, null, 'yahoo'),
        'yahoo',
        ['yahoo_symbol' => $yahooSymbol, 'regular_market_price' => $price],
      );
    } catch (\Throwable $e) {
      $this->portfolioLogger->provider('warning', 'Yahoo stock validation failed', [
        'provider' => 'yahoo',
        'symbol' => $symbol,
        'exchange' => $exchange,
        'yahoo_symbol' => $yahooSymbol,
        'failure_reason' => $e->getMessage(),
      ]);

      return StockValidationResult::invalid(['Yahoo: '.$e->getMessage()]);
    }
  }

  public function validateViaAlphaVantage(string $symbol, string $exchange = 'NSE'): StockValidationResult
  {
    $apiKey = $this->settings->get('alpha_vantage_api_key');
    if (! $apiKey) {
      return StockValidationResult::invalid(['Alpha Vantage API key not configured.']);
    }

    $querySymbol = $this->resolver->alphaVantageSymbol($symbol, $exchange);

    try {
      $response = ExternalHttp::client()->timeout(20)->get('https://www.alphavantage.co/query', [
        'function' => 'GLOBAL_QUOTE',
        'symbol' => $querySymbol,
        'apikey' => $apiKey,
      ]);

      if (! $response->successful()) {
        throw new \RuntimeException('HTTP '.$response->status());
      }

      $body = $response->json();
      if (isset($body['Note']) || isset($body['Information'])) {
        throw new \RuntimeException($body['Note'] ?? $body['Information']);
      }

      $quote = $body['Global Quote'] ?? [];
      $price = $quote['05. price'] ?? null;

      if ($price === null || $price === '' || $price === '0.0000') {
        return StockValidationResult::invalid(['Alpha Vantage returned empty quote.']);
      }

      return StockValidationResult::valid(
        $this->upsertFromProvider($symbol, $exchange, $symbol, null, null, 'alpha_vantage'),
        'alpha_vantage',
        ['alpha_vantage_symbol' => $querySymbol, 'price' => $price],
      );
    } catch (\Throwable $e) {
      $this->portfolioLogger->provider('warning', 'Alpha Vantage stock validation failed', [
        'provider' => 'alpha_vantage',
        'symbol' => $symbol,
        'exchange' => $exchange,
        'failure_reason' => $e->getMessage(),
      ]);

      return StockValidationResult::invalid(['Alpha Vantage: '.$e->getMessage()]);
    }
  }

  protected function validateViaProviders(string $symbol, string $exchange): StockValidationResult
  {
    $retryCount = max(1, (int) $this->settings->get('nse_retry_count', '3'));
    $errors = [];

    for ($attempt = 1; $attempt <= $retryCount; $attempt++) {
      $nse = $this->validateViaNSE($symbol, $exchange);
      if ($nse->valid) {
        $this->portfolioLogger->provider('info', 'Stock validated via NSE', [
          'symbol' => $symbol,
          'exchange' => $exchange,
          'attempt' => $attempt,
        ]);

        return $nse;
      }
      $errors = array_merge($errors, $nse->errors);
      usleep(100000 * $attempt);
    }

    $yahoo = $this->validateViaYahoo($symbol, $exchange);
    if ($yahoo->valid) {
      $this->portfolioLogger->provider('info', 'Yahoo fallback validated stock', [
        'symbol' => $symbol,
        'exchange' => $exchange,
      ]);

      return $yahoo;
    }
    $errors = array_merge($errors, $yahoo->errors);

    $alpha = $this->validateViaAlphaVantage($symbol, $exchange);
    if ($alpha->valid) {
      $this->portfolioLogger->provider('info', 'Alpha Vantage fallback validated stock', [
        'symbol' => $symbol,
        'exchange' => $exchange,
      ]);

      return $alpha;
    }
    $errors = array_merge($errors, $alpha->errors);

    return StockValidationResult::invalid(array_values(array_unique($errors)));
  }

  protected function isRecentlyVerified(Stock $stock): bool
  {
    if (! $stock->last_verified_at) {
      return false;
    }

    $days = (int) config('portfolio.stock_master.revalidation_days', 7);

    return $stock->last_verified_at->greaterThanOrEqualTo(now()->subDays($days));
  }

  protected function markVerified(Stock $stock): void
  {
    $stock->last_verified_at = now();
    $stock->is_active = true;
    $this->resolver->applyProviderSymbols($stock);
    $stock->save();
  }

  protected function upsertFromProvider(
    string $symbol,
    string $exchange,
    string $name,
    ?string $isin,
    ?string $sector,
    string $source,
  ): Stock {
    $stock = Stock::query()->firstOrNew([
      'symbol' => $symbol,
      'exchange' => $exchange,
      'is_benchmark' => false,
    ]);

    $stock->name = $name ?: $symbol;
    $stock->isin = $isin ?: $stock->isin;
    $stock->sector = $sector ?: $stock->sector;
    $stock->is_active = true;
    $stock->last_verified_at = now();
    $this->resolver->applyProviderSymbols($stock);
    $stock->save();

    $this->portfolioLogger->validation('info', 'Stock master upserted from provider validation', [
      'symbol' => $symbol,
      'exchange' => $exchange,
      'source' => $source,
      'stock_id' => $stock->id,
    ]);

    return $stock;
  }

  protected function triggerHistoricalBackfill(Stock $stock): void
  {
    try {
      BackfillHistoricalDataJob::dispatchSync($stock->id);
    } catch (\Throwable $e) {
      $this->portfolioLogger->api('warning', 'Historical backfill after validation failed', [
        'stock_id' => $stock->id,
        'symbol' => $stock->symbol,
        'failure_reason' => $e->getMessage(),
      ]);
    }
  }
}
