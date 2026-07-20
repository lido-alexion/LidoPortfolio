import test from 'node:test';
import assert from 'node:assert/strict';
import {
    resolveExternalStockUrl,
    enabledExternalStockLinks,
    DEFAULT_EXTERNAL_STOCK_LINKS,
} from '../../resources/js/src/utils/externalStockLinks.js';

test('resolveExternalStockUrl fills SYMBOL EXCHANGE YAHOO_SUFFIX', () => {
    assert.equal(
        resolveExternalStockUrl('https://chartink.com/stocks/{SYMBOL}.html', 'syrma', 'NSE'),
        'https://chartink.com/stocks/SYRMA.html',
    );
    assert.equal(
        resolveExternalStockUrl('https://finance.yahoo.com/quote/{SYMBOL}.{YAHOO_SUFFIX}/', 'SYRMA', 'BSE'),
        'https://finance.yahoo.com/quote/SYRMA.BO/',
    );
});

test('enabledExternalStockLinks filters disabled and empty urls', () => {
    const rows = enabledExternalStockLinks([
        ...DEFAULT_EXTERNAL_STOCK_LINKS.slice(0, 1),
        { id: 'x', label: 'X', url: '', enabled: true },
        { id: 'y', label: 'Y', url: 'https://y/{SYMBOL}', enabled: false },
    ]);
    assert.equal(rows.length, 1);
    assert.equal(rows[0].id, 'chartink');
});
