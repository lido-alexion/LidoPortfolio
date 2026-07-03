import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildDefaultColumnOrder,
    loadTableColumnPrefs,
    saveTableColumnPrefs,
} from '../../resources/js/src/utils/tableColumnPrefs.js';

test('buildDefaultColumnOrder uses id or accessorKey', () => {
    const order = buildDefaultColumnOrder([
        { accessorKey: 'symbol', header: 'Symbol' },
        { id: 'actions', header: 'Actions' },
        { header: 'No Key' },
    ]);
    assert.deepEqual(order, ['symbol', 'actions', 'col_2']);
});

test('save and load column preferences', () => {
    const store = {};
    global.localStorage = {
        setItem(k, v) { store[k] = v; },
        getItem(k) { return store[k] ?? null; },
        removeItem(k) { delete store[k]; },
    };

    saveTableColumnPrefs('holdings', ['symbol', 'qty'], { qty: false }, { symbol: 180, qty: 96 });
    const loaded = loadTableColumnPrefs('holdings', ['symbol', 'qty', 'xirr']);
    assert.deepEqual(loaded.columnOrder, ['symbol', 'qty']);
    assert.deepEqual(loaded.columnVisibility, { qty: false });
    assert.deepEqual(loaded.columnSizing, { symbol: 180, qty: 96 });
});
