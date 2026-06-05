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

    if ($normalized['symbol'] === 'NIFTY50') {
      return '^NSEI';
    }

    if ($normalized['exchange'] === 'BSE') {
      return $normalized['symbol'].'.BO';
    }

    return $normalized['symbol'].'.NS';
  }

  public function alphaVantageSymbol(string $symbol, string $exchange = 'NSE'): string
  {
    $normalized = $this->normalizeSymbol($symbol, $exchange);

    if ($normalized['symbol'] === 'NIFTY50') {
      return 'NSEI';
    }

    return $this->yahooSymbol($normalized['symbol'], $normalized['exchange']);
  }

  public function applyProviderSymbols(Stock $stock): Stock
  {
    if ($stock->symbol === 'NIFTY50' && $stock->exchange === 'NSE') {
      $stock->yahoo_symbol = '^NSEI';
      $stock->alpha_vantage_symbol = 'NSEI';

      return $stock;
    }

    $stock->yahoo_symbol = $stock->yahoo_symbol ?: $this->yahooSymbol($stock->symbol, $stock->exchange);
    $stock->alpha_vantage_symbol = $stock->alpha_vantage_symbol ?: $this->alphaVantageSymbol($stock->symbol, $stock->exchange);

    return $stock;
  }

  /**
   * @return array<string, string>
   */
  public function providerSymbolsForStock(Stock $stock): array
  {
    $stock = $this->applyProviderSymbols($stock);

    return [
      'nse' => $stock->symbol,
      'yahoo' => $stock->yahoo_symbol,
      'alpha_vantage' => $stock->alpha_vantage_symbol,
    ];
  }
}
