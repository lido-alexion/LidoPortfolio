import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { installApiFailureDiagnosticBoundaryForPage, isApiFailureDiagnosticRequest, registerApiFailureDiagnosticFixtureHooks } from './apiFailureDiagnosticBoundary.js';

registerApiFailureDiagnosticFixtureHooks(test);

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];
const INSIGHT_PATH = '/api/ai/insights/stocks/42';
const MARKET_PATH = '/api/stocks/42/market-prices';
const STOCK = { id: 42, symbol: 'TCS', name: 'Tata Consultancy Services', exchange: 'NSE' };
const HOLDING = {
    id: 1,
    stock_id: 42,
    stock: STOCK,
    quantity: 2,
    avg_buy_price: 3000,
    invested_amount: 6000,
    is_unmanaged: true,
    summary: { latest_close: 3500, market_value: 7000, invested_amount: 6000, unrealized_profit: 1000, quantity: 2, first_buy_date: '2026-09-01' },
};
const CACHED_INSIGHT = {
    status: 'ready',
    fingerprint: 'c'.repeat(64),
    response: {
        summary: 'Cached company evidence remains available.',
        technical_price_context: 'Recent price evidence is limited.',
        fundamental_context: 'No recent fundamentals were available.',
        positive_signals: 'No additional signal asserted.',
        risks_watch_items: 'Price history is incomplete.',
        what_to_check_next: 'Review the next company filing.',
        data_limitations: 'Two expected prices are unavailable.',
    },
    data_as_of: { ohlcv_through: '2026-09-09', fundamentals_period: '2026-06-30', holding_personalized: true },
};
const NEWEST_FIRST_ROWS = [
    { price_date: '2026-09-09', open_price: 107.4, high_price: 109.5, low_price: 106.9, close_price: 108.8, volume: 7890 },
    { price_date: '2026-09-08', open_price: 106.8, high_price: 108.3, low_price: 106.2, close_price: 107.4, volume: 6789 },
    { price_date: '2026-09-07', open_price: 105.2, high_price: null, low_price: 104.6, close_price: 106.8, volume: 5678 },
    { price_date: '2026-09-04', open_price: 103.5, high_price: 106, low_price: 103, close_price: 105.2, volume: 4567 },
    { price_date: '2026-09-03', open_price: 102.8, high_price: 104.4, low_price: 101.9, close_price: 103.5, volume: 3456 },
    { price_date: '2026-09-02', open_price: 101, high_price: 103.2, low_price: 100.7, close_price: 102.8, volume: 2345 },
    { price_date: '2026-09-01', open_price: 100, high_price: 102.5, low_price: 99.1, close_price: 101, volume: 1200 },
    { price_date: '2026-08-31', open_price: 999.99, high_price: 999.99, low_price: 999.99, close_price: 999.99, volume: 999999 },
];
const MARKET_HISTORY = {
    stock: STOCK,
    data: NEWEST_FIRST_ROWS,
    price_count: NEWEST_FIRST_ROWS.length,
    has_price_history: true,
    from_date: '2026-08-31',
    to_date: '2026-09-09',
    latest_close: 108.8,
    latest_price_date: '2026-09-09',
};
const EXPECTED_OHLCV = '[2026-09-01: O=100.00 H=102.50 L=99.10 C=101.00 V=1,200; 2026-09-02: O=101.00 H=103.20 L=100.70 C=102.80 V=2,345; 2026-09-03: O=102.80 H=104.40 L=101.90 C=103.50 V=3,456; 2026-09-04: O=103.50 H=106.00 L=103.00 C=105.20 V=4,567; 2026-09-07: O=105.20 H=— L=104.60 C=106.80 V=5,678; 2026-09-08: O=106.80 H=108.30 L=106.20 C=107.40 V=6,789; 2026-09-09: O=107.40 H=109.50 L=106.90 C=108.80 V=7,890]';
const SECTION_HEADINGS = [
    '1. Current Price & Fundamentals:',
    '2. Shareholding Shift & Smart Money Tracking:',
    '3. Revenue Visibility & Order Book:',
    '4. Operational Efficiency & Governance Risks:',
    '5. Mid-Term Technical Outlook (6 Months - 1 Year):',
    '6. Short-Term Technical Outlook:',
    '7. 7-Day OHLC & Volume Pattern Analysis:',
    '8. Balanced Investment Thesis Summary:',
];
const AI_ERROR = { success: false, error: { code: 'AI_UNAVAILABLE' } };
const MARKET_ERROR = { message: 'Market-price history is temporarily unavailable.' };

function isApiResponse(response, method, origin, pathname) {
    const request = response.request();
    const url = new URL(response.url());
    return request.method() === method && url.origin === origin && url.pathname === pathname;
}

async function settleResponse(response, status, expectedBody) {
    expect(response.status()).toBe(status);
    expect(await response.finished()).toBeNull();
    expect(await response.json()).toEqual(expectedBody);
}

async function settleObservedInsightPosts(requests) {
    const outcomes = await Promise.all(requests.map(async (request) => {
        const response = await request.response();
        if (!response) {
            expect(request.failure()?.errorText).toBe('net::ERR_ABORTED');
            return { kind: 'aborted' };
        }
        expect(await response.finished()).toBeNull();
        return { kind: 'response', status: response.status(), body: await response.json() };
    }));
    return outcomes;
}

function expectPrompt(prompt) {
    expect(prompt).toContain('Please provide a comprehensive stock analysis for Tata Consultancy Services (TCS).');
    for (const heading of SECTION_HEADINGS) expect(prompt).toContain(heading);
    expect(prompt).toContain(`${SECTION_HEADINGS[6]} ${EXPECTED_OHLCV}. Analyze this specific multi-day data block`);
    expect(prompt).not.toContain('2026-08-31');
    expect(prompt).not.toContain('999.99');
}

async function installClipboardBoundary(page) {
    await page.addInitScript(() => {
        window.__stoxClipboardWrites = [];
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: async text => { window.__stoxClipboardWrites.push(text); } },
        });
    });
}

async function installHoldingsRoutes(page) {
    await installInvestorWorkflowApiMocks(page);
    await page.route('**/api/holdings', async route => {
        expect(route.request().method()).toBe('GET');
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [HOLDING] }) });
    });
    await page.route('**/api/stocks/42/prices**', async route => {
        expect(route.request().method()).toBe('GET');
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ stock: STOCK, data: [], price_count: 0, has_price_history: false }) });
    });
    await installApiFailureDiagnosticBoundaryForPage(page);
}

async function installInsightRoute(page, mode, observedPayloads) {
    await page.route(`**${INSIGHT_PATH}`, async route => {
        const request = route.request();
        expect(request.method()).toBe('POST');
        const payload = request.postDataJSON();
        expect(payload).toEqual({ refresh: expect.any(Boolean) });
        observedPayloads.push(payload);

        const unavailable = (mode === 'no-cache' && payload.refresh === false) || payload.refresh === true;
        await route.fulfill({
            status: unavailable ? 503 : 200,
            contentType: 'application/json',
            body: JSON.stringify(unavailable ? AI_ERROR : { data: CACHED_INSIGHT }),
        });
    });
}

function observedPosts(observedRequests, refresh) {
    return observedRequests
        .filter(({ request, pathname }) => request.method() === 'POST' && pathname === INSIGHT_PATH)
        .filter(({ request }) => request.postDataJSON().refresh === refresh)
        .map(({ request }) => request);
}

async function assertOnlyAllowedHttpAndMutations(observedRequests, allowedOrigin, expectedRefreshCount) {
    const http = observedRequests.filter(({ protocol }) => protocol === 'http:' || protocol === 'https:');
    expect(http.length).toBeGreaterThan(0);
    expect(http.every(({ origin }) => origin === allowedOrigin)).toBe(true);
    const mutations = http.filter(({ request }) => /^(POST|PUT|PATCH|DELETE)$/.test(request.method())
        && !isApiFailureDiagnosticRequest(request, allowedOrigin));
    expect(mutations.length).toBeGreaterThan(0);
    expect(mutations.every(({ request, pathname }) => request.method() === 'POST' && pathname === INSIGHT_PATH)).toBe(true);
    const payloads = mutations.map(({ request }) => request.postDataJSON());
    expect(payloads.every(payload => payload.refresh === false || payload.refresh === true)).toBe(true);
    expect(payloads.filter(payload => payload.refresh === false).length).toBeGreaterThan(0);
    expect(payloads.filter(payload => payload.refresh === true)).toHaveLength(expectedRefreshCount);
}

for (const viewport of VIEWPORTS) {
    test(`AI-08 preserves a cached insight and copies the current stock prompt after market-read recovery (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AI-08');
        await seedDeterministicJourney(page, `ai08-cached-prompt-recovery-${viewport.name}`);
        await installClipboardBoundary(page);
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await installHoldingsRoutes(page);

        const allowedOrigin = new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin;
        const observedRequests = [];
        const aiPayloads = [];
        let marketReadCount = 0;
        page.on('request', request => {
            const url = new URL(request.url());
            observedRequests.push({ request, protocol: url.protocol, origin: url.origin, pathname: url.pathname });
        });
        await installInsightRoute(page, 'cached', aiPayloads);
        await page.route(`**${MARKET_PATH}`, async route => {
            const request = route.request();
            expect(request.method()).toBe('GET');
            expect(new URL(request.url()).origin).toBe(allowedOrigin);
            marketReadCount += 1;
            const failed = marketReadCount === 1;
            expect(marketReadCount).toBeLessThanOrEqual(2);
            await route.fulfill({
                status: failed ? 503 : 200,
                contentType: 'application/json',
                body: JSON.stringify(failed ? MARKET_ERROR : MARKET_HISTORY),
            });
        });

        await page.goto('/holdings');
        const row = page.getByRole('row').filter({ hasText: 'TCS' });
        await expect(row).toHaveCount(1);
        const openInsight = row.getByRole('button', { name: 'Open AI Insights', exact: true });
        await expect(openInsight).toBeVisible();
        const initialResponsePromise = page.waitForResponse(response => isApiResponse(response, 'POST', allowedOrigin, INSIGHT_PATH)
            && response.request().postDataJSON().refresh === false && response.status() === 200);
        await openInsight.click();
        const initialResponse = await initialResponsePromise;
        await settleResponse(initialResponse, 200, { data: CACHED_INSIGHT });
        const initialPosts = observedPosts(observedRequests, false);
        expect(initialPosts.length).toBeGreaterThan(0);
        const initialOutcomes = await settleObservedInsightPosts(initialPosts);
        expect(initialOutcomes.some(outcome => outcome.kind === 'response' && outcome.status === 200 && outcome.body.data?.fingerprint === CACHED_INSIGHT.fingerprint)).toBe(true);
        const surface = page.getByRole('dialog', { name: 'AI Insights — TCS', exact: true });
        await expect(surface.getByText(CACHED_INSIGHT.response.summary, { exact: true })).toBeVisible();
        await expect(surface.getByRole('heading', { name: 'Summary', exact: true })).toBeVisible();
        for (const heading of ['Technical / price context', 'Fundamental context', 'Positive signals', 'Risks / watch items', 'What to check next', 'Data limitations']) {
            await expect(surface.getByRole('heading', { name: heading, exact: true })).toBeVisible();
        }
        await expect(surface.getByText('OHLCV through 2026-09-09', { exact: true })).toBeVisible();
        await expect(surface.getByText('Fundamentals through 2026-06-30', { exact: true })).toBeVisible();
        await expect(surface.getByText('Personalized with your active portfolio holding', { exact: true })).toBeVisible();
        await expect(surface.getByRole('button', { name: 'Copy AI Prompt', exact: true })).toHaveCount(0);

        const refreshResponsePromise = page.waitForResponse(response => isApiResponse(response, 'POST', allowedOrigin, INSIGHT_PATH) && response.request().postDataJSON().refresh === true);
        await surface.getByRole('button', { name: 'Refresh insight', exact: true }).click();
        const refreshResponse = await refreshResponsePromise;
        await settleResponse(refreshResponse, 503, AI_ERROR);
        const refreshPosts = observedPosts(observedRequests, true);
        expect(refreshPosts).toHaveLength(1);
        const refreshOutcomes = await settleObservedInsightPosts(refreshPosts);
        expect(refreshOutcomes).toEqual([{ kind: 'response', status: 503, body: AI_ERROR }]);
        await expect(surface.getByText('Fresh AI refresh unavailable; showing the latest cached insight.', { exact: true })).toBeVisible();
        await expect(surface.getByText(CACHED_INSIGHT.response.summary, { exact: true })).toBeVisible();

        const failedMarketPromise = page.waitForResponse(response => isApiResponse(response, 'GET', allowedOrigin, MARKET_PATH));
        await surface.getByRole('button', { name: 'Copy AI Prompt', exact: true }).click();
        const failedMarketResponse = await failedMarketPromise;
        await settleResponse(failedMarketResponse, 503, MARKET_ERROR);
        await expect(page.getByText('Could not build the AI prompt. Please try again.', { exact: true })).toBeVisible();
        expect(await page.evaluate(() => window.__stoxClipboardWrites)).toEqual([]);
        await expect(surface.getByText(CACHED_INSIGHT.response.summary, { exact: true })).toBeVisible();

        const recoveredMarketPromise = page.waitForResponse(response => isApiResponse(response, 'GET', allowedOrigin, MARKET_PATH));
        await surface.getByRole('button', { name: 'Copy AI Prompt', exact: true }).click();
        const recoveredMarketResponse = await recoveredMarketPromise;
        await settleResponse(recoveredMarketResponse, 200, MARKET_HISTORY);
        await expect(page.getByText('Copied to clipboard.', { exact: true })).toBeVisible();
        const writes = await page.evaluate(() => window.__stoxClipboardWrites);
        expect(writes).toHaveLength(1);
        expectPrompt(writes[0]);
        expect(marketReadCount).toBe(2);
        expect(aiPayloads.filter(payload => payload.refresh === true)).toHaveLength(1);
        await assertOnlyAllowedHttpAndMutations(observedRequests, allowedOrigin, 1);
    });

    test(`AI-08 copies a manual prompt when managed insight is unavailable and returns to Holdings (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AI-08');
        await seedDeterministicJourney(page, `ai08-no-cache-prompt-recovery-${viewport.name}`);
        await installClipboardBoundary(page);
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await installHoldingsRoutes(page);

        const allowedOrigin = new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin;
        const observedRequests = [];
        const aiPayloads = [];
        let marketReadCount = 0;
        page.on('request', request => {
            const url = new URL(request.url());
            observedRequests.push({ request, protocol: url.protocol, origin: url.origin, pathname: url.pathname });
        });
        await installInsightRoute(page, 'no-cache', aiPayloads);
        await page.route(`**${MARKET_PATH}`, async route => {
            const request = route.request();
            expect(request.method()).toBe('GET');
            expect(new URL(request.url()).origin).toBe(allowedOrigin);
            marketReadCount += 1;
            expect(marketReadCount).toBe(1);
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(MARKET_HISTORY) });
        });

        await page.goto('/holdings');
        const row = page.getByRole('row').filter({ hasText: 'TCS' });
        await expect(row).toHaveCount(1);
        const openInsight = row.getByRole('button', { name: 'Open AI Insights', exact: true });
        const initialResponsePromise = page.waitForResponse(response => isApiResponse(response, 'POST', allowedOrigin, INSIGHT_PATH)
            && response.request().postDataJSON().refresh === false && response.status() === 503);
        await openInsight.click();
        const initialResponse = await initialResponsePromise;
        await settleResponse(initialResponse, 503, AI_ERROR);
        const initialPosts = observedPosts(observedRequests, false);
        expect(initialPosts.length).toBeGreaterThan(0);
        const initialOutcomes = await settleObservedInsightPosts(initialPosts);
        expect(initialOutcomes.some(outcome => outcome.kind === 'response' && outcome.status === 503 && outcome.body.error?.code === 'AI_UNAVAILABLE')).toBe(true);
        expect(aiPayloads.every(payload => payload.refresh === false)).toBe(true);
        const surface = page.getByRole('dialog', { name: 'AI Insights — TCS', exact: true });
        await expect(surface.getByText('Managed AI is unavailable. You can copy the AI prompt and try manually.', { exact: true })).toBeVisible();
        await expect(surface.getByRole('button', { name: 'Copy insight', exact: true })).toHaveCount(0);
        await expect(surface.getByRole('button', { name: 'Copy AI Prompt', exact: true })).toBeVisible();

        const marketResponsePromise = page.waitForResponse(response => isApiResponse(response, 'GET', allowedOrigin, MARKET_PATH));
        await surface.getByRole('button', { name: 'Copy AI Prompt', exact: true }).click();
        const marketResponse = await marketResponsePromise;
        await settleResponse(marketResponse, 200, MARKET_HISTORY);
        await expect(page.getByText('Copied to clipboard.', { exact: true })).toBeVisible();
        const writes = await page.evaluate(() => window.__stoxClipboardWrites);
        expect(writes).toHaveLength(1);
        expectPrompt(writes[0]);
        await expect(surface.getByText('Managed AI is unavailable. You can copy the AI prompt and try manually.', { exact: true })).toBeVisible();

        await surface.getByRole('button', { name: 'Close AI Insights', exact: true }).click();
        await expect(openInsight).toBeFocused();
        await expect(row).toBeVisible();
        await expect(page).toHaveURL(/\/holdings(?:[?#]|$)/);
        expect(marketReadCount).toBe(1);
        await assertOnlyAllowedHttpAndMutations(observedRequests, allowedOrigin, 0);
    });
}
