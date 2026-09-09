import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const page = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src/pages/HistoricalHoldingsPage.jsx');
const source = readFileSync(page, 'utf8');

test('V5 historical holdings exposes cash, total value, and price evidence', () => {
    assert.match(source, /totals\.cash_balance/);
    assert.match(source, /totals\.total_value/);
    assert.match(source, /completeness\.total_value_complete/);
    assert.match(source, /row\.price_as_of/);
    assert.match(source, /row\.price_source/);
});
