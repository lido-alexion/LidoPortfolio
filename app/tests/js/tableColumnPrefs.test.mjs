import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildDefaultColumnOrder,
    distributeColumnWidths,
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

test('distributeColumnWidths scales visible columns to target width', () => {
    const columns = [
        { id: 'symbol', getSize: () => 200, columnDef: {} },
        { id: 'qty', getSize: () => 100, columnDef: {} },
        { id: 'price', getSize: () => 100, columnDef: {} },
    ];

    const sizing = distributeColumnWidths(columns, 400);
    const total = Object.values(sizing).reduce((sum, width) => sum + width, 0);

    assert.equal(total, 400);
    assert.equal(sizing.symbol, 200);
    assert.equal(sizing.qty, 100);
    assert.equal(sizing.price, 100);
});

test('distributeColumnWidths respects column min and max sizes', () => {
    const columns = [
        { id: 'narrow', getSize: () => 40, columnDef: { minSize: 56, maxSize: 120 } },
        { id: 'wide', getSize: () => 300, columnDef: { minSize: 80, maxSize: 720 } },
    ];

    const sizing = distributeColumnWidths(columns, 500);

    assert.ok(sizing.narrow >= 56);
    assert.ok(sizing.wide <= 720);
    assert.equal(sizing.narrow + sizing.wide, 500);
});
