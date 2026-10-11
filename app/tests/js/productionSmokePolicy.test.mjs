import test from 'node:test';
import assert from 'node:assert/strict';
import { allowProductionSmokeRequest as allowed, neutralizeProductionSmokeRequest as neutralized, productionSmokeBlockReason, summarizeProductionSmokeBlocks } from '../e2e/productionSmokePolicy.js';
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
    assert.equal(neutralized('PUT', `${base}/api/guided-tour`, base), true);
    assert.equal(neutralized('PUT', `${base}/api/guided-tour?source=welcome`, base), true);
    assert.equal(neutralized('GET', `${base}/api/guided-tour`, base), false);
    assert.equal(neutralized('POST', `${base}/api/guided-tour`, base), false);
    assert.equal(neutralized('PUT', `${base}/api/guided-tour/reset`, base), false);
    assert.equal(neutralized('PUT', 'https://broker.example.test/api/guided-tour', base), false);
    assert.equal(neutralized('PUT', `${base}/api/transactions`, base), false);
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
