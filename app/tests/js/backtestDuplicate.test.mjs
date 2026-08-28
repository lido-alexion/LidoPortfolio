import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const src = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src');
const page = readFileSync(join(src, 'pages/BacktestHistoryPage.jsx'), 'utf8');
const helpers = readFileSync(join(src, 'utils/backtestHelpers.js'), 'utf8');
const docs = readFileSync(join(src, 'data/appDocumentation.js'), 'utf8');

test('Backtest history Duplicate is enabled and reuses create payload helper', () => {
    assert.match(page, /duplicateBacktestPayload/);
    assert.match(page, /onDuplicate/);
    assert.match(page, />\s*Duplicate\s*</);
    assert.doesNotMatch(page, /title="Coming soon"/);
    assert.doesNotMatch(page, /disabled\s*\n\s*title="Coming soon"/);
});

test('duplicate helper omits strategy_version_id and result state', () => {
    assert.match(helpers, /export function duplicateBacktestPayload/);
    assert.match(helpers, /Omits strategy_version_id/);
    assert.match(helpers, /from_date/);
    assert.match(helpers, /to_date/);
    assert.match(helpers, /initial_capital/);
    assert.doesNotMatch(helpers, /payload\.strategy_version_id/);
});

test('help documents Duplicate as a new simulation against the current Strategy', () => {
    const start = docs.indexOf("id: 'backtests'");
    assert.notEqual(start, -1);
    const topic = docs.slice(start, start + 8000);
    assert.match(topic, /name: 'Duplicate'/);
    assert.match(topic, /current Strategy/);
    assert.doesNotMatch(topic, /Duplicate is reserved for a future release/);
});
