import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const src = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src');
const app = readFileSync(join(src, 'App.jsx'), 'utf8');
const nav = readFileSync(join(src, 'config/navigation.js'), 'utf8');
const page = readFileSync(join(src, 'pages/StocksAdminPage.jsx'), 'utf8');

test('Stocks admin route is wrapped in AdminRoute', () => {
    assert.match(app, /path="\/settings\/stocks"/);
    assert.match(app, /<AdminRoute>\s*<StocksAdminPage/);
});

test('User management route remains routable and admin-protected', () => {
    assert.match(app, /path="\/settings\/users"/);
    assert.match(app, /<AdminRoute>\s*<UserManagementPage/);
});

test('navigation exposes admin-only Stocks catalogue entry', () => {
    assert.match(nav, /id: 'stocks-admin'/);
    assert.match(nav, /permission: 'admin'/);
    assert.match(nav, /SETTINGS_STOCKS/);
});

test('Stocks admin page has no manual add or delete controls', () => {
    assert.doesNotMatch(page, /Add stock/i);
    assert.doesNotMatch(page, /Delete/i);
    assert.doesNotMatch(page, /Create stock/i);
    assert.match(page, /admin\/stocks/);
    assert.match(page, /\/stocks\/\$\{stock\.id\}\/deactivate/);
    assert.match(page, /\/stocks\/\$\{stock\.id\}\/activate/);
});
