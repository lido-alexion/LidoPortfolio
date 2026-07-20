/**
 * External research URL templates ({SYMBOL}, {EXCHANGE}, {YAHOO_SUFFIX}).
 */

export const DEFAULT_EXTERNAL_STOCK_LINKS = [
    {
        id: 'chartink',
        label: 'Chartink',
        url: 'https://chartink.com/stocks/{SYMBOL}.html',
        enabled: true,
    },
    {
        id: 'tradingview',
        label: 'TradingView',
        url: 'https://in.tradingview.com/symbols/{EXCHANGE}-{SYMBOL}/',
        enabled: true,
    },
    {
        id: 'yahoo',
        label: 'Yahoo Finance',
        url: 'https://finance.yahoo.com/quote/{SYMBOL}.{YAHOO_SUFFIX}/',
        enabled: true,
    },
    {
        id: 'zerodha',
        label: 'Zerodha',
        url: 'https://zerodha.com/markets/stocks/{EXCHANGE}/{SYMBOL}/',
        enabled: true,
    },
    {
        id: 'screener',
        label: 'Screener.in',
        url: 'https://www.screener.in/company/{SYMBOL}/consolidated/',
        enabled: true,
    },
    {
        id: 'stockscans',
        label: 'StockScans',
        url: 'https://www.stockscans.in/company/{EXCHANGE}:{SYMBOL}',
        enabled: true,
    },
];

export function normalizeExternalStockLinks(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
        return DEFAULT_EXTERNAL_STOCK_LINKS.map((row) => ({ ...row }));
    }

    return rows.slice(0, 20).map((row, index) => {
        const id = String(row?.id || '').trim() || `link_${index}`;
        const label = String(row?.label || '').trim() || id;
        const url = String(row?.url || '').trim();
        const enabled = row?.enabled !== false && row?.enabled !== 'false' && row?.enabled !== 0;

        return {
            id: id.slice(0, 64),
            label: label.slice(0, 80),
            url: url.slice(0, 500),
            enabled: Boolean(enabled),
        };
    });
}

export function resolveExternalStockUrl(template, symbol, exchange = 'NSE') {
    const sym = String(symbol || '').trim().toUpperCase();
    const exch = String(exchange || 'NSE').trim().toUpperCase() === 'BSE' ? 'BSE' : 'NSE';
    const yahooSuffix = exch === 'BSE' ? 'BO' : 'NS';

    return String(template || '')
        .replaceAll('{SYMBOL}', sym)
        .replaceAll('{EXCHANGE}', exch)
        .replaceAll('{YAHOO_SUFFIX}', yahooSuffix);
}

export function enabledExternalStockLinks(rows) {
    return normalizeExternalStockLinks(rows).filter((row) => row.enabled && row.url);
}
