import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const source = async (path) => readFile(new URL(path, import.meta.url), 'utf8');

test('portfolio reconciliation fails closed and rechecks broker usability before posting', async () => {
    const card = await source('../../resources/js/src/components/PortfolioReconciliationCard.jsx');
    assert.match(card, /disabled=\{busy \|\| !brokerUsable\}/);
    assert.match(card, /latestBrokerStatus\?\.usable !== true/);
    assert.ok(card.indexOf('latestBrokerStatus?.usable !== true') < card.indexOf("api.post('/reconciliation'"));
    assert.match(card, /params: \{ return_to: 'dashboard' \}/);
});

test('Kite disconnect uses only the normal broker disconnect endpoint', async () => {
    const header = await source('../../resources/js/src/components/KiteHeaderControl.jsx');
    assert.match(header, /window\.confirm\(/);
    assert.match(header, /api\.post\('\/v1\/broker\/kite\/disconnect'/);
    assert.doesNotMatch(header, /emergency-disconnect|\/v1\/execution\/recover/);
});

test('shared broker status refreshes on visibility, portfolio changes, route return, and a minute timer', async () => {
    const context = await source('../../resources/js/src/context/KiteConnectionContext.jsx');
    assert.match(context, /setInterval\(refresh, 60_000\)/);
    assert.match(context, /visibilitychange/);
    assert.match(context, /PORTFOLIO_CHANGED/);
    assert.match(context, /pathname === '\/' \|\| pathname\.startsWith\('\/settings\/account'\)/);
});
