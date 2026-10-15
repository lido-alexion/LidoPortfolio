import test from 'node:test';
import assert from 'node:assert/strict';
import { resolvePreferredStockListing } from '../../resources/js/src/utils/stockListing.js';

test('symbol routes prefer the NSE listing even when a BSE watchlist row comes first', () => {
    const bse = { id: 4573, symbol: 'TCS', exchange: 'BSE' };
    const nse = { id: 1886, symbol: ' tcs ', exchange: 'nse' };
    assert.equal(resolvePreferredStockListing([bse, nse], 'TCS'), nse);
});

test('symbol routes retain a matching non-NSE listing when no NSE listing exists', () => {
    const bse = { id: 4573, symbol: 'TCS', exchange: 'BSE' };
    assert.equal(resolvePreferredStockListing([bse], 'TCS'), bse);
});

test('symbol route resolution requires an exact symbol match', () => {
    assert.equal(resolvePreferredStockListing([{ id: 1, symbol: 'TCS-BE', exchange: 'NSE' }], 'TCS'), null);
    assert.equal(resolvePreferredStockListing([{ id: 1, symbol: 'TCS', exchange: 'NSE' }], ''), null);
});
