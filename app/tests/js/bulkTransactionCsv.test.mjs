import test from 'node:test';
import assert from 'node:assert/strict';
import {
    normalizeBulkTransactionType,
    parseBulkTransactionCsv,
} from '../../resources/js/src/utils/bulkTransactionCsv.js';

test('parseBulkTransactionCsv parses sample csv with header', () => {
    const csv = `Stock,Quantity,Average Price,Transaction Type
WELCORP,32,1520.00,BUY
MBAPL,88,565.27,BUY`;

    const { rows, errors } = parseBulkTransactionCsv(csv);

    assert.deepEqual(errors, []);
    assert.equal(rows.length, 2);
    assert.deepEqual(rows[0], {
        id: rows[0].id,
        symbol: 'WELCORP',
        quantity: 32,
        price: 1520,
        type: 'buy',
    });
    assert.equal(rows[1].symbol, 'MBAPL');
});

test('parseBulkTransactionCsv parses rows without header', () => {
    const { rows, errors } = parseBulkTransactionCsv('INFY,10,1500.50,SELL');

    assert.deepEqual(errors, []);
    assert.deepEqual(rows[0], {
        id: rows[0].id,
        symbol: 'INFY',
        quantity: 10,
        price: 1500.5,
        type: 'sell',
    });
});

test('parseBulkTransactionCsv reports invalid rows', () => {
    const { rows, errors } = parseBulkTransactionCsv('BAD,0,10,HOLD');

    assert.equal(rows.length, 0);
    assert.ok(errors.length > 0);
});

test('normalizeBulkTransactionType normalizes buy and sell', () => {
    assert.equal(normalizeBulkTransactionType('BUY'), 'buy');
    assert.equal(normalizeBulkTransactionType('sell'), 'sell');
    assert.equal(normalizeBulkTransactionType('x'), null);
});

test('parseBulkTransactionCsv parses optional owner column', () => {
    const { rows, errors } = parseBulkTransactionCsv('INFY,10,1500.50,SELL,unmanaged');

    assert.deepEqual(errors, []);
    assert.equal(rows[0].owner_key, 'unmanaged');
});

test('parseBulkTransactionCsv maps numeric owner to strategy key', () => {
    const { rows, errors } = parseBulkTransactionCsv('INFY,10,1500.50,SELL,12');

    assert.deepEqual(errors, []);
    assert.equal(rows[0].owner_key, 'strategy:12');
    assert.equal(rows[0].strategy_id, 12);
});
