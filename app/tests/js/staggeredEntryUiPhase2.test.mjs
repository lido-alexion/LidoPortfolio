import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src');
const docs = readFileSync(join(root, 'data/appDocumentation.js'), 'utf8');

test('help documents BUY cooldown OD-11', () => {
    assert.match(docs, /BUY cooldown \(OD-11\)/);
    assert.match(docs, /1 calendar day/);
    assert.match(docs, /Does not suppress REDUCE\/EXIT\/HOLD/);
});

test('help documents staggered entry and position target OD-12', () => {
    assert.match(docs, /Staggered entry \/ position target \(OD-12\)/);
    assert.match(docs, /first_entry_pct/);
    assert.match(docs, /remaining = max\(0, target − filled\)/);
    assert.match(docs, /Position target \/ filled \(OD-12\)/);
});

test('help does not claim portfolio trailing is unrealized proxy', () => {
    assert.match(docs, /Not an unrealized-% proxy/);
});

test('Holdings UI exposes Target column for OD-12', () => {
    const page = readFileSync(join(root, 'pages/HoldingsPage.jsx'), 'utf8');
    assert.match(page, /id: 'target_amount'/);
    assert.match(page, /remaining_target_amount/);
    assert.match(page, /Filled/);
});

test('Holdings UI exposes Adopt for unmanaged holdings', () => {
    const page = readFileSync(join(root, 'pages/HoldingsPage.jsx'), 'utf8');
    assert.match(page, /label: 'Adopt'/);
    assert.match(page, /is_unmanaged/);
    assert.match(page, /\/holdings\/\$\{adoptHolding\.id\}\/adopt/);
    assert.match(docs, /Sell menu → Adopt/);
});

test('Recommendations UI exposes position target block', () => {
    const page = readFileSync(join(root, 'pages/RecommendationsPage.jsx'), 'utf8');
    assert.match(page, /Position target \(OD-12\)/);
    assert.match(page, /position_target_amount/);
    assert.match(page, /this_cycle_amount/);
});
