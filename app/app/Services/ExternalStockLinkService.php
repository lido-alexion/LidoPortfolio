<?php

namespace App\Services;

/**
 * Configurable external research URLs for a stock symbol (Chartink, TradingView, etc.).
 * Templates support {SYMBOL}, {EXCHANGE}, {YAHOO_SUFFIX}.
 */
class ExternalStockLinkService
{
    public const SETTING_KEY = 'external_stock_links';

    public const MAX_LINKS = 20;

    /**
     * @return list<array{id:string,label:string,url:string,enabled:bool}>
     */
    public function defaults(): array
    {
        return [
            [
                'id' => 'chartink',
                'label' => 'Chartink',
                'url' => 'https://chartink.com/stocks/{SYMBOL}.html',
                'enabled' => true,
            ],
            [
                'id' => 'tradingview',
                'label' => 'TradingView',
                'url' => 'https://in.tradingview.com/symbols/{EXCHANGE}-{SYMBOL}/',
                'enabled' => true,
            ],
            [
                'id' => 'yahoo',
                'label' => 'Yahoo Finance',
                'url' => 'https://finance.yahoo.com/quote/{SYMBOL}.{YAHOO_SUFFIX}/',
                'enabled' => true,
            ],
            [
                'id' => 'zerodha',
                'label' => 'Zerodha',
                'url' => 'https://zerodha.com/markets/stocks/{EXCHANGE}/{SYMBOL}/',
                'enabled' => true,
            ],
            [
                'id' => 'screener',
                'label' => 'Screener.in',
                'url' => 'https://www.screener.in/company/{SYMBOL}/consolidated/',
                'enabled' => true,
            ],
            [
                'id' => 'stockscans',
                'label' => 'StockScans',
                'url' => 'https://www.stockscans.in/company/{EXCHANGE}:{SYMBOL}',
                'enabled' => true,
            ],
        ];
    }

    /**
     * @return list<array{id:string,label:string,url:string,enabled:bool}>
     */
    public function all(): array
    {
        $raw = \App\Models\Setting::getValue(self::SETTING_KEY, '');
        if ($raw === null || $raw === '') {
            return $this->defaults();
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->defaults();
        }

        $normalized = $this->normalize($decoded);

        return $normalized !== [] ? $normalized : $this->defaults();
    }

    /**
     * Enabled links with a non-empty URL template (for Screener / API consumers).
     *
     * @return list<array{id:string,label:string,url:string}>
     */
    public function enabledTemplates(): array
    {
        $out = [];
        foreach ($this->all() as $row) {
            if (! ($row['enabled'] ?? false)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $out[] = [
                'id' => $row['id'],
                'label' => $row['label'],
                'url' => $url,
            ];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array{id:string,label:string,url:string,enabled:bool}>
     */
    public function normalize(array $rows): array
    {
        $out = [];
        foreach (array_slice(array_values($rows), 0, self::MAX_LINKS) as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                $id = 'link_'.$i.'_'.substr(sha1((string) ($row['url'] ?? $i)), 0, 8);
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = $id;
            }
            $url = trim((string) ($row['url'] ?? ''));
            $enabled = filter_var($row['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);

            $out[] = [
                'id' => mb_substr($id, 0, 64),
                'label' => mb_substr($label, 0, 80),
                'url' => mb_substr($url, 0, 500),
                'enabled' => $enabled,
            ];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $rows
     */
    public function persist(array $rows): array
    {
        $normalized = $this->normalize($rows);
        \App\Models\Setting::setValue(self::SETTING_KEY, json_encode($normalized));

        return $normalized;
    }

    public function resolve(string $template, string $symbol, ?string $exchange = 'NSE'): string
    {
        $sym = strtoupper(trim($symbol));
        $exch = strtoupper(trim((string) $exchange));
        if ($exch !== 'BSE') {
            $exch = 'NSE';
        }
        $yahooSuffix = $exch === 'BSE' ? 'BO' : 'NS';

        return str_replace(
            ['{SYMBOL}', '{EXCHANGE}', '{YAHOO_SUFFIX}'],
            [$sym, $exch, $yahooSuffix],
            $template,
        );
    }
}
