import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src');
const flow = readFileSync(join(root, 'utils/strategyScreenerReturnFlow.js'), 'utf8');
const strategyPage = readFileSync(join(root, 'pages/StrategyPage.jsx'), 'utf8');
const screenerPage = readFileSync(join(root, 'pages/ScreenerEditorPage.jsx'), 'utf8');

test('configWithEligibilityScreener appends unique screener row', async () => {
    const { configWithEligibilityScreener } = await import('../../resources/js/src/utils/strategyScreenerReturnFlow.js');
    const base = { eligibility_sources: [{ screener_id: 1, screener_name: 'A' }] };
    const next = configWithEligibilityScreener(base, 2, { name: 'B' });
    assert.equal(next.eligibility_sources.length, 2);
    assert.equal(next.eligibility_sources[1].screener_id, 2);
    assert.equal(configWithEligibilityScreener(next, 2, {}).eligibility_sources.length, 2);
});

test('WP-09 Strategy page exposes contextual create screener flow', () => {
    assert.match(strategyPage, /id="strategy-create-screener"/);
    assert.match(strategyPage, /writeStrategyScreenerReturnDraft/);
    assert.match(strategyPage, /STRATEGY_SCREENER_CREATED_PARAM/);
});

test('WP-09 Screener editor supports return-to-strategy save and cancel', () => {
    assert.match(screenerPage, /strategyReturnActive/);
    assert.match(screenerPage, /screener-cancel-return-strategy/);
    assert.match(screenerPage, /STRATEGY_SCREENER_CREATED_PARAM/);
});

test('return flow module defines storage key', () => {
    assert.match(flow, /lido:strategy:screener-create-return/);
});
