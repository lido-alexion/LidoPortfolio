import { test, expect } from '@playwright/test';
import { allowProductionSmokeRequest } from './productionSmokePolicy.js';

test('production smoke is available and non-destructive', async ({ page }) => {
    test.skip(!process.env.STOX_E2E_BASE_URL, 'Set STOX_E2E_BASE_URL for controlled production smoke.');
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page).toHaveTitle(/StoX|Lido/i);
    await expect(page.locator('body')).not.toContainText(/Internal Server Error/i);

    const moduleScript = page.locator('script[type="module"][src]').first();
    await expect(moduleScript).toHaveCount(1);
    const moduleUrl = await moduleScript.getAttribute('src');
    expect(moduleUrl).toBeTruthy();
    const moduleResponse = await page.request.get(new URL(moduleUrl, process.env.STOX_E2E_BASE_URL).toString());
    expect(moduleResponse.ok()).toBeTruthy();
    expect(moduleResponse.headers()['content-type']).toMatch(/javascript/i);
});

test.describe('authenticated production smoke', () => {
    // Credentials must never be written into a trace, screenshot, or video artifact.
    test('dedicated smoke identity can open a read-only investor page', async ({ browser }) => {
        const email = process.env.STOX_SMOKE_EMAIL;
        const password = process.env.STOX_SMOKE_PASSWORD;
        test.skip(!email || !password, 'Configure a dedicated, read-only production smoke identity. Acceptance remains blocked until this runs.');

        const context = await browser.newContext({ baseURL: process.env.STOX_E2E_BASE_URL, serviceWorkers: 'block' });
        const page = await context.newPage();
        try {
            let login = true;
            const blocked = [];
            await page.route('**/*', async route => {
                const request = route.request();
                if (!allowProductionSmokeRequest(request.method(), request.url(), process.env.STOX_E2E_BASE_URL, { login })) {
                    blocked.push(`${request.method()} ${new URL(request.url()).pathname}`);
                    return route.abort();
                }
                return route.continue();
            });
            await page.goto('/login');
            await page.getByLabel('Email').fill(email);
            await page.getByLabel('Password').fill(password);
            await page.getByRole('button', { name: 'Login' }).click();
            await expect(page.getByRole('heading', { name: 'Login' })).toHaveCount(0, { timeout: 30_000 });
            login = false;
            await page.goto('/review');
            await expect(page.getByRole('heading', { name: 'Review' })).toBeVisible();
            expect(blocked).toEqual([]);
        } finally {
            await context.close();
        }
    });
});
