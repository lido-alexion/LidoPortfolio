import { createApiFailureDiagnosticCollector, installApiFailureDiagnosticBoundary, handleApiFailureDiagnostic, registerApiFailureDiagnosticAfterEach } from './apiFailureDiagnosticBoundary.js';

import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

registerApiFailureDiagnosticAfterEach(test);


const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
const INDEXES = [
    { stock_id: 501, symbol: 'NIFTY50', name: 'Nifty 50', exchange: 'NSE', tier: 'broad', has_price_history: true, price_from: '2026-09-01', price_to: '2026-10-01', price_count: 20, latest_close: 200, latest_price_date: '2026-10-01', change_percent: {}, constituents_available: true },
    { stock_id: 502, symbol: 'NIFTYBANK', name: 'Nifty Bank', exchange: 'NSE', tier: 'sector', has_price_history: false, price_from: null, price_to: null, price_count: 0, latest_close: null, latest_price_date: null, change_percent: {}, constituents_available: true },
    { stock_id: 503, symbol: 'INDIAVIX', name: 'India VIX', exchange: 'NSE', tier: 'volatility', has_price_history: true, price_from: '2026-09-01', price_to: '2026-10-01', price_count: 20, latest_close: 14, latest_price_date: '2026-10-01', change_percent: {}, constituents_available: false },
];
const EMPTY_HISTORY_INDEX = {
    ...INDEXES[0],
    has_price_history: false,
    price_from: null,
    price_to: null,
    price_count: 0,
    latest_close: null,
    latest_price_date: null,
};

async function prepare(page, viewport, seed) {
    await seedDeterministicJourney(page, `e2e36-index-${seed}-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await installInvestorWorkflowApiMocks(page);
    const blocked = [];
    const writes = [];
    const diagnosticReports = createApiFailureDiagnosticCollector(page);
    await installApiFailureDiagnosticBoundary(page, { origin: 'http://127.0.0.1:4177', collector: diagnosticReports });
    const seal = () => page.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const write = /^(POST|PUT|PATCH|DELETE)$/.test(request.method());
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

async function settleSuccessfulReads(requests) {
    expect(requests.length).toBeGreaterThan(0);
    await Promise.all(requests.map(async (request) => {
        const response = await request.response();
        expect(response).not.toBeNull();
        expect(response.status()).toBe(200);
        expect(await response.finished()).toBeNull();
    }));
}

for (const viewport of VIEWPORTS) {
    test.describe(`E2E-36 index research recovery ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });

        test(`distinguishes malformed overview/comparison from valid empty and keeps legend selection portfolio-scoped (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-36');
            const safety = await prepare(page, viewport, `overview-${viewport.name}`);
            let phase = 'malformed';
            const pageReads = [];
            const comparisonReads = [];
            const observed = { page: [], comparison: [] };
            page.on('request', (request) => {
                const url = new URL(request.url());
                if (url.origin !== 'http://127.0.0.1:4177' || request.method() !== 'GET') return;
                if (url.pathname === '/api/indexes/page') observed.page.push({ phase, request });
                if (url.pathname === '/api/indexes/comparison') observed.comparison.push({ phase, request });
            });
            await page.route('**/api/portfolios', (route) => route.fulfill({ json: { data: [
                { id: 1, name: 'Main', is_default: true },
                { id: 2, name: 'Research', is_default: false },
            ] } }));
            await page.route('**/api/indexes/page', (route) => {
                pageReads.push(phase);
                return route.fulfill({ json: phase === 'malformed'
                    ? { data: { primary_symbol: 'NIFTY50' } }
                    : { data: { primary_symbol: 'NIFTY50', indexes: INDEXES } } });
            });
            await page.route('**/api/indexes/comparison*', (route) => {
                const url = new URL(route.request().url());
                expect(url.searchParams.get('months')).toBe('12');
                comparisonReads.push(phase);
                return route.fulfill({ json: phase === 'malformed'
                    ? { data: { months: 12, baseline_date: null } }
                    : { data: { months: 12, baseline_date: '2026-09-01', series: [
                        { symbol: 'NIFTY50', name: 'Nifty 50', exchange: 'NSE', tier: 'broad', points: [
                            { date: '2026-09-01', gain_percent: 0 },
                            { date: '2026-10-01', gain_percent: 2 },
                        ] },
                    ] } } });
            });
            await safety.seal();
            await page.goto('/indices');
            await expect(page.getByRole('alert').filter({ hasText: 'Failed to load index data' })).toBeVisible();
            await expect(page.getByRole('button', { name: /Nifty 50 \(NIFTY50\)/ })).toHaveCount(0);
            const initialPage = observed.page.filter((item) => item.phase === 'malformed').map((item) => item.request);
            const initialComparison = observed.comparison.filter((item) => item.phase === 'malformed').map((item) => item.request);
            await settleSuccessfulReads(initialPage);
            await settleSuccessfulReads(initialComparison);
            expect(initialPage.length).toBe(initialComparison.length);
            expect(pageReads.every((read) => read === 'malformed')).toBe(true);
            expect(comparisonReads.every((read) => read === 'malformed')).toBe(true);

            const pageBaseline = pageReads.length;
            const comparisonBaseline = comparisonReads.length;
            phase = 'valid';
            await page.getByRole('button', { name: 'Retry index data' }).click();
            await expect.poll(() => pageReads.length).toBe(pageBaseline + 1);
            await expect.poll(() => comparisonReads.length).toBe(comparisonBaseline + 1);
            await settleSuccessfulReads(observed.page.filter((item) => item.phase === 'valid').map((item) => item.request));
            await settleSuccessfulReads(observed.comparison.filter((item) => item.phase === 'valid').map((item) => item.request));
            const broad = page.getByRole('group', { name: 'Broad market' });
            const sectors = page.getByRole('group', { name: 'Sector' });
            const nifty = broad.getByRole('checkbox', { name: 'Nifty 50' });
            const bank = sectors.getByRole('checkbox', { name: 'Nifty Bank' });
            await expect(nifty).toBeChecked();
            await expect(bank).toBeChecked();
            await expect(page.getByRole('checkbox', { name: /India VIX/ })).toHaveCount(0);
            const comparisonCanvas = page.locator('.indices-comparison-chart-canvas');
            await expect.poll(() => comparisonCanvas.locator('.recharts-line-curve').count()).toBe(1);
            await expect(comparisonCanvas.locator('.recharts-line-curve').first()).toHaveAttribute('d', /\S+/);

            const switcher = page.locator('.lido-portfolio-switcher');
            await expect(switcher).toContainText('Main');
            await nifty.uncheck();
            await switcher.click();
            await page.getByRole('button', { name: 'Research', exact: true }).click();
            await expect(switcher).toContainText('Research');
            await expect(broad.getByRole('checkbox', { name: 'Nifty 50' })).toBeChecked();
            await sectors.getByRole('checkbox', { name: 'Nifty Bank' }).uncheck();
            await switcher.click();
            await page.getByRole('button', { name: 'Main', exact: true }).click();
            await expect(switcher).toContainText('Main');
            await expect(broad.getByRole('checkbox', { name: 'Nifty 50' })).not.toBeChecked();
            await expect(sectors.getByRole('checkbox', { name: 'Nifty Bank' })).toBeChecked();

            expect(pageReads).toHaveLength(pageBaseline + 1);
            expect(comparisonReads).toHaveLength(comparisonBaseline + 1);
            expect(safety.writes).toEqual([]);
            expect(safety.blocked).toEqual([]);
        });

        test(`retries malformed history and HTTP-200 constituent-source failure, preserving confirmed empty history (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-36');
            const safety = await prepare(page, viewport, `subresource-${viewport.name}`);
            let historyPhase = 'malformed';
            let constituentsPhase = 'unavailable';
            const historyRequests = [];
            const constituentRequests = [];
            const observedHistory = [];
            const observedConstituents = [];
            const observedOverview = [];
            const observedComparison = [];
            page.on('request', (request) => {
                const url = new URL(request.url());
                if (url.origin !== 'http://127.0.0.1:4177' || request.method() !== 'GET') return;
                if (url.pathname === '/api/indexes/page') observedOverview.push(request);
                if (url.pathname === '/api/indexes/comparison') observedComparison.push(request);
                if (url.pathname === '/api/stocks/501/market-prices') observedHistory.push({ phase: historyPhase, request });
                if (url.pathname === '/api/indexes/NIFTY50/constituents') observedConstituents.push({ phase: constituentsPhase, request });
            });
            await page.route('**/api/indexes/page', (route) => route.fulfill({ json: { data: { primary_symbol: 'NIFTY50', indexes: [EMPTY_HISTORY_INDEX] } } }));
            await page.route('**/api/indexes/comparison*', (route) => {
                expect(new URL(route.request().url()).searchParams.get('months')).toBe('12');
                return route.fulfill({ json: { data: { months: 12, baseline_date: null, series: [] } } });
            });
            await page.route('**/api/stocks/501/market-prices', (route) => {
                historyRequests.push(historyPhase);
                return route.fulfill({ json: historyPhase === 'malformed'
                    ? { stock: { id: 501, symbol: 'NIFTY50' } }
                    : { stock: { id: 501, symbol: 'NIFTY50' }, data: [], has_price_history: false, price_count: 0 } });
            });
            await page.route('**/api/indexes/NIFTY50/constituents', (route) => {
                constituentRequests.push(constituentsPhase);
                return route.fulfill({ json: { data: {
                    symbol: 'NIFTY50',
                    name: 'Nifty 50',
                    available: true,
                    constituents: constituentsPhase === 'unavailable'
                        ? []
                        : [{ symbol: 'TCS', name: 'Tata Consultancy Services', stock_id: 42 }],
                    message: constituentsPhase === 'unavailable'
                        ? 'Could not load constituents from NSE right now. Cached list is empty — try again later.'
                        : null,
                } } });
            });
            await safety.seal();
            await page.goto('/indices');
            await expect(page.locator('.indices-page').getByRole('heading', { name: 'Indices', exact: true })).toBeVisible();
            await page.getByRole('button', { name: /^Nifty 50 \(NIFTY50\)/ }).click();
            const panel = page.getByRole('region', { name: /^Nifty 50 \(NIFTY50\)/ });
            await expect(panel.getByRole('alert').filter({ hasText: 'Index history could not be loaded' })).toBeVisible();
            await expect(panel.getByRole('alert').filter({ hasText: 'Could not load constituents from NSE right now' })).toBeVisible();
            await settleSuccessfulReads(observedOverview);
            await settleSuccessfulReads(observedComparison);
            const firstHistory = observedHistory.filter((item) => item.phase === 'malformed').map((item) => item.request);
            const firstConstituents = observedConstituents.filter((item) => item.phase === 'unavailable').map((item) => item.request);
            await settleSuccessfulReads(firstHistory);
            await settleSuccessfulReads(firstConstituents);
            const historyBaseline = historyRequests.length;
            const constituentsBaseline = constituentRequests.length;
            expect(historyBaseline).toBeGreaterThan(0);
            expect(constituentsBaseline).toBeGreaterThan(0);

            historyPhase = 'confirmed-empty';
            constituentsPhase = 'confirmed-rows';
            await panel.getByRole('button', { name: 'Retry index history' }).click();
            await panel.getByRole('button', { name: 'Retry index constituents' }).click();
            await expect.poll(() => historyRequests.length).toBe(historyBaseline + 1);
            await expect.poll(() => constituentRequests.length).toBe(constituentsBaseline + 1);
            await settleSuccessfulReads(observedHistory.filter((item) => item.phase === 'confirmed-empty').map((item) => item.request));
            await settleSuccessfulReads(observedConstituents.filter((item) => item.phase === 'confirmed-rows').map((item) => item.request));
            await expect(panel).toContainText('No price history in cache.');
            await expect(panel.getByRole('link', { name: 'TCS', exact: true })).toBeVisible();
            await expect(panel.getByRole('button', { name: 'Retry index history' })).toHaveCount(0);
            await expect(panel.getByRole('button', { name: 'Retry index constituents' })).toHaveCount(0);
            expect(historyRequests).toHaveLength(historyBaseline + 1);
            expect(constituentRequests).toHaveLength(constituentsBaseline + 1);
            expect(historyRequests.every((phase) => ['malformed', 'confirmed-empty'].includes(phase))).toBe(true);
            expect(constituentRequests.every((phase) => ['unavailable', 'confirmed-rows'].includes(phase))).toBe(true);
            expect(safety.writes).toEqual([]);
            expect(safety.blocked).toEqual([]);
        });
    });
}
