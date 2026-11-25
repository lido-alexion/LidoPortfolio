import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { allowProductionSmokeRequest } from '../e2e/productionSmokePolicy.js';
const script = fileURLToPath(new URL('../e2e/authenticatedProductionSmoke.mjs', import.meta.url));
for (const baseURL of ['', 'http://example.test']) {
    test(`authenticated smoke fails closed without exposing configuration (${baseURL ? 'non-HTTPS' : 'missing URL'})`, () => {
        const result = spawnSync(process.execPath, [script], {
            env: { ...process.env, STOX_E2E_BASE_URL: baseURL, STOX_SMOKE_EMAIL: 'privacy-marker@example.test', STOX_SMOKE_PASSWORD: 'privacy-password-marker' },
            encoding: 'utf8', timeout: 10_000,
        });
        assert.equal(result.status, 1);
        assert.equal(result.stdout.trim(), 'AUTHENTICATED_SMOKE_FAILED:configuration');
        assert.equal(result.stderr, '');
    });
}

test('production dashboard smoke inspects only status UI and never activates financial controls', async () => {
    const source = await (await import('node:fs/promises')).readFile(script, 'utf8');
    assert.match(source, /pathname === '\/api\/v1\/broker\/status'/);
    assert.match(source, /\.lido-kite-header-control/);
    assert.match(source, /DOCUMENT_POSITION_FOLLOWING/);
    // Safety controls load asynchronously; the physical order must only be
    // asserted once both actual controls are visible (never waived).
    const safetyReadyIndex = source.indexOf("await safetyControls.waitFor({ state: 'visible' });");
    const headerOrderIndex = source.indexOf("stage = 'dashboard_kite_header_order';");
    assert.ok(safetyReadyIndex >= 0 && headerOrderIndex > safetyReadyIndex);
    assert.match(source, /Emergency Kite disconnect/);
    assert.match(source, /Cancel eligible open orders and disconnect Kite/);
    assert.match(source, /Reconcile excluding today/);
    assert.match(source, /Reconcile including today/);
    assert.match(source, /not_eligible_card_absent/);
    assert.match(source, /kiteControl\.getByRole\('button'\)\.first\(\)/);
    assert.match(source, /kitePrimaryButton\.getAttribute\('aria-label'\)/);
    assert.doesNotMatch(source, /kiteControl\.getAttribute\('aria-label'\)/);
    assert.match(source, /stage = 'combo_preference_response_wait'/);
    assert.match(source, /stage = 'combo_preference_response_status'/);
    assert.match(source, /target\.origin === baseOrigin/);
    assert.doesNotMatch(source, /(?:\.click\(\)|\.check\(|\.fill\()[^\n]*(?:Reconcile|Disconnect|Emergency|Recover|Cancel)/i);
});

test('production smoke request policy blocks all financial and Kite mutation endpoints', () => {
    const base = 'https://stox.example.test';
    for (const path of [
        '/api/reconciliation', '/api/v1/broker/kite/disconnect', '/api/v1/broker/kite/emergency-disconnect',
        '/api/v1/broker/kite/emergency-cancel-open-orders-disconnect', '/api/v1/execution/recover',
        '/api/v1/recommendations/1/cancel-execution',
    ]) {
        assert.equal(allowProductionSmokeRequest('POST', `${base}${path}`, base), false, path);
    }
    assert.equal(allowProductionSmokeRequest('POST', 'https://other.example.test/api/reconciliation', base), false);
    assert.equal(allowProductionSmokeRequest('GET', `${base}/api/v1/broker/status`, base), true);
});

test('Kite production smoke waits for hydrated state and verifies a read-only menu using stable DOM selectors', async () => {
    const source = await (await import('node:fs/promises')).readFile(script, 'utf8');
    assert.match(source, /kiteControl\.locator\('button\[aria-haspopup="menu"\]'\)/);
    assert.match(source, /stage = 'dashboard_kite_menu_ready'/);
    assert.match(source, /stage = 'dashboard_kite_menu_expanded'/);
    assert.match(source, /stage = 'dashboard_kite_menu_collapsed'/);
    assert.match(source, /menu\.getAttribute\('aria-label'\) ===/);
    assert.match(source, /await kiteMenuButton\.click\(\)/);
    assert.match(source, /Kite settings/);
    assert.match(source, /Disconnect Kite…/);
    assert.doesNotMatch(source, /await kiteMenuButton\.elementHandle\(\)/);
    const ready = source.indexOf("stage = 'dashboard_kite_menu_ready';");
    const open = source.indexOf("stage = 'dashboard_kite_menu_open';");
    const expanded = source.indexOf("stage = 'dashboard_kite_menu_expanded';");
    const items = source.indexOf("stage = 'dashboard_kite_menu_items';");
    assert.ok(ready >= 0 && ready < open && open < expanded && expanded < items);
});
