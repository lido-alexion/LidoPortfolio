import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const app = fs.readFileSync(new URL('../../resources/js/src/App.jsx', import.meta.url), 'utf8');
const nav = fs.readFileSync(new URL('../../resources/js/src/config/navigation.js', import.meta.url), 'utf8');
const page = fs.readFileSync(new URL('../../resources/js/src/pages/PerformanceTaxPage.jsx', import.meta.url), 'utf8');

test('V5 performance and tax workspace is routed and discoverable', () => {
    assert.match(app, /path="\/portfolio\/performance-tax"/);
    assert.match(nav, /title: 'Performance & Tax'/);
    assert.match(page, /\/analysis\/performance/);
    assert.match(page, /\/analysis\/attribution/);
    assert.match(page, /\/tax\/report/);
});

test('workspace distinguishes what-if, evidence, completeness, and tax advice', () => {
    assert.match(page, /What-if portfolio selection/);
    assert.match(page, /does not change configured inclusion/);
    assert.match(page, /Preserve evidence/);
    assert.match(page, /Completeness:/);
    assert.match(page, /not certified tax advice/);
});
