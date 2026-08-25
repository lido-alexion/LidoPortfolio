import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const src = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src');

test('Settings UI exposes independent Portfolio Stop-Loss and Trailing Stop labels', () => {
    const page = readFileSync(join(src, 'pages/SettingsPage.jsx'), 'utf8');
    assert.match(page, /Portfolio Stop-Loss %/);
    assert.match(page, /Portfolio Trailing Stop %/);
    assert.match(page, /portfolio_trailing_percent/);
    assert.match(page, /default_stoploss_percent/);
    assert.match(page, /Seeded to 15%/);
    assert.match(page, /Independent of trailing %/);
    assert.doesNotMatch(page, /trailing.*from.*stoploss/i);
});

test('Holdings UI uses portfolio_trailing_percent not stoploss_percent for trailing subtitle', () => {
    const page = readFileSync(join(src, 'pages/HoldingsPage.jsx'), 'utf8');
    assert.match(page, /portfolio_trailing_percent/);
    assert.match(page, /% from peak close/);
    assert.match(page, /stop_loss_price/);
    assert.match(page, /weighted_average_fill_cost/);
    assert.doesNotMatch(page, /stoploss_percent\}% stop/);
});

test('Recommendations UI displays primary exit attribution via labels helper', () => {
    const page = readFileSync(join(src, 'pages/RecommendationsPage.jsx'), 'utf8');
    assert.match(page, /exitAttributionLabel/);
    assert.match(page, /Primary exit reason/);
    assert.match(page, /primary_exit_reason/);
});

test('Transactions table exposes persisted exit_reason with human label', () => {
    const cols = readFileSync(join(src, 'utils/transactionTableColumns.jsx'), 'utf8');
    assert.match(cols, /exit_reason/);
    assert.match(cols, /exitAttributionLabel/);
    assert.match(cols, /Exit reason/);
});
