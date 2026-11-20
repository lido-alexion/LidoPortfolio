import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { compareReconciliation } from '../../scripts/zerodha-fee-comparator.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

test('synthetic Zerodha comparator reports daily component variances and source precision', async () => {
    const input = JSON.parse(await readFile(join(root, 'tests/fixtures/zerodha-fee-reconciliation-sample.json'), 'utf8'));
    const report = compareReconciliation(input);

    assert.deepEqual(report.notes[0].components.map(({ component, variance_paise }) => [component, variance_paise]), [
        ['stamp', 0],
        ['stt', 0],
        ['txn_nse', 0],
    ]);
    assert.equal(report.cash_events[0].variance_paise, -0.0078);
    assert.equal(report.cash_events[0].evidence, 'matched_source_event');
    assert.ok(report.notes[0].components.every(component => component.evidence === 'daily_aggregate_allocation'));
    assert.match(report.limitations[0], /do not uniquely prove individual fill allocations/);
});

test('a single comparable fill is identified separately from a daily allocation', async () => {
    const input = JSON.parse(await readFile(join(root, 'tests/fixtures/zerodha-fee-reconciliation-sample.json'), 'utf8'));
    input.fills = [input.fills[0]];
    input.invoice_days[0].components = { stt: 100, txn_nse: 3.54, stamp: 15 };
    const report = compareReconciliation(input);
    assert.ok(report.notes[0].components.every(component => component.evidence === 'single_fill_invoice'));
});
