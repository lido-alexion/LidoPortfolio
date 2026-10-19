import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];
const STOCK = { id: 42, symbol: 'TCS', name: 'Tata Consultancy Services', exchange: 'NSE' };
const INSIGHT_PATH = '/api/ai/insights/stocks/42';
const EXPLORER_ANALYSIS_PATH = '/api/analytics/explore';
const EXPLORER_ANALYSIS_PAYLOAD = {
    symbol: 'TCS',
    exchange: 'NSE',
    benchmark_symbol: 'NIFTY50',
    periods: [1, 3, 6, 12],
};
const EXPLORER_MANUAL_FALLBACK_ERROR = {
    message: 'Symbol TCS (NSE) was not found. Choose a symbol from search or check the ticker spelling. You can still enter prices manually for RS.',
    errors: ['NSE returned no company name.'],
};
const INSIGHT = {
    status: 'ready',
    fingerprint: 'a'.repeat(64),
    response: {
        summary: 'The fixture contains a bounded company summary.',
        technical_price_context: 'Price context is fixture evidence only.',
        fundamental_context: 'Fundamental context is available for review.',
        positive_signals: 'No additional positive signal is asserted.',
        risks_watch_items: 'One review item remains open.',
        what_to_check_next: 'Review the next reported period.',
        data_limitations: 'This fixture is not live market evidence.',
    },
    data_as_of: { ohlcv_through: '2026-09-30', fundamentals_period: '2026-06-30' },
};
const INSIGHT_SECTIONS = [
    'Summary',
    'Technical / price context',
    'Fundamental context',
    'Positive signals',
    'Risks / watch items',
    'What to check next',
    'Data limitations',
];
const WATCHLIST = { id: 5, name: 'Research list', item_count: 1 };
const WATCHLIST_ITEM = {
    id: 51,
    watchlist_id: WATCHLIST.id,
    stock_id: STOCK.id,
    stock: STOCK,
    latest_close: 3500,
    daily_change: 10,
    daily_change_percent: 0.29,
    note: null,
};

function insightResponse(response) {
    const url = new URL(response.url());
    return url.pathname === INSIGHT_PATH && response.request().method() === 'POST';
}

async function settleInsightPhase(requests, startIndex = 0) {
    expect(requests.length - startIndex).toBeGreaterThan(0);
    const settled = new Set();
    const outcomes = [];
    while (settled.size < requests.length - startIndex) {
        const pending = requests.slice(startIndex).filter((request) => !settled.has(request));
        const batch = await Promise.all(pending.map(async (request) => {
            expect(request.method()).toBe('POST');
            expect(new URL(request.url()).pathname).toBe(INSIGHT_PATH);
            expect(request.postDataJSON()).toEqual({ refresh: false });
            const response = await request.response();
            if (!response) {
                expect(request.failure()?.errorText).toBe('net::ERR_ABORTED');
                return { request, kind: 'aborted' };
            }
            expect(response.status()).toBe(200);
            expect(await response.finished()).toBeNull();
            const body = await response.json();
            expect(body).toEqual({ data: INSIGHT });
            return { request, kind: 'completed', body };
        }));
        for (const outcome of batch) {
            settled.add(outcome.request);
            outcomes.push(outcome);
        }
    }
    expect(outcomes.some((outcome) => outcome.kind === 'completed')).toBe(true);
    return outcomes;
}

async function settleAnalysisPhase(requests, status, expectedBody) {
    expect(requests.length).toBeGreaterThan(0);
    const settled = new Set();
    const outcomes = [];
    while (settled.size < requests.length) {
        const pending = requests.filter((request) => !settled.has(request));
        const batch = await Promise.all(pending.map(async (request) => {
            expect(request.method()).toBe('POST');
            expect(new URL(request.url()).pathname).toBe(EXPLORER_ANALYSIS_PATH);
            expect(request.postDataJSON()).toEqual(EXPLORER_ANALYSIS_PAYLOAD);
            const response = await request.response();
            if (!response) {
                expect(request.failure()?.errorText).toBe('net::ERR_ABORTED');
                return { request, kind: 'aborted' };
            }
            expect(response.status()).toBe(status);
            expect(await response.finished()).toBeNull();
            expect(await response.json()).toEqual(expectedBody);
            return { request, kind: 'completed' };
        }));
        for (const outcome of batch) {
            settled.add(outcome.request);
            outcomes.push(outcome);
        }
    }
    expect(outcomes.some((outcome) => outcome.kind === 'completed')).toBe(true);
    return outcomes;
}

async function assertRenderedInsight(surface) {
    await expect(surface).toBeVisible();
    for (const heading of INSIGHT_SECTIONS) {
        await expect(surface.getByRole('heading', { name: heading, exact: true })).toBeVisible();
    }
    await expect(surface.getByText('The fixture contains a bounded company summary.', { exact: true })).toBeVisible();
    const provenance = surface.getByLabel('Based on / Data used');
    await expect(provenance.getByText('OHLCV through 2026-09-30', { exact: true })).toBeVisible();
    await expect(provenance.getByText('Fundamentals through 2026-06-30', { exact: true })).toBeVisible();
    await expect(surface.getByRole('button', { name: 'Copy insight', exact: true })).toBeVisible();
    await expect(surface.getByRole('button', { name: 'Refresh insight', exact: true })).toBeEnabled();
    await expect(surface.getByRole('link', { name: 'Open stock details', exact: true })).toBeVisible();
    await expect(surface.getByRole('button', { name: 'Copy AI Prompt', exact: true })).toHaveCount(0);
}

async function prepare(page, viewport, dashboardKind = null, setupPageRoutes = async () => {}, allowExplorerAnalysis = false) {
    await seedDeterministicJourney(page, `ai07-placement-${dashboardKind || 'stock-pages'}-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await installInvestorWorkflowApiMocks(page);

    if (dashboardKind) {
        const alerts = dashboardKind === 'alerts' ? [{
            id: 501,
            stock_id: STOCK.id,
            stock: STOCK,
            created_at: '2026-09-30T10:00:00Z',
            message: 'Synthetic alert for placement coverage.',
            condition_display: 'Fixture threshold',
            action_suggested: 'Review',
            context_json: {},
        }] : [];
        const patternResults = dashboardKind === 'patterns' ? [{
            stock_id: STOCK.id,
            symbol: STOCK.symbol,
            matches: [{
                id: 'fixture-pattern-1',
                name: 'Synthetic base pattern',
                category: 'actionable',
                bar_date: '2026-09-30',
                variant: 'breakout',
            }],
        }] : [];
        await page.route('**/api/dashboard', (route) => route.fulfill({ json: {
            portfolio_value: 7000,
            invested_value: 6000,
            total_gain_loss: 1000,
            portfolio_growth: [],
            alerts,
        } }));
        await page.route('**/api/patterns/scan', (route) => route.fulfill({ json: { results: patternResults } }));
    }

    if (dashboardKind === 'watchlist') {
        await page.route('**/api/watchlists', (route) => route.fulfill({ json: { data: [WATCHLIST] } }));
        await page.route('**/api/watchlists/5/items', (route) => route.fulfill({ json: { data: [WATCHLIST_ITEM] } }));
        await page.route('**/api/watchlist/membership**', (route) => route.fulfill({ json: { data: { watchlist_ids: [WATCHLIST.id] } } }));
    }

    await page.route(`**${INSIGHT_PATH}`, async (route) => {
        const request = route.request();
        expect(request.method()).toBe('POST');
        expect(request.postDataJSON()).toEqual({ refresh: false });
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: INSIGHT }) });
    });
    await setupPageRoutes();

    const observedRequests = [];
    const insightRequests = [];
    const analysisRequests = [];
    page.on('request', (request) => {
        const url = new URL(request.url());
        if (url.protocol === 'http:' || url.protocol === 'https:') {
            observedRequests.push({ request, origin: url.origin, pathname: url.pathname });
            if (url.pathname === INSIGHT_PATH && request.method() === 'POST') insightRequests.push(request);
            if (url.pathname === EXPLORER_ANALYSIS_PATH && request.method() === 'POST') analysisRequests.push(request);
        }
    });
    const allowedOrigin = new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin;
    await page.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return route.fallback();
        if (url.origin !== allowedOrigin) return route.abort();
        if (/^(POST|PUT|PATCH|DELETE)$/.test(request.method())
            && !(request.method() === 'POST' && url.pathname === INSIGHT_PATH)
            && !(allowExplorerAnalysis && request.method() === 'POST' && url.pathname === EXPLORER_ANALYSIS_PATH)) {
            return route.abort();
        }
        return route.fallback();
    });
    return {
        insightRequests,
        analysisRequests,
        assertRequestBoundary({ expectExplorerAnalysis = false } = {}) {
            expect(observedRequests.length).toBeGreaterThan(0);
            expect(observedRequests.every(({ origin }) => origin === allowedOrigin)).toBe(true);
            const writes = observedRequests.filter(({ request }) => /^(POST|PUT|PATCH|DELETE)$/.test(request.method()));
            const insightPosts = writes.filter(({ pathname }) => pathname === INSIGHT_PATH);
            const explorerAnalysisPosts = writes.filter(({ pathname }) => pathname === EXPLORER_ANALYSIS_PATH);
            expect(insightPosts.length).toBeGreaterThan(0);
            expect(insightPosts.map(({ request }) => request.postDataJSON()))
                .toEqual(insightPosts.map(() => ({ refresh: false })));
            // The browser fixture permits the user's explicit Stock Explorer calculation
            // separately from insight generation. Interception proves request shape only;
            // it does not establish whether the server-side analysis has effects.
            expect(explorerAnalysisPosts.length > 0).toBe(expectExplorerAnalysis);
            expect(explorerAnalysisPosts.map(({ request }) => request.postDataJSON()))
                .toEqual(explorerAnalysisPosts.map(() => EXPLORER_ANALYSIS_PAYLOAD));
            expect(writes.every(({ request, pathname }) => request.method() === 'POST'
                && (pathname === INSIGHT_PATH || pathname === EXPLORER_ANALYSIS_PATH))).toBe(true);
        },
    };
}

async function openInsight(page, button, requests) {
    const start = requests.length;
    const responsePromise = page.waitForResponse(insightResponse);
    await button.click();
    await responsePromise;
    await settleInsightPhase(requests, start);
}

async function assertSurfacePlacement(page, presentation, sourceButton) {
    const surface = presentation === 'inline'
        ? page.getByRole('region', { name: 'AI Insights — TCS', exact: true })
        : page.getByRole('dialog', { name: 'AI Insights — TCS', exact: true });
    await assertRenderedInsight(surface);
    await surface.getByRole('button', { name: 'Close AI Insights', exact: true }).click();
    await expect(surface).toHaveCount(0);
    if (presentation === 'modal') {
        await expect(sourceButton).toBeFocused();
    }
    return surface;
}

for (const viewport of VIEWPORTS) {
    test.describe(`AI-07 stock placements ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });

        for (const dashboardKind of ['alerts', 'patterns']) {
            test(`opens the ${dashboardKind} table insight for its TCS row on the dashboard`, async ({ page }, testInfo) => {
                journeyId(testInfo, 'AI-07');
                const harness = await prepare(page, viewport, dashboardKind);
                await page.goto('/');
                const row = page.getByRole('row').filter({ hasText: STOCK.symbol });
                await expect(row).toHaveCount(1);
                const button = row.getByRole('button', { name: 'Open AI Insights', exact: true });
                await expect(button).toBeVisible();
                expect(harness.insightRequests).toHaveLength(0);
                await openInsight(page, button, harness.insightRequests);
                await assertSurfacePlacement(page, 'modal', button);
                await expect(row).toBeVisible();
                harness.assertRequestBoundary();
            });
        }

        test('distinguishes the watchlist row action from the selected-stock inline action', async ({ page }, testInfo) => {
            journeyId(testInfo, 'AI-07');
            const harness = await prepare(page, viewport, 'watchlist');
            await page.goto('/watchlist');
            const row = page.locator('li.lido-watchlist-row').filter({ hasText: STOCK.symbol });
            await expect(row).toHaveCount(1);
            const rowAction = row.getByRole('button', { name: 'Open AI Insights', exact: true });
            expect(harness.insightRequests).toHaveLength(0);
            await openInsight(page, rowAction, harness.insightRequests);
            await assertSurfacePlacement(page, 'modal', rowAction);
            await expect(row).toBeVisible();

            const requestsBeforeSelection = harness.insightRequests.length;
            await row.locator('button.lido-watchlist-row-main').click();
            const selectedStockHeading = page.locator('.h5.lido-stock-symbol-with-analyse').filter({ hasText: STOCK.symbol });
            await expect(selectedStockHeading).toHaveCount(1);
            const selectedAction = selectedStockHeading.getByRole('button', { name: 'Open AI Insights', exact: true });
            expect(harness.insightRequests).toHaveLength(requestsBeforeSelection);
            await openInsight(page, selectedAction, harness.insightRequests);
            await assertSurfacePlacement(page, 'inline');
            await expect(selectedStockHeading).toBeVisible();
            harness.assertRequestBoundary();
        });

        test('opens the normal Stock Explorer result inline for the selected stock', async ({ page }, testInfo) => {
            journeyId(testInfo, 'AI-07');
            const noHistoryStatus = {
                success: false,
                cache_hit: false,
                stored_rows: 0,
                fetched_rows: 0,
                ranges_fetched: [],
                errors: ['Insufficient cached price history. Universe price sync may not have covered this symbol yet.'],
            };
            const expectedResult = { data: {
                valid: true,
                stock: STOCK,
                benchmark: { symbol: 'NIFTY50', name: 'Nifty 50', latest_close: null },
                tracking: { is_portfolio_tracked: false, is_exploratory: true },
                latest_close: null,
                as_of: '2026-10-19',
                growth_percent: { '1m': null, '3m': null, '6m': null, '12m': null },
                benchmark_growth_percent: { '1m': null, '3m': null, '6m': null, '12m': null },
                relative_strength: { '1m': null, '3m': null, '6m': null, '12m': null },
                period_closes: {
                    '1m': { stock_start_close: null, stock_end_close: null, benchmark_start_close: null, benchmark_end_close: null, start_date: '2026-09-19', end_date: '2026-10-19' },
                    '3m': { stock_start_close: null, stock_end_close: null, benchmark_start_close: null, benchmark_end_close: null, start_date: '2026-07-19', end_date: '2026-10-19' },
                    '6m': { stock_start_close: null, stock_end_close: null, benchmark_start_close: null, benchmark_end_close: null, start_date: '2026-04-19', end_date: '2026-10-19' },
                    '12m': { stock_start_close: null, stock_end_close: null, benchmark_start_close: null, benchmark_end_close: null, start_date: '2025-10-19', end_date: '2026-10-19' },
                },
                history: { available: null, benchmark_available: null, stock_fetch: noHistoryStatus, benchmark_fetch: noHistoryStatus },
                chart: [
                    { period: '1M', growth_percent: 0, benchmark_growth_percent: 0, relative_strength: 0 },
                    { period: '3M', growth_percent: 0, benchmark_growth_percent: 0, relative_strength: 0 },
                    { period: '6M', growth_percent: 0, benchmark_growth_percent: 0, relative_strength: 0 },
                    { period: '1Y', growth_percent: 0, benchmark_growth_percent: 0, relative_strength: 0 },
                ],
                normalized_gain_chart: [],
            } };
            const harness = await prepare(page, viewport, null, async () => {
                await page.route(`**${EXPLORER_ANALYSIS_PATH}`, async (route) => {
                    expect(route.request().method()).toBe('POST');
                    expect(route.request().postDataJSON()).toEqual(EXPLORER_ANALYSIS_PAYLOAD);
                    await route.fulfill({ json: expectedResult });
                });
            }, true);
            await page.goto('/explorer');
            const stockInput = page.getByRole('textbox', { name: 'Stock', exact: true });
            await stockInput.fill(STOCK.symbol);
            const suggestion = page.getByRole('button', { name: /TCS.*NSE.*Tata Consultancy Services/ });
            await expect(suggestion).toBeVisible();
            await suggestion.click();
            const analysisResponsePromise = page.waitForResponse((response) => {
                const url = new URL(response.url());
                return url.pathname === EXPLORER_ANALYSIS_PATH && response.request().method() === 'POST';
            });
            await page.getByRole('button', { name: 'Calculate relative strength', exact: true }).click();
            await analysisResponsePromise;
            await settleAnalysisPhase(harness.analysisRequests, 200, expectedResult);
            const context = page.locator('[data-ai-insight-context]');
            await expect(context.getByText('Latest close', { exact: true })).toBeVisible();
            const button = context.getByRole('button', { name: 'Open AI Insights', exact: true });
            await expect(button).toBeVisible();
            expect(harness.analysisRequests.length).toBeGreaterThan(0);
            expect(harness.insightRequests).toHaveLength(0);
            await openInsight(page, button, harness.insightRequests);
            await assertSurfacePlacement(page, 'inline', button);
            await expect(context.getByText('Latest close', { exact: true })).toBeVisible();
            harness.assertRequestBoundary({ expectExplorerAnalysis: true });
        });

        test('opens the manual-fallback Stock Explorer result inline for the selected stock', async ({ page }, testInfo) => {
            journeyId(testInfo, 'AI-07');
            const harness = await prepare(page, viewport, null, async () => {
                await page.route(`**${EXPLORER_ANALYSIS_PATH}`, async (route) => {
                    expect(route.request().method()).toBe('POST');
                    expect(route.request().postDataJSON()).toEqual(EXPLORER_ANALYSIS_PAYLOAD);
                    await route.fulfill({ status: 422, json: EXPLORER_MANUAL_FALLBACK_ERROR });
                });
            }, true);
            await page.goto('/explorer');
            const stockInput = page.getByRole('textbox', { name: 'Stock', exact: true });
            await stockInput.fill(STOCK.symbol);
            await page.getByRole('button', { name: /TCS.*NSE.*Tata Consultancy Services/ }).click();
            const analysisResponsePromise = page.waitForResponse((response) => {
                const url = new URL(response.url());
                return url.pathname === EXPLORER_ANALYSIS_PATH && response.request().method() === 'POST';
            });
            await page.getByRole('button', { name: 'Calculate relative strength', exact: true }).click();
            await analysisResponsePromise;
            await settleAnalysisPhase(harness.analysisRequests, 422, EXPLORER_MANUAL_FALLBACK_ERROR);
            await expect(page.getByText('Analysis data is unavailable for this symbol.', { exact: false })).toBeVisible();
            expect(harness.analysisRequests.length).toBeGreaterThan(0);
            await page.getByLabel(/TCS latest close/).fill('3500');
            await page.getByLabel(/NIFTY50 latest close/).fill('24000');
            await page.getByLabel(/TCS close 6 months ago/).fill('3400');
            await page.getByLabel(/NIFTY50 close 6 months ago/).fill('23500');
            await page.getByRole('button', { name: 'Calculate RS from manual values', exact: true }).click();
            const context = page.locator('[data-ai-insight-context]');
            await expect(context.getByText('Latest close', { exact: true })).toBeVisible();
            const button = context.getByRole('button', { name: 'Open AI Insights', exact: true });
            await expect(button).toBeVisible();
            expect(harness.insightRequests).toHaveLength(0);
            await openInsight(page, button, harness.insightRequests);
            await assertSurfacePlacement(page, 'inline', button);
            await expect(context.getByText('Latest close', { exact: true })).toBeVisible();
            harness.assertRequestBoundary({ expectExplorerAnalysis: true });
        });
    });
}
