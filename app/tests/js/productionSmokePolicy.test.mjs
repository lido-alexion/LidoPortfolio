import test from 'node:test';
import assert from 'node:assert/strict';
import { allowProductionSmokeRequest as allowed, allowTransientWelcomeSkipRequest as allowWelcomeSkip, createTransientWelcomeSkipGate, neutralizeProductionSmokeRequest as neutralized, productionSmokeBlockReason, summarizeProductionSmokeBlocks, transientWelcomeSkipPayload } from '../e2e/productionSmokePolicy.js';
const base = 'https://stox.example.test';
test('production smoke permits only same-origin reads and narrow login/telemetry POSTs', () => {
    assert.equal(allowed('GET', `${base}/api/v1/recommendations`, base), true);
    assert.equal(allowed('HEAD', `${base}/build/app.js`, base), true);
    assert.equal(allowed('POST', `${base}/api/auth/login`, base, { login: true }), true);
    assert.equal(allowed('POST', `${base}/api/auth/login`, base), false);
    for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
        for (const path of ['/api/transactions', '/api/v1/orders/1/cancel', '/api/v1/execution/submit-selected', '/api/v1/admin/ml/acceptance/campaigns', '/api/settings']) {
            assert.equal(allowed(method, `${base}${path}`, base, { login: true }), false, `${method} ${path}`);
        }
    }
    assert.equal(allowed('POST', `${base}/api/logs/frontend`, base), true);
    assert.equal(allowed('POST', `${base}/api/logs/frontend/orders`, base), false);
    assert.equal(allowed('POST', `${base}/api/telemetry`, base), true);
    assert.equal(allowed('POST', `${base}/api/telemetry/route-view`, base), true);
    assert.equal(allowed('POST', `${base}/api/telemetry/otlp/v1/traces`, base), true);
    assert.equal(allowed('POST', `${base}/api/telemetry-unrelated`, base), false);
    assert.equal(allowed('GET', 'https://broker.example.test/orders', base), false);
    assert.equal(allowed('POST', 'https://broker.example.test/api/auth/login', base, { login: true }), false);
});


test('production smoke permits actual same-origin telemetry relays without admitting sibling writes', () => {
    for (const path of ['/api/telemetry/route-view', '/api/telemetry/otlp/v1/traces']) {
        assert.equal(allowed('POST', `${base}${path}`, base), true);
        for (const method of ['PUT', 'PATCH', 'DELETE']) assert.equal(allowed(method, `${base}${path}`, base), false);
        assert.equal(allowed('POST', `${base}${path}/orders`, base), false);
        assert.equal(allowed('POST', `https://broker.example.test${path}`, base), false);
    }
    assert.equal(allowed('POST', `${base}/api/telemetry/orders`, base), false);
    assert.equal(allowed('PUT', `${base}/api/guided-tour`, base), false);
});


test('automatic guided-tour persistence is answered in-browser without admitting a production write', () => {
    const welcome = JSON.stringify({ action: 'record_welcome_shown' });
    assert.equal(neutralized('PUT', `${base}/api/guided-tour`, base, welcome), true);
    assert.equal(neutralized('PUT', `${base}/api/guided-tour?source=welcome`, base, welcome), true);
    for (const body of [null, '', '{', 'null', '[]', '{}', JSON.stringify({ action: 'complete' }), JSON.stringify({ action: 'begin' }), JSON.stringify({ action: 'record_welcome_shown', step_id: 'review' })]) {
        assert.equal(neutralized('PUT', `${base}/api/guided-tour`, base, body), false);
    }
    assert.equal(neutralized('GET', `${base}/api/guided-tour`, base, welcome), false);
    assert.equal(neutralized('POST', `${base}/api/guided-tour`, base, welcome), false);
    assert.equal(neutralized('PUT', `${base}/api/guided-tour/reset`, base, welcome), false);
    assert.equal(neutralized('PUT', 'https://broker.example.test/api/guided-tour', base, welcome), false);
    assert.equal(neutralized('PUT', `${base}/api/transactions`, base, welcome), false);
});

test('only the exact welcome Skip UI action is eligible for transient local fulfillment', () => {
    const endpoint = `${base}/api/guided-tour`;
    const skip = JSON.stringify({ action: 'skip_prompt' });
    assert.equal(allowWelcomeSkip('PUT', endpoint, base, skip, { uiActionInProgress: true }), true);
    assert.equal(allowed('PUT', endpoint, base), false);
    assert.equal(allowWelcomeSkip('PUT', endpoint, base, skip, { uiActionInProgress: false }), false, 'unsolicited skip remains blocked');
    assert.equal(allowWelcomeSkip('PUT', endpoint, base, skip, { uiActionInProgress: 1 }), false, 'scope must be an explicit active boolean');
    const oneUseGate = createTransientWelcomeSkipGate(base);
    assert.equal(oneUseGate('PUT', endpoint, skip, { uiActionInProgress: false }), false);
    assert.equal(oneUseGate('PUT', endpoint, skip, { uiActionInProgress: true }), true);
    assert.equal(oneUseGate('PUT', endpoint, skip, { uiActionInProgress: true }), false, 'a second request cannot reuse the click scope');
    for (const [method, url, body, inProgress] of [
        ['POST', endpoint, skip, true],
        ['PUT', `${endpoint}?source=welcome`, skip, true],
        ['PUT', `${base}/api/guided-tour/reset`, skip, true],
        ['PUT', 'https://broker.example.test/api/guided-tour', skip, true],
        ['PUT', endpoint, '{', true],
        ['PUT', endpoint, 'null', true],
        ['PUT', endpoint, '[]', true],
        ['PUT', endpoint, JSON.stringify({ action: 'skip_prompt', step_id: 'review' }), true],
        ['PUT', endpoint, JSON.stringify({ action: 'dismiss_forever' }), true],
        ['PUT', endpoint, JSON.stringify({ action: 'begin' }), true],
        ['PUT', endpoint, JSON.stringify({ action: 'record_welcome_shown' }), true],
    ]) assert.equal(allowWelcomeSkip(method, url, base, body, { uiActionInProgress: inProgress }), false, `${method} ${url} ${body}`);
});

test('transient welcome Skip mirrors the normal prompt-count transition without forcing dismissal', () => {
    const baseState = { eligible: true, welcome_prompt_count: 0, max_auto_prompts: 3, show_welcome_prompt: true, can_manual_relaunch: true, tour_in_progress: false };
    assert.deepEqual(transientWelcomeSkipPayload(baseState), { ...baseState, welcome_prompt_count: 1 });
    assert.equal(transientWelcomeSkipPayload({ ...baseState, welcome_prompt_count: 2 }).show_welcome_prompt, false);
    assert.equal(transientWelcomeSkipPayload({ ...baseState, permanently_dismissed: true }).show_welcome_prompt, false);
    assert.equal(transientWelcomeSkipPayload({ ...baseState, completed: true }).show_welcome_prompt, false);
    assert.equal(transientWelcomeSkipPayload({ ...baseState, tour_in_progress: true }).show_welcome_prompt, false);
    for (const value of [null, [], {}, { ...baseState, welcome_prompt_count: 'unknown' }, { ...baseState, max_auto_prompts: NaN }]) {
        assert.equal(transientWelcomeSkipPayload(value), null);
    }
});


test('blocked production diagnostics discard private request values and unknown output', () => {
    assert.equal(productionSmokeBlockReason('PUT', `${base}/api/guided-tour?private_token=do_not_report`, base), 'guided_tour_write');
    assert.equal(productionSmokeBlockReason('GET', 'https://broker.example.test/private-capability?secret=do_not_report', base), 'cross_origin');
    assert.equal(productionSmokeBlockReason('POST', `${base}/api/auth/login`, base), 'late_login_write');
    assert.equal(productionSmokeBlockReason('DELETE', `${base}/api/v1/orders/1`, base), 'other_write');
    const stdout = 'private email password URL body\nAUTHENTICATED_SMOKE_BLOCKED:other_write,cross_origin,secret_value,other_write\nAUTHENTICATED_SMOKE_FAILED:request_safety';
    assert.deepEqual(summarizeProductionSmokeBlocks(stdout), ['cross_origin', 'other_write']);
    assert.deepEqual(summarizeProductionSmokeBlocks('untrusted private output'), []);
});
