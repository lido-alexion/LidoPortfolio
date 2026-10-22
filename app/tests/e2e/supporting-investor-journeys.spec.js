import { createApiFailureDiagnosticCollector, installApiFailureDiagnosticBoundary, handleApiFailureDiagnostic, registerApiFailureDiagnosticAfterEach } from './apiFailureDiagnosticBoundary.js';

import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { TEST_PORTFOLIO, TEST_USER } from '../js/tos/fixtures/tosApi.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

registerApiFailureDiagnosticAfterEach(test);


const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
const STOCK = { id: 42, symbol: 'TCS', exchange: 'NSE', name: 'Tata Consultancy Services' };

async function prepare(page, viewport) {
    await seedDeterministicJourney(page, `supporting-${viewport.name}`);
    await page.setViewportSize(viewport);
    const blocked = []; const diagnosticReports = createApiFailureDiagnosticCollector(page);
    await page.route('**/*', async route => {
        if (await handleApiFailureDiagnostic(route, { origin: 'http://127.0.0.1:4177', collector: diagnosticReports })) return;
        const url = new URL(route.request().url());
        if (url.origin !== 'http://127.0.0.1:4177') {
            blocked.push(url.origin);
            return route.abort();
        }
        return route.fallback();
    });
    await installInvestorWorkflowApiMocks(page);
    await installApiFailureDiagnosticBoundary(page, { origin: 'http://127.0.0.1:4177', collector: diagnosticReports });
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
  test.describe(`Supporting investor ${viewport.name}`, () => {
    test.use({ hasTouch: viewport.name === 'mobile' });
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
        await page.route('**/api/watchlist/membership*', route => route.fulfill({ json: { watchlist_ids: items.length ? [5] : [] } }));
        await page.route('**/api/watchlist-items/91', route => { items = []; return route.fulfill({ status: 204, body: '' }); });
        await page.route('**/api/stocks/42/pattern-scan*', route => route.fulfill({ json: { data: { matches: [] } } }));
        await page.goto('/watchlist');
        await expect(page.getByLabel('Active watchlist')).toHaveValue('5');
        await page.getByLabel('Stock', { exact: true }).fill('TCS');
        await page.getByRole('button', { name: /TCS.*Tata Consultancy/ }).click();
        await expect(page).toHaveURL(/\/watchlist\/TCS$/);
        await page.getByRole('button', { name: 'Fundamentals', exact: true }).click();
        await expect(page.getByRole('img', { name: /^Revenue \(TTM\):/ })).toBeVisible();
        await page.getByRole('button', { name: 'Add to Research', exact: true }).click();
        await expect(page.getByText('Watchlist temporarily unavailable.', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Add to Research', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Remove TCS from watchlist', exact: true })).toBeVisible();
        await page.reload();
        await expect(page.getByRole('button', { name: 'Remove TCS from watchlist', exact: true })).toBeVisible();
        expect(items).toHaveLength(1);
        if (viewport.name === 'desktop') await page.locator('.lido-watchlist-row').hover();
        await page.getByRole('button', { name: 'Remove TCS from watchlist', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Remove TCS from watchlist', exact: true })).toHaveCount(0);
        expect(items).toEqual([]);
        expectNoTrading(evidence);
    });

    test(`E2E-10 retries unknown Kite status and disconnect read failure (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'E2E-10');
        const evidence = await prepare(page, viewport);
        await settingsMocks(page);
        let connected = true;
        let usable = false;
        let failLogin = true;
        let statusReads = 0;
        let allowInitialStatus = false;
        let failStatusAfterDisconnect = true;
        let disconnectPosts = 0;
        let loginUrlReads = 0;
        await page.route('**/api/v1/broker/status', route => {
            statusReads += 1;
            if (!allowInitialStatus || (!connected && failStatusAfterDisconnect)) {
                if (!connected) failStatusAfterDisconnect = false;
                return route.fulfill({ status: 503, json: { message: 'Synthetic private broker status failure.' } });
            }
            return route.fulfill({ json: { data: { configured: true, connected, usable } } });
        });
        await page.route('**/api/v1/broker/kite/login-url', route => {
            loginUrlReads += 1;
            if (failLogin) { failLogin = false; return route.fulfill({ status: 503, json: { message: 'Kite temporarily unavailable.' } }); }
            usable = true;
            return route.fulfill({ json: { data: { url: 'http://127.0.0.1:4177/settings/account?mock-kite-return=1' } } });
        });
        await page.route('**/api/v1/broker/kite/disconnect', route => {
            disconnectPosts += 1;
            connected = false;
            usable = false;
            return route.fulfill({ json: { data: { disconnected: true } } });
        });
        await page.goto('/settings/account');
        const panel = page.locator('.card').filter({ has: page.locator('.card-header').getByText('Zerodha / Kite', { exact: true }) });

        const initialStatusAlert = panel.getByRole('alert');
        await expect(initialStatusAlert).toContainText('Kite connection status is unavailable. Retry to check the current connection.');
        await expect(initialStatusAlert).not.toContainText('Synthetic private broker status failure.');
        await expect(panel.getByText('Not connected', { exact: true })).toHaveCount(0);
        await expect(panel.getByText('Kite API keys are not configured on this server.', { exact: true })).toHaveCount(0);
        await expect(panel.getByRole('button', { name: 'Connect Kite' })).toBeDisabled();
        await expect(panel.getByRole('button', { name: 'Disconnect' })).toBeDisabled();
        expect(statusReads).toBeGreaterThan(0);
        const readsBeforeInitialRetry = statusReads;
        allowInitialStatus = true;
        await panel.getByRole('button', { name: 'Retry status', exact: true }).click();
        await expect(panel.getByText('Session expired', { exact: true })).toBeVisible();
        expect(statusReads).toBe(readsBeforeInitialRetry + 1);
        await panel.getByRole('button', { name: 'Connect Kite' }).click();
        await expect(page.getByText('Kite temporarily unavailable.', { exact: true })).toBeVisible();
        await panel.getByRole('button', { name: 'Connect Kite' }).click();
        await expect(page).toHaveURL(/mock-kite-return=1/);
        await expect(panel.getByText('Connected', { exact: true })).toBeVisible();
        await panel.getByRole('button', { name: 'Disconnect', exact: true }).click();

        const disconnectStatusAlert = panel.getByRole('alert');
        await expect(disconnectStatusAlert).toContainText('Kite connection status is unavailable. Retry to check the current connection.');
        await expect(disconnectStatusAlert).not.toContainText('Synthetic private broker status failure.');
        await expect(panel.getByText('Connected', { exact: true })).toHaveCount(0);
        await expect(panel.getByRole('button', { name: 'Connect Kite' })).toBeDisabled();
        await expect(panel.getByRole('button', { name: 'Disconnect', exact: true })).toBeDisabled();
        await expect.poll(() => disconnectPosts).toBe(1);
        const readsBeforeDisconnectRetry = statusReads;

        await panel.getByRole('button', { name: 'Retry status', exact: true }).click();
        await expect(panel.getByText('Not connected', { exact: true })).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Disconnect', exact: true })).toBeDisabled();
        expect(disconnectPosts).toBe(1);
        expect(statusReads).toBe(readsBeforeDisconnectRetry + 1);
        expect(loginUrlReads).toBe(2);
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
  });
}

for (const viewport of VIEWPORTS) {
  test.describe(`Supporting investor ${viewport.name}`, () => {
    test.use({ hasTouch: viewport.name === 'mobile' });
    test(`AUTH-03 ends a session and requires login before restoring protected content (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AUTH-03');
        const evidence = await prepare(page, viewport);
        let signedIn = true;
        let logoutAttempts = 0;
        await page.route('**/api/auth/me', route => route.fulfill({ status: signedIn ? 200 : 401, json: signedIn ? { user: TEST_USER } : { message: 'Unauthenticated.' } }));
        await page.route('**/api/auth/logout', route => {
            logoutAttempts++;
            if (logoutAttempts === 1) return route.fulfill({ status: 503, json: { message: 'Synthetic private server detail.' } });
            signedIn = false;
            return route.fulfill({ status: 204, body: '' });
        });
        await page.route('**/api/auth/login', route => { signedIn = true; return route.fulfill({ json: { user: TEST_USER } }); });
        await page.goto('/screeners');
        await expect(page.getByRole('heading', { name: 'Screener', exact: true })).toBeVisible();
        const meRequestsBeforeLogout = evidence.requests.filter(request => request.path === '/api/auth/me').length;
        await page.getByRole('button', { name: /Profile menu for/ }).click();
        await page.getByRole('button', { name: /Logout$/ }).click();
        await expect(page.getByRole('alert')).toHaveText('Logout could not be confirmed. Your session may still be active. Please retry.');
        await expect(page.getByRole('heading', { name: 'Screener', exact: true })).toBeVisible();
        await expect(page).toHaveURL(/\/screeners$/);
        expect(logoutAttempts).toBe(1);
        expect(evidence.requests.filter(request => request.path === '/api/auth/me')).toHaveLength(meRequestsBeforeLogout);
        await Promise.all([page.waitForURL('**/', { waitUntil: 'load' }), page.getByRole('button', { name: /Retry logout$/ }).click()]);
        await expect(page.getByRole('heading', { name: 'Login', exact: true })).toBeVisible();
        expect(logoutAttempts).toBe(2);
        await page.goto('/screeners?source=ended-session');
        await expect(page.getByRole('heading', { name: 'Login', exact: true })).toBeVisible();
        await page.reload();
        await expect(page.getByRole('heading', { name: 'Login', exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Screener', exact: true })).toHaveCount(0);
        await page.getByLabel('Email').fill('investor@example.test');
        await page.getByLabel('Password').fill('deterministic-test-password');
        await page.getByRole('button', { name: 'Login', exact: true }).click();
        await expect(page).toHaveURL(/screeners\?source=ended-session$/);
        await expect(page.getByRole('heading', { name: 'Screener', exact: true })).toBeVisible();
        expectNoTrading(evidence);
    });

    test(`E2E-12 recovers notification history and persists attention without trading (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'E2E-12');
        const evidence = await prepare(page, viewport);
        let attention = 'read';
        let failHistory = true;
        await page.route('**/api/notification-center**', route => {
            const req = route.request();
            const url = new URL(req.url());
            if (url.pathname.endsWith('/unread')) { attention = 'unread'; return route.fulfill({ json: { data: { attention_state: attention } } }); }
            if (req.method() === 'GET' && url.pathname === '/api/notification-center') {
                // The header summary is not the history page's per_page=50 request.
                if (url.searchParams.get('per_page') === '50' && failHistory) { failHistory = false; return route.fulfill({ status: 503, json: { message: 'History temporarily unavailable.' } }); }
                return route.fulfill({ json: { data: [{ id: 17, attention_state: attention, condition_state: 'na', type: 'recommendation.changed', severity: 'info', title: 'Recommendation changed', message: 'A product update is ready.', latest_activity_at: '2026-10-04T10:00:00Z', deliveries: [] }], meta: { unread_count: attention === 'unread' ? 1 : 0, active_critical_count: 0 } } });
            }
            return route.fallback();
        });
        await page.goto('/notification-history');
        await expect(page.getByRole('button', { name: 'Retry', exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Retry', exact: true }).click();
        await page.getByLabel('Search notifications').fill('recommendation');
        const query = page.waitForRequest(r => new URL(r.url()).pathname === '/api/notification-center' && new URL(r.url()).searchParams.get('q') === 'recommendation');
        await page.getByRole('button', { name: 'Apply filters' }).click();
        await query;
        await expect(page.getByText('Recommendation changed', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Mark unread', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Mark as read', exact: true })).toBeVisible();
        await page.reload();
        await expect(page.getByRole('button', { name: 'Mark as read', exact: true })).toBeVisible();
        expect(attention).toBe('unread');
        expectNoTrading(evidence);
    });

    test(`E2E-13 switches scoped holdings per tab and retains selection after refresh (${viewport.name})`, async ({ page, context }, testInfo) => {
        journeyId(testInfo, 'E2E-13');
        const evidence = await prepare(page, viewport);
        const second = { id: 2, name: 'Long Term', is_default: false };
        await page.route('**/api/portfolios', route => route.fulfill({ json: { data: [TEST_PORTFOLIO, second] } }));
        const scoped = [];
        await page.route('**/api/holdings', route => { scoped.push(route.request().headers()['x-profile-id']); return route.fulfill({ json: { data: [] } }); });
        await page.goto('/holdings');
        const switcher = page.getByRole('button', { name: 'Main', exact: true });
        await expect(switcher).toBeVisible();
        await switcher.click();
        const switched = page.waitForRequest(r => new URL(r.url()).pathname === '/api/holdings' && r.headers()['x-profile-id'] === '2');
        await page.getByRole('button', { name: 'Long Term', exact: true }).click();
        await switched;
        await expect(page.locator('.lido-portfolio-switcher')).toHaveText('Long Term');
        await page.reload();
        await expect(page.locator('.lido-portfolio-switcher')).toHaveText('Long Term');
        expect(scoped.at(-1)).toBe('2');
        const other = await context.newPage();
        const otherEvidence = await prepare(other, viewport);
        await other.route('**/api/portfolios', route => route.fulfill({ json: { data: [TEST_PORTFOLIO, second] } }));
        await other.goto('/holdings');
        await expect(other.locator('.lido-portfolio-switcher')).toHaveText('Main');
        await expect(page.locator('.lido-portfolio-switcher')).toHaveText('Long Term');
        expectNoTrading(evidence);
        expectNoTrading(otherEvidence);
        await other.close();
    });
  });
}
