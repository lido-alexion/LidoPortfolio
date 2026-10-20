import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { createApiFailureDiagnosticCollector, installApiFailureDiagnosticBoundary, handleApiFailureDiagnostic, registerApiFailureDiagnosticAfterEach } from './apiFailureDiagnosticBoundary.js';

registerApiFailureDiagnosticAfterEach(test);



const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
async function prepare(page, viewport, name) {
    await seedDeterministicJourney(page, `market-depth-${name}-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await installInvestorWorkflowApiMocks(page);
    const blocked = []; const writes = []; const diagnosticReports = createApiFailureDiagnosticCollector(page);
    await installApiFailureDiagnosticBoundary(page, { origin: 'http://127.0.0.1:4177', collector: diagnosticReports });
    const seal = () => page.route('**/*', async (route) => {
        const request = route.request(); const url = new URL(request.url()); const write = /^(POST|PUT|PATCH|DELETE)$/.test(request.method());
        if (await handleApiFailureDiagnostic(route, { origin: 'http://127.0.0.1:4177', collector: diagnosticReports })) return;
        if (url.origin !== 'http://127.0.0.1:4177' || (write && url.pathname !== '/api/logs/frontend')) {
            blocked.push(`${request.method()} ${url.pathname}`);
            return route.abort();
        }
        if (write && url.pathname !== '/api/logs/frontend') writes.push({ method: request.method(), path: url.pathname });
        return route.fallback();
    });
    return { blocked, writes, seal };
}
const failure = (route, message = 'Synthetic research unavailable.') => route.fulfill({ status: 503, json: { message } });
function deferredGate() {
    let start; let release;
    const started = new Promise((resolve) => { start = resolve; });
    const released = new Promise((resolve) => { release = resolve; });
    return { started, start, wait: () => released, release };
}
const syntheticBreadth = (date, pct) => ({
    available_dates: ['2026-10-01', '2026-09-30', '2026-09-29'],
    as_of_date: date,
    chart_history: [{ date: '2026-10-01', metrics: { rising: { pct: 50, pass: 5, scanned: 10 } } }],
    matrix: { as_of_date: date, columns: [{ key: 'rising', label: 'Rising' }], rows: [{ symbol: 'NIFTY50', name: 'Nifty 50', eligible: 10, pct_rising: pct, pass_rising: Math.round(pct / 10), scanned_rising: 10 }] },
});

for (const viewport of VIEWPORTS) {
    test.describe(`Market-depth read recovery ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });

        test(`E2E-37 retries failed breadth snapshots and preserves dated percent/count evidence without backfill (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-37'); const safety = await prepare(page, viewport, 'breadth'); let fail = true; const dates = [];
            await page.route('**/api/market-depth*', (route) => {
                const date = new URL(route.request().url()).searchParams.get('date') || '2026-10-01'; dates.push(date);
                if (fail) return failure(route);
                return route.fulfill({ json: {
                    available_dates: ['2026-10-01', '2026-09-30'], as_of_date: date, chart_history: [],
                    matrix: { as_of_date: date, columns: [{ key: 'rising', label: 'Rising' }], rows: [{ symbol: 'NIFTY50', name: 'Nifty 50', eligible: 10, pct_rising: date === '2026-10-01' ? 50 : null, pass_rising: date === '2026-10-01' ? 5 : null, scanned_rising: date === '2026-10-01' ? 10 : null }] },
                } });
            });
            await safety.seal(); await page.goto('/market-depth');
            await expect(page.getByRole('alert').filter({ hasText: 'Market breadth could not be loaded' })).toContainText('Market breadth could not be loaded');
            await expect(page.getByText(/Run cpanel-backfill/)).toHaveCount(0);
            const initialReads = dates.length; expect(initialReads).toBeGreaterThan(0); expect(initialReads).toBeLessThanOrEqual(2); expect(dates).toEqual(Array(initialReads).fill('2026-10-01'));
            fail = false; await page.getByRole('button', { name: 'Retry market breadth' }).click();
            await expect(page.getByLabel('Date', { exact: true })).toHaveValue('2026-10-01'); await expect(page.locator('.lido-market-depth-cell')).toHaveText('50%');
            await page.getByRole('group', { name: 'Value mode' }).getByRole('button', { name: 'Count', exact: true }).click(); await expect(page.locator('.lido-market-depth-cell')).toHaveText('5');
            await page.getByLabel('Date', { exact: true }).selectOption('2026-09-30'); await expect(page.locator('.lido-market-depth-cell')).toHaveText('—');
            await page.getByRole('group', { name: 'Value mode' }).getByRole('button', { name: '%', exact: true }).click(); await expect(page.locator('.lido-market-depth-cell')).toHaveText('—');
            expect(dates).toEqual([...Array(initialReads).fill('2026-10-01'), '2026-10-01', '2026-09-30']); expect(safety.writes).toHaveLength(0); expect(safety.blocked).toEqual([]);
        });

        test(`E2E-37 keeps the selected dated matrix when an older breadth read completes late (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-37'); const safety = await prepare(page, viewport, 'breadth-date-race'); const dates = []; const finishedDates = []; const older = deferredGate();
            page.on('requestfinished', (request) => { const url = new URL(request.url()); if (url.pathname === '/api/market-depth') finishedDates.push(url.searchParams.get('date') || '2026-10-01'); });
            await page.route('**/api/market-depth*', async (route) => {
                const date = new URL(route.request().url()).searchParams.get('date') || '2026-10-01'; dates.push(date);
                if (date === '2026-09-30') { older.start(); await older.wait(); return route.fulfill({ json: syntheticBreadth(date, 20) }); }
                return route.fulfill({ json: syntheticBreadth(date, date === '2026-10-01' ? 50 : 80) });
            });
            await safety.seal(); await page.goto('/market-depth'); await expect(page.locator('.lido-market-depth-cell')).toHaveText('50%');
            const initialReads = dates.length; expect(initialReads).toBeGreaterThan(0); expect(initialReads).toBeLessThanOrEqual(2); expect(dates).toEqual(Array(initialReads).fill('2026-10-01'));
            await expect.poll(() => finishedDates.filter((date) => date === '2026-10-01').length).toBe(initialReads);
            const oldResponsePromise = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/market-depth' && new URL(response.url()).searchParams.get('date') === '2026-09-30');
            await page.getByLabel('Date', { exact: true }).selectOption('2026-09-30'); await older.started;
            const currentResponsePromise = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/market-depth' && new URL(response.url()).searchParams.get('date') === '2026-09-29');
            await page.getByLabel('Date', { exact: true }).selectOption('2026-09-29'); const currentResponse = await currentResponsePromise; await currentResponse.finished(); await currentResponse.body();
            await expect(page.getByLabel('Date', { exact: true })).toHaveValue('2026-09-29'); await expect(page.locator('.lido-market-depth-cell')).toHaveText('80%');
            older.release(); const oldResponse = await oldResponsePromise; await oldResponse.finished(); await oldResponse.body();
            await expect(page.getByLabel('Date', { exact: true })).toHaveValue('2026-09-29'); await expect(page.locator('.lido-market-depth-cell')).toHaveText('80%'); await expect(page.getByText('Nifty 50 — last 1 snapshot (%)', { exact: true })).toBeVisible();
            expect(dates).toEqual([...Array(initialReads).fill('2026-10-01'), '2026-09-30', '2026-09-29']); await expect.poll(() => finishedDates.length).toBe(dates.length); expect(safety.writes).toEqual([]); expect(safety.blocked).toEqual([]);
        });
    });
}
