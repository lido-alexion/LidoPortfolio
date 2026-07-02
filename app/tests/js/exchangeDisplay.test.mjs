import test from 'node:test';
import assert from 'node:assert/strict';
import { stockExchangeLabel } from '../../resources/js/src/utils/exchangeDisplay.js';

test('stockExchangeLabel shows NSE+ for dual-listed NSE rows', () => {
    assert.equal(stockExchangeLabel({ exchange: 'NSE', is_dual_listed: true }), 'NSE+');
    assert.equal(stockExchangeLabel({ exchange: 'NSE', exchange_label: 'NSE+' }), 'NSE+');
    assert.equal(stockExchangeLabel({ exchange: 'BSE' }), 'BSE');
});
