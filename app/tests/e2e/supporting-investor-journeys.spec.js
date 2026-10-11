import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
const STOCK = { id: 42, symbol: 'TCS', exchange: 'NSE', name: 'Tata Consultancy Services' };

async function prepare(page, viewport) {
    await seedDeterministicJourney(page, `supporting-${viewport.name}`);
    await page.setViewportSize(viewport);
    const blocked = [];
    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.origin !== 'http://127.0.0.1:4177') {
            blocked.push(url.origin);
            return route.abort();
        }
        return route.fallback();
    });
    await installInvestorWorkflowApiMocks(page);
    const requests = [];
    page.on('request', request => requests.push({ method: request.method(), path: new URL(request.url()).pathname }));
    return { blocked, requests };
}
function expectNoTrading({ blocked, requests }) {
    expect(blocked).toEqual([]);
    expect(requests.filter(r => /^(POST|PUT|PATCH|DELETE)$/.test(r.method) && /\/(orders|transactions|holdings)(?:\/|$)|\/execution\/|\/pipeline\//.test(r.path))).toEqual([]);
}
async function settingsMocks(page) {
    let settings = { default_stoploss_percent: '8', portfolio_trailing_percent: '10', notification_schedules: [] };
    await page.route('**/api/settings', route => {
        if (route.request().method() === 'PUT') settings = { ...settings, ...route.request().postDataJSON() };
        return route.fulfill({ json: { data: settings } });
    });
    await page.route('**/api/auth/sessions', route => route.fulfill({ json: { data: [] } }));
    await page.route('**/api/v1/capital/recall-period', route => route.fulfill({ json: { data: { effective_days: 3 } } }));
    await page.route('**/api/v1/totp', route => route.fulfill({ json: { data: { enabled: false } } }));
    await page.route('**/api/v1/execution/mode', route => route.fulfill({ json: { data: { execution_mode: 'manual', entitled: false, blockers: ['broker', 'totp', 'entitlement'] } } }));
    await page.route('**/api/personal-api-tokens', route => route.fulfill({ json: { data: [] } }));
}

for (const viewport of VIEWPORTS) {
    test(`E2E-09 researches a stock, retries failed membership, and removes it without trading (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'E2E-09');
        const evidence = await prepare(page, viewport);
        let items = [];
        let failAdd = true;
        await page.route('**/api/watchlists', route => route.fulfill({ json: { data: [{ id: 5, name: 'Research', item_count: items.length }] } }));
        await page.route('**/api/watchlists/5/items*', route => {
            if (route.request().method() === 'POST') {
                expect(route.request().postDataJSON().stock_id).toBe(42);
                if (failAdd) { failAdd = false; return route.fulfill({ status: 503, json: { message: 'Watchlist temporarily unavailable.' } }); }
                items = [{ id: 91, stock_id: 42, watchlist_id: 5, stock: STOCK, note: null }];
                return route.fulfill({ status: 201, json: { data: items[0] } });
            }
            return route.fulfill({ json: { data: items } });
        });
        await page.route('**/api/watchlist/membership*', route => route.fulfill({ json: { data: { watchlist_ids: items.length ? [5] : [] } } }));
        await page.route('**/api/watchlist-items/91', route => { items = []; return route.fulfill({ status: 204, body: '' }); });
        await page.route('**/api/stocks/42/pattern-scan*', route => route.fulfill({ json: { data: { matches: [] } } }));
        await page.goto('/watchlist');
        await expect(page.getByLabel('Active watchlist')).toHaveValue('5');
        await page.getByLabel('Stock', { exact: true }).fill('TCS');
        await page.getByRole('button', { name: /TCS.*Tata Consultancy/ }).click();
        await expect(page).toHaveURL(/\/watchlist\/TCS$/);
        await page.getByRole('button', { name: 'Fundamentals', exact: true }).click();
        await expect(page.getByText('Revenue (TTM)', { exact: true }).first()).toBeVisible();
        await page.getByRole('button', { name: 'Add to Research', exact: true }).click();
        await expect(page.getByText('Watchlist temporarily unavailable.', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Add to Research', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Remove TCS from watchlist', exact: true })).toBeVisible();
        await page.reload();
        await expect(page.getByRole('button', { name: 'Remove TCS from watchlist', exact: true })).toBeVisible();
        expect(items).toHaveLength(1);
        await page.getByRole('button', { name: 'Remove TCS from watchlist', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Remove TCS from watchlist', exact: true })).toHaveCount(0);
        expect(items).toEqual([]);
        expectNoTrading(evidence);
    });

    test(`E2E-10 retries a failed Kite handoff and disconnects a mocked connection (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'E2E-10');
        const evidence = await prepare(page, viewport);
        await settingsMocks(page);
        let connected = true;
        let usable = false;
        let failLogin = true;
        await page.route('**/api/v1/broker/status', route => route.fulfill({ json: { data: { configured: true, connected, usable } } }));
        await page.route('**/api/v1/broker/kite/login-url', route => {
            if (failLogin) { failLogin = false; return route.fulfill({ status: 503, json: { message: 'Kite temporarily unavailable.' } }); }
            usable = true;
            return route.fulfill({ json: { data: { url: 'http://127.0.0.1:4177/settings/account?mock-kite-return=1' } } });
        });
        await page.route('**/api/v1/broker/kite/disconnect', route => { connected = false; usable = false; return route.fulfill({ json: { data: { disconnected: true } } }); });
        await page.goto('/settings/account');
        const panel = page.locator('.card').filter({ has: page.locator('.card-header').getByText('Zerodha / Kite', { exact: true }) });
        await expect(panel.getByText('Session expired', { exact: true })).toBeVisible();
        await panel.getByRole('button', { name: 'Connect Kite' }).click();
        await expect(page.getByText('Kite temporarily unavailable.', { exact: true })).toBeVisible();
        await panel.getByRole('button', { name: 'Connect Kite' }).click();
        await expect(page).toHaveURL(/mock-kite-return=1/);
        await expect(panel.getByText('Connected', { exact: true })).toBeVisible();
        await panel.getByRole('button', { name: 'Disconnect', exact: true }).click();
        await expect(panel.getByText('Not connected', { exact: true })).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Disconnect', exact: true })).toBeDisabled();
        expectNoTrading(evidence);
    });

    test(`E2E-11 saves independent portfolio safeguards and reads them after refresh (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'E2E-11');
        const evidence = await prepare(page, viewport);
        await settingsMocks(page);
        await page.goto('/settings/portfolio');
        const stop = page.getByLabel('Portfolio Stop-Loss %', { exact: true });
        const trailing = page.getByLabel('Portfolio Trailing Stop %', { exact: true });
        await expect(stop).toHaveValue('8');
        await stop.fill('7');
        await trailing.fill('12');
        const saved = page.waitForRequest(r => r.method() === 'PUT' && new URL(r.url()).pathname === '/api/settings');
        await page.getByRole('button', { name: 'Save portfolio settings' }).click();
        const request = await saved;
        expect(request.postDataJSON()).toMatchObject({ default_stoploss_percent: '7.00', portfolio_trailing_percent: '12.00' });
        await expect(page.getByText('Portfolio settings saved', { exact: true }).first()).toBeVisible();
        await page.reload();
        await expect(stop).toHaveValue('7.00');
        await expect(trailing).toHaveValue('12.00');
        expectNoTrading(evidence);
    });
}
