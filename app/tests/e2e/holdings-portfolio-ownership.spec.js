import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { TEST_PORTFOLIO } from '../js/tos/fixtures/tosApi.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { createApiFailureDiagnosticCollector, installApiFailureDiagnosticBoundary, handleApiFailureDiagnostic, registerApiFailureDiagnosticAfterEach } from './apiFailureDiagnosticBoundary.js';

registerApiFailureDiagnosticAfterEach(test);
const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];

for (const viewport of VIEWPORTS) {
    test.describe(`Holdings portfolio ownership ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });
        test('E2E-13 keeps the active portfolio rows and position actions after a late prior read', async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-13');
            await seedDeterministicJourney(page, `holdings-owner-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const blocked = []; const diagnostics = createApiFailureDiagnosticCollector(page); const writes = []; const requests = [];
            const responses = [];
            page.on('request', (request) => {
                const url = new URL(request.url());
                requests.push({ method: request.method(), origin: url.origin, path: url.pathname, profile: request.headers()['x-profile-id'] });
                if (/^(POST|PUT|PATCH|DELETE)$/.test(request.method())) writes.push({ method: request.method(), origin: url.origin, path: url.pathname });
                if (url.origin !== 'http://127.0.0.1:4177') blocked.push(url.origin);
            });
            page.on('response', response => responses.push(response));
            await installInvestorWorkflowApiMocks(page);
            await installApiFailureDiagnosticBoundary(page, { origin: 'http://127.0.0.1:4177', collector: diagnostics });
            const second = { id: 2, name: 'Long Term', is_default: false };
            await page.route('**/api/portfolios', route => route.fulfill({ json: { data: [TEST_PORTFOLIO, second] } }));
            let releaseA;
            const lateA = new Promise(resolve => { releaseA = resolve; });
            let aProtectionRequests = 0;
            await page.route('**/api/holdings', async route => {
                const profile = route.request().headers()['x-profile-id'];
                if (profile === '1') {
                    return route.fulfill({ json: { data: [{ id: 101, stock_id: 41, quantity: 3, avg_buy_price: 10, invested_amount: 30, stock: { id: 41, symbol: 'LATE_A' }, stoploss_summary: {} }] } });
                }
                return route.fulfill({ json: { data: [{ id: 202, stock_id: 42, quantity: 8, avg_buy_price: 20, invested_amount: 160, stock: { id: 42, symbol: 'ACTIVE_B' }, stoploss_summary: {} }] } });
            });
            await page.route('**/api/v1/execution/mode', route => route.fulfill({ json: { data: { execution_mode: route.request().headers()['x-profile-id'] === '2' ? 'semi_automatic' : 'manual' } } }));
            await page.route('**/api/v1/protections', async route => {
                if (route.request().headers()['x-profile-id'] === '1') { aProtectionRequests += 1; await lateA; }
                return route.fulfill({ json: { data: route.request().headers()['x-profile-id'] === '2' ? [{ id: 77, holding_id: 202, state: 'active', type: 'target' }] : [] } });
            });
            // Register a catch-all guard after the app mocks so it runs first and only falls
            // through to the explicit local responses below.
            await page.route('**/*', async route => {
                if (await handleApiFailureDiagnostic(route, { origin: 'http://127.0.0.1:4177', collector: diagnostics })) return;
                const request = route.request(); const url = new URL(request.url());
                if (url.origin !== 'http://127.0.0.1:4177') { blocked.push(url.origin); return route.abort(); }
                if (/^(POST|PUT|PATCH|DELETE)$/.test(request.method())) return route.abort();
                return route.fallback();
            });
            await page.goto('/holdings');
            await expect.poll(() => aProtectionRequests).toBeGreaterThan(0);
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            const initialAHoldingsCount = requests.filter(request => request.path === '/api/holdings' && request.profile === '1').length;
            const initialANestedCount = aProtectionRequests;
            expect(initialAHoldingsCount).toBeGreaterThan(0);
            expect(requests.filter(request => request.path === '/api/v1/execution/mode' && request.profile === '1')).toHaveLength(initialANestedCount);
            expect(requests.filter(request => request.path === '/api/v1/protections' && request.profile === '1')).toHaveLength(initialANestedCount);
            await expect.poll(() => responses.filter(response => new URL(response.url()).pathname === '/api/holdings' && response.request().headers()['x-profile-id'] === '1').length).toBe(initialAHoldingsCount);
            await expect.poll(() => responses.filter(response => new URL(response.url()).pathname === '/api/v1/execution/mode' && response.request().headers()['x-profile-id'] === '1').length).toBe(initialANestedCount);
            const initialAHoldings = responses.filter(response => new URL(response.url()).pathname === '/api/holdings' && response.request().headers()['x-profile-id'] === '1');
            const initialAMode = responses.filter(response => new URL(response.url()).pathname === '/api/v1/execution/mode' && response.request().headers()['x-profile-id'] === '1');
            await Promise.all([...initialAHoldings, ...initialAMode].map(async response => { expect(response.status()).toBe(200); const body = await response.json(); expect(body).toHaveProperty('data'); expect(await response.finished()).toBeNull(); }));
            const switcher = page.getByRole('button', { name: 'Main', exact: true });
            await expect(switcher).toBeVisible(); await switcher.click();
            await page.getByRole('button', { name: 'Long Term', exact: true }).click();
            await expect(page.locator('.lido-portfolio-switcher')).toHaveText('Long Term');
            await expect(page.getByText('ACTIVE_B', { exact: true }).first()).toBeVisible();
            await expect.poll(() => responses.filter(response => new URL(response.url()).pathname === '/api/v1/execution/mode' && response.request().headers()['x-profile-id'] === '2').length).toBeGreaterThan(0);
            await expect.poll(() => responses.filter(response => new URL(response.url()).pathname === '/api/v1/protections' && response.request().headers()['x-profile-id'] === '2').length).toBeGreaterThan(0);
            await page.getByRole('button', { name: 'Open menu', exact: true }).click();
            await expect(page.getByRole('menuitem', { name: 'Cancel protection', exact: true })).toBeVisible();
            const priorAResponses = responses.filter(response => new URL(response.url()).pathname === '/api/v1/protections' && response.request().headers()['x-profile-id'] === '1').length;
            releaseA();
            await expect.poll(() => responses.filter(response => new URL(response.url()).pathname === '/api/v1/protections' && response.request().headers()['x-profile-id'] === '1').length).toBe(priorAResponses + initialANestedCount);
            const lateResponses = responses.filter(response => new URL(response.url()).pathname === '/api/v1/protections' && response.request().headers()['x-profile-id'] === '1');
            await Promise.all(lateResponses.map(async response => { expect(response.status()).toBe(200); expect(await response.json()).toEqual({ data: [] }); expect(await response.finished()).toBeNull(); }));
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            await expect(page.locator('.lido-portfolio-switcher')).toHaveText('Long Term');
            await expect(page.getByText('ACTIVE_B', { exact: true }).first()).toBeVisible();
            await expect(page.getByText('LATE_A', { exact: true })).toHaveCount(0);
            await expect(page.getByRole('button', { name: 'Sell' }).first()).toBeVisible();
            await expect(page.getByRole('menuitem', { name: 'Cancel protection', exact: true })).toBeVisible();
            expect(blocked).toEqual([]);
            expect(requests.filter(request => request.origin !== 'http://127.0.0.1:4177')).toEqual([]);
            expect(writes).toEqual([]);
            expect(requests.filter(request => request.path === '/api/holdings').map(request => request.profile)).toContain('2');
        });
    });
}
