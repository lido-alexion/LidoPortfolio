import { test, expect } from '@playwright/test';
import { captureKiteMenuHitTest, createComboPreferenceLifecycleCapture } from './productionSmokeDiagnostics.js';

test('persistent overlay keeps the ordinary Kite menu click blocked and classifies its hit target', async ({ page }) => {
    await page.setContent(`<style>#overlay{position:fixed;inset:0;z-index:2;background:#0002}</style><header class="lido-header"><div class="lido-kite-header-control"><button id="target" aria-haspopup="menu">Kite menu</button></div></header><div id="overlay" class="lido-guided-tour-scrim"></div>`);
    const classification = await captureKiteMenuHitTest(page);
    expect(classification).toEqual({ hit: 'other', overlay: 'guided_tour' });
    await expect(page.locator('#target').click({ timeout: 100 })).rejects.toThrow();
    await page.locator('#overlay').evaluate(element => element.remove());
    expect(await captureKiteMenuHitTest(page)).toEqual({ hit: 'target', overlay: 'none' });
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
