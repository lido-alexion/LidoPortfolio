import test from 'node:test';
import assert from 'node:assert/strict';
import { allowProductionSmokeRequest as allowed } from '../e2e/productionSmokePolicy.js';
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
