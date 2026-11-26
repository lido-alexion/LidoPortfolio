import { test, expect } from '@playwright/test';
import { captureKiteMenuHitTest, createComboPreferenceLifecycleCapture } from './productionSmokeDiagnostics.js';
import { createTransientWelcomeSkipGate, transientWelcomeSkipPayload } from './productionSmokePolicy.js';

test('persistent overlay keeps the ordinary Kite menu click blocked and classifies its hit target', async ({ page }) => {
    await page.setContent(`<style>#overlay{position:fixed;inset:0;z-index:2;background:#0002}</style><header class="lido-header"><div class="lido-kite-header-control"><button id="target" aria-haspopup="menu">Kite menu</button></div></header><div id="overlay" class="lido-guided-tour-scrim"></div>`);
    const classification = await captureKiteMenuHitTest(page);
    expect(classification).toEqual({ hit: 'other', overlay: 'guided_tour' });
    await expect(page.locator('#target').click({ timeout: 100 })).rejects.toThrow();
    await page.locator('#overlay').evaluate(element => element.remove());
    expect(await captureKiteMenuHitTest(page)).toEqual({ hit: 'target', overlay: 'none' });
});

test('smoke dismisses an eligible welcome overlay through its normal control before later navigation', async ({ page }) => {
    const base = 'http://kite-welcome-lifecycle.invalid';
    const gate = createTransientWelcomeSkipGate(base);
    const state = { eligible: true, completed: false, permanently_dismissed: false, tour_in_progress: false, welcome_prompt_count: 0, max_auto_prompts: 3 };
    let skipUiActionInProgress = false;
    let skipFulfilledLocally = false;
    await page.route(base + '/api/guided-tour', async route => {
        const allowed = gate(route.request().method(), route.request().url(), route.request().postData(), { uiActionInProgress: skipUiActionInProgress });
        if (!allowed) return route.abort();
        skipFulfilledLocally = true;
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: transientWelcomeSkipPayload(state) }) });
    });
    await page.route(base + '/', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>synthetic welcome fixture</body></html>' }));
    await page.goto(base + '/');
    await page.setContent(`<style>#welcome{position:fixed;inset:0;z-index:2;background:#0008;display:grid;place-items:center}#welcome[hidden]{display:none}</style>
        <header class="lido-header"><div class="lido-kite-header-control"><button id="kite" aria-haspopup="menu" aria-expanded="false" onclick="this.setAttribute('aria-expanded','true')">Kite menu</button></div></header>
        <div id="welcome" class="lido-guided-tour-modal" role="dialog" aria-modal="true" aria-labelledby="welcome-title"><h2 id="welcome-title">Welcome to StoX</h2><button id="skip" onclick="fetch('/api/guided-tour',{method:'PUT',headers:{'content-type':'application/json'},body:JSON.stringify({action:'skip_prompt'})}).then(()=>document.querySelector('#welcome').remove())">Skip for now</button></div>`);
    const welcome = page.getByRole('dialog', { name: 'Welcome to StoX' });
    await expect(welcome).toBeVisible();
    await expect(page.locator('#kite').click({ timeout: 100 })).rejects.toThrow(/intercepts pointer events/i);
    skipUiActionInProgress = true;
    const skipResponsePromise = page.waitForResponse(response => response.url() === base + '/api/guided-tour' && response.request().method() === 'PUT');
    try {
        await page.getByRole('button', { name: 'Skip for now', exact: true }).click();
        const skipResponse = await skipResponsePromise;
        expect(skipResponse.ok()).toBe(true);
        expect(await skipResponse.json()).toEqual({ data: transientWelcomeSkipPayload(state) });
    } finally {
        skipUiActionInProgress = false;
    }
    expect(skipFulfilledLocally).toBe(true);
    expect(gate('PUT', base + '/api/guided-tour', '{"action":"skip_prompt"}', { uiActionInProgress: true })).toBe(false);
    expect(gate('PUT', base + '/api/guided-tour', '{"action":"skip_prompt"}', { uiActionInProgress: false })).toBe(false);
    await expect(welcome).toBeHidden();
    expect(await captureKiteMenuHitTest(page)).toEqual({ hit: 'target', overlay: 'none' });
    await page.locator('#kite').click();
    await expect(page.locator('#kite')).toHaveAttribute('aria-expanded', 'true');
});

test('preference lifecycle distinguishes response, abort, method mismatch, and path mismatch', async ({ page }) => {
    const origin = 'http://kite-smoke-diagnostics.invalid';
    await page.route(origin + '/', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>synthetic fixture</body></html>' }));
    await page.route(origin + '/api/v1/stock-details/**', route => {
        if (route.request().url().endsWith('/combo-chart-preference') && route.request().method() === 'GET') {
            return route.fulfill({ status: 200, contentType: 'application/json', body: '{"data":{"scope":"account"}}' });
        }
        return route.abort();
    });
    const capture = createComboPreferenceLifecycleCapture(page, origin);
    await page.goto(origin + '/');
    await page.evaluate(() => fetch('/api/v1/stock-details/combo-chart-preference'));
    expect(capture.snapshot()).toBe('response');
    capture.dispose();

    const aborted = createComboPreferenceLifecycleCapture(page, origin);
    await page.evaluate(() => fetch('/api/v1/stock-details/combo-chart-preference?synthetic=abort').catch(() => {}));
    expect(aborted.snapshot()).toBe('failed');
    aborted.dispose();

    const wrongMethod = createComboPreferenceLifecycleCapture(page, origin);
    await page.evaluate(() => fetch('/api/v1/stock-details/combo-chart-preference', { method: 'POST' }).catch(() => {}));
    expect(wrongMethod.snapshot()).toBe('method_mismatch');
    wrongMethod.dispose();

    const mismatch = createComboPreferenceLifecycleCapture(page, origin);
    await page.evaluate(() => fetch('/api/v1/stock-details/other').catch(() => {}));
    expect(mismatch.snapshot()).toBe('path_mismatch');
    mismatch.dispose();
});

test('preference lifecycle remains pending until a delayed mocked response arrives', async ({ page }) => {
    const origin = 'http://kite-preference-delay.invalid';
    let releaseResponse;
    const responseGate = new Promise(resolve => { releaseResponse = resolve; });
    await page.route(origin + '/api/v1/stock-details/combo-chart-preference', async route => {
        await responseGate;
        await route.fulfill({ status: 200, contentType: 'application/json', body: '{"data":{"scope":"account","default_preset_id":"price-volume"}}' });
    });
    await page.route(origin + '/', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>synthetic preference fixture</body></html>' }));
    const capture = createComboPreferenceLifecycleCapture(page, origin);
    await page.goto(origin + '/');
    const responsePromise = page.evaluate(() => fetch('/api/v1/stock-details/combo-chart-preference').then(response => response.json()));
    try {
        await expect.poll(() => capture.snapshot()).toBe('matching_pending');
    } finally {
        releaseResponse();
    }
    const payload = await responsePromise;
    expect(payload.data.scope).toBe('account');
    expect(capture.snapshot()).toBe('response');
    capture.dispose();
});
