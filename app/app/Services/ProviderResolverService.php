<?php

namespace App\Services;

use App\Models\Stock;
use InvalidArgumentException;

class ProviderResolverService
{
  public const EXCHANGES = ['NSE', 'BSE'];

  /**
   * @return array{symbol: string, exchange: string}
   */
  public function normalizeSymbol(string $input, ?string $exchange = 'NSE'): array
  {
    $raw = strtoupper(trim($input));
    $raw = preg_replace('/\s+/', '', $raw) ?? $raw;

    if ($raw === '') {
      throw new InvalidArgumentException('Symbol cannot be empty.');
    }

    $resolvedExchange = strtoupper((string) ($exchange ?: 'NSE'));

    if (preg_match('/^([A-Z0-9\-&]+)\.(NS|BO)$/', $raw, $matches)) {
      return [
        'symbol' => $matches[1],
        'exchange' => $matches[2] === 'BO' ? 'BSE' : 'NSE',
      ];
    }

    if (! in_array($resolvedExchange, self::EXCHANGES, true)) {
      $resolvedExchange = 'NSE';
    }

    return [
      'symbol' => $raw,
      'exchange' => $resolvedExchange,
    ];
  }

  public function isMalformed(string $input): bool
  {
    try {
      $normalized = $this->normalizeSymbol($input);
    } catch (InvalidArgumentException) {
      return true;
    }

    $symbol = $normalized['symbol'];

    if (strlen($symbol) < 1 || strlen($symbol) > 20) {
      return true;
    }

    return ! preg_match('/^[A-Z0-9][A-Z0-9\-&]*$/', $symbol);
  }

  public function yahooSymbol(string $symbol, string $exchange = 'NSE'): string
  {
    $normalized = $this->normalizeSymbol($symbol, $exchange);
    $indexDef = app(IndexCatalogService::class)->definitionForSymbol($normalized['symbol']);
    if ($indexDef !== null && ($indexDef['yahoo_symbol'] ?? '') !== '') {
      return (string) $indexDef['yahoo_symbol'];
    }

    if ($normalized['exchange'] === 'BSE') {
      return $normalized['symbol'].'.BO';
    }

    return $normalized['symbol'].'.NS';
  }

  public function alphaVantageSymbol(string $symbol, string $exchange = 'NSE'): string
  {
    $normalized = $this->normalizeSymbol($symbol, $exchange);
    $indexDef = app(IndexCatalogService::class)->definitionForSymbol($normalized['symbol']);
    if ($indexDef !== null) {
      if (($indexDef['alpha_vantage_symbol'] ?? null) !== null && $indexDef['alpha_vantage_symbol'] !== '') {
        return (string) $indexDef['alpha_vantage_symbol'];
      }

      return $this->yahooSymbol($normalized['symbol'], $normalized['exchange']);
    }

    return $this->yahooSymbol($normalized['symbol'], $normalized['exchange']);
  }

  public function applyProviderSymbols(Stock $stock): Stock
  {
    $indexDef = app(IndexCatalogService::class)->definitionForSymbol((string) $stock->symbol);
    if ($indexDef !== null) {
      if (($indexDef['yahoo_symbol'] ?? '') !== '') {
        $stock->yahoo_symbol = (string) $indexDef['yahoo_symbol'];
      }
      if (($indexDef['alpha_vantage_symbol'] ?? null) !== null && $indexDef['alpha_vantage_symbol'] !== '') {
        $stock->alpha_vantage_symbol = (string) $indexDef['alpha_vantage_symbol'];
      } elseif (! $stock->alpha_vantage_symbol) {
        $stock->alpha_vantage_symbol = $stock->yahoo_symbol;
      }

      return $stock;
    }

    $stock->yahoo_symbol = $stock->yahoo_symbol ?: $this->yahooSymbol($stock->symbol, $stock->exchange);
    $stock->alpha_vantage_symbol = $stock->alpha_vantage_symbol ?: $this->alphaVantageSymbol($stock->symbol, $stock->exchange);

    return $stock;
  }

  /**
   * NSE charting / NextApi trade symbol (base symbol + series suffix when not EQ).
   */
  public function nseTradeSymbol(Stock $stock): string
  {
    $base = strtoupper(trim((string) $stock->symbol));
    if ($base === '' || strtoupper((string) $stock->exchange) !== 'NSE') {
      return $base;
    }

    if (app(IndexCatalogService::class)->isConfiguredIndex($base)) {
      return $base;
    }

    $series = strtoupper(trim((string) ($stock->series ?? 'EQ')));
    if ($series === '' || $series === 'EQ') {
      return $base;
    }

    return $base.'-'.$series;
  }

  /**
   * @return array{0: string, 1: string} [baseSymbol, series]
   */
  public function parseNseTradeSymbol(string $tradeSymbol): array
  {
    $upper = strtoupper(trim($tradeSymbol));
    if (preg_match('/^([A-Z0-9][A-Z0-9\-&]*)-(EQ|BE|BZ|IV|E1|E2|SM|ST|GS|GB|MF)$/', $upper, $matches) === 1) {
      return [$matches[1], $matches[2]];
    }

    return [$upper, 'EQ'];
  }

  /**
   * @return array<string, string>
   */
  public function providerSymbolsForStock(Stock $stock): array
  {
    $stock = $this->applyProviderSymbols($stock);

    return [
      'nse' => $this->nseTradeSymbol($stock),
      'bse_bhavcopy' => trim((string) ($stock->bse_scrip_code ?? '')),
      'yahoo' => $stock->yahoo_symbol,
      'alpha_vantage' => $stock->alpha_vantage_symbol,
    ];
  }

  /**
   * Yahoo tickers to try for Indian equities (primary exchange first, then alternate listing).
   *
   * @return array<int, string>
   */
  public function yahooSymbolCandidates(Stock $stock): array
  {
    $stock = $this->applyProviderSymbols($stock);

    if (app(IndexCatalogService::class)->isConfiguredIndex((string) $stock->symbol)) {
      $yahoo = $stock->yahoo_symbol ?: $this->yahooSymbol($stock->symbol, $stock->exchange);

      return array_values(array_unique(array_filter([$yahoo])));
    }

    $base = strtoupper((string) $stock->symbol);
    $ns = $base.'.NS';
    $bo = $base.'.BO';
    $exchange = strtoupper((string) $stock->exchange);

    if ($exchange === 'BSE') {
      $ordered = [$stock->yahoo_symbol ?: $bo, $bo];
    } else {
      $ordered = [$stock->yahoo_symbol ?: $ns, $ns, $bo];
    }

    return array_values(array_unique(array_filter($ordered)));
  }
}
