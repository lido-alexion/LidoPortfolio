import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];
const STOCKS = {
    TCS: { id: 42, symbol: 'TCS', name: 'Tata Consultancy Services', exchange: 'NSE' },
    INFY: { id: 84, symbol: 'INFY', name: 'Infosys Limited', exchange: 'NSE' },
};
const prices = (stock, close) => ({
    stock,
    data: [{ price_date: '2026-10-16', open_price: close - 1, high_price: close + 1, low_price: close - 2, close_price: close, volume: 100, data_source: 'SYNTHETIC_FIXTURE_ONLY' }],
    price_count: 1,
    has_price_history: true,
    latest_close: close,
    latest_price_date: '2026-10-16',
    from_date: '2026-10-16',
    to_date: '2026-10-16',
});
const deferred = () => {
    let resolve;
    const promise = new Promise((yes) => { resolve = yes; });
    return { promise, resolve };
};

async function prepare(page, viewport, seed) {
    await seedDeterministicJourney(page, seed);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    const allowedOrigin = new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin;
    const blocked = [];
    const requests = [];
    const priceRequests = [];
    const priceRequestsByStock = new Map();
    await page.route('**/*', (route) => {
        const url = new URL(route.request().url());
        if (url.origin !== allowedOrigin) {
            blocked.push(url.origin);
            return route.abort();
        }
        return route.fallback();
    });
    await installInvestorWorkflowApiMocks(page);
    page.on('request', (request) => {
        const url = new URL(request.url());
        requests.push({ method: request.method(), path: url.pathname, origin: url.origin });
        const priceMatch = url.pathname.match(/^\/api\/stocks\/(\d+)\/market-prices$/);
        if (priceMatch) {
            priceRequests.push(request);
            const stockId = Number(priceMatch[1]);
            if (!priceRequestsByStock.has(stockId)) priceRequestsByStock.set(stockId, []);
            priceRequestsByStock.get(stockId).push(request);
        }
    });
    await page.route('**/api/watchlists', (route) => route.fulfill({
        json: { data: [{ id: 5, name: 'Research', item_count: 2 }] },
    }));
    await page.route('**/api/watchlists/5/items*', (route) => route.fulfill({
        json: { data: Object.values(STOCKS).map((stock, index) => ({
            id: 91 + index, stock_id: stock.id, watchlist_id: 5, stock, note: null,
        })) },
    }));
    await page.route('**/api/watchlist/membership*', (route) => route.fulfill({ json: { watchlist_ids: [] } }));
    await page.route('**/api/stocks/search*', (route) => {
        const query = new URL(route.request().url()).searchParams.get('q')?.toUpperCase();
        const stock = STOCKS[query];
        return route.fulfill({ json: { data: stock ? [stock] : [] } });
    });
    await page.route('**/api/stocks/*/pattern-scan*', (route) => route.fulfill({ json: { data: { matches: [] } } }));
    return { allowedOrigin, blocked, requests, priceRequests, priceRequestsByStock };
}

async function settle(requests, evidence, stockId) {
    expect(requests.length).toBeGreaterThan(0);
    await Promise.all(requests.map(async (request) => {
        const url = new URL(request.url());
        expect(request.method()).toBe('GET');
        expect(url.origin).toBe(evidence.allowedOrigin);
        expect(url.pathname).toBe(`/api/stocks/${stockId}/market-prices`);
        const response = await request.response();
        expect(response).not.toBeNull();
        expect(response.status()).toBe(200);
        expect(await response.finished()).toBeNull();
        expect((await response.body()).length).toBeGreaterThan(0);
    }));
}

async function selectStock(page, symbol) {
    const search = page.getByLabel('Stock', { exact: true });
    await search.fill(symbol);
    const option = page.getByRole('button', { name: new RegExp(`${symbol}.*${STOCKS[symbol].name}`) });
    await expect(option).toBeVisible();
    await option.click();
    await expect(page).toHaveURL(new RegExp(`/watchlist/${symbol}$`));
    await expect(page.locator('.lido-stock-symbol-with-analyse').getByText(symbol, { exact: true })).toBeVisible();
}

function expectReadOnly(evidence) {
    expect(evidence.blocked).toEqual([]);
    expect(evidence.requests.filter((request) => request.origin !== evidence.allowedOrigin)).toEqual([]);
    expect(evidence.requests.filter((request) => /^(POST|PUT|PATCH|DELETE)$/.test(request.method))).toEqual([]);
}

for (const viewport of VIEWPORTS) {
    test.describe(`E2E-09 selected-stock price history ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });

        test('does not project a late TCS response into the pending INFY selection', async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-09');
            const evidence = await prepare(page, viewport, `e2e09-price-race-${viewport.name}`);
            const tcsGate = deferred();
            const infyGate = deferred();
            await page.route('**/api/stocks/*/market-prices', async (route) => {
                expect(route.request().method()).toBe('GET');
                const stockId = Number(new URL(route.request().url()).pathname.match(/\/stocks\/(\d+)\/market-prices$/)?.[1]);
                if (stockId === STOCKS.TCS.id) await tcsGate.promise;
                if (stockId === STOCKS.INFY.id) await infyGate.promise;
                const stock = stockId === STOCKS.TCS.id ? STOCKS.TCS : STOCKS.INFY;
                return route.fulfill({ json: prices(stock, stockId === STOCKS.TCS.id ? 100 : 200) });
            });

            await page.goto('/watchlist');
            await expect(page.getByLabel('Active watchlist', { exact: true })).toHaveValue('5');
            await selectStock(page, 'TCS');
            await expect.poll(() => evidence.priceRequestsByStock.get(42)?.length || 0).toBeGreaterThan(0);
            const tcsRequests = evidence.priceRequestsByStock.get(42);
            await expect(page.getByText('Loading chart…', { exact: true })).toBeVisible();

            await selectStock(page, 'INFY');
            await expect.poll(() => evidence.priceRequestsByStock.get(84)?.length || 0).toBeGreaterThan(0);
            const infyRequests = evidence.priceRequestsByStock.get(84);
            await expect(page.getByText('Loading chart…', { exact: true })).toBeVisible();

            tcsGate.resolve();
            await settle(tcsRequests, evidence, 42);
            await expect(page.getByText('Loading chart…', { exact: true })).toBeVisible();
            await expect(page.getByText('Latest close', { exact: true })).toHaveCount(0);
            await expect(page.getByRole('alert')).toHaveCount(0);

            infyGate.resolve();
            await settle(infyRequests, evidence, 84);
            await expect(page.getByText('Latest close', { exact: true })).toBeVisible();
            await expect(page.getByText('₹ 200', { exact: true })).toBeVisible();
            expectReadOnly(evidence);
        });

        test('shows malformed history as unavailable, then distinguishes confirmed empty history after Retry', async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-09');
            const evidence = await prepare(page, viewport, `e2e09-price-retry-${viewport.name}`);
            let mode = 'malformed';
            await page.route('**/api/stocks/42/market-prices', async (route) => {
                expect(route.request().method()).toBe('GET');
                if (mode === 'malformed') {
                    return route.fulfill({ json: { price_count: 0, has_price_history: false } });
                }
                if (mode === 'missing-identity') {
                    return route.fulfill({ json: {
                        data: [],
                        price_count: 0,
                        has_price_history: false,
                        latest_close: null,
                        latest_price_date: null,
                        from_date: null,
                        to_date: null,
                    } });
                }
                if (mode === 'mismatched') {
                    return route.fulfill({ json: { stock: STOCKS.INFY, data: [], price_count: 0, has_price_history: false } });
                }
                return route.fulfill({ json: {
                    stock: STOCKS.TCS,
                    data: [],
                    price_count: 0,
                    has_price_history: false,
                    latest_close: null,
                    latest_price_date: null,
                    from_date: null,
                    to_date: null,
                } });
            });

            await page.goto('/watchlist');
            await selectStock(page, 'TCS');
            await expect(page.getByRole('alert')).toContainText('Price history could not be confirmed');
            await expect(page.getByText('Price history is unavailable until a current read succeeds.', { exact: true })).toBeVisible();
            await expect(page.getByText(/No cached OHLCV/)).toHaveCount(0);
            const initialReads = evidence.priceRequestsByStock.get(42);
            await settle(initialReads, evidence, 42);

            mode = 'missing-identity';
            let phaseStart = evidence.priceRequestsByStock.get(42).length;
            const missingIdentityRequest = page.waitForRequest((request) => request.method() === 'GET'
                && new URL(request.url()).pathname === '/api/stocks/42/market-prices');
            await page.getByRole('button', { name: 'Retry price history', exact: true }).click();
            const missingIdentity = await missingIdentityRequest;
            await settle(evidence.priceRequestsByStock.get(42).slice(phaseStart), evidence, 42);
            expect(evidence.priceRequestsByStock.get(42).slice(phaseStart)).toContain(missingIdentity);
            await expect(page.getByRole('alert')).toContainText('Price history could not be confirmed');
            await expect(page.getByText(/No cached OHLCV/)).toHaveCount(0);

            mode = 'mismatched';
            phaseStart = evidence.priceRequestsByStock.get(42).length;
            const mismatchRequest = page.waitForRequest((request) => request.method() === 'GET'
                && new URL(request.url()).pathname === '/api/stocks/42/market-prices');
            await page.getByRole('button', { name: 'Retry price history', exact: true }).click();
            const mismatched = await mismatchRequest;
            await settle(evidence.priceRequestsByStock.get(42).slice(phaseStart), evidence, 42);
            expect(evidence.priceRequestsByStock.get(42).slice(phaseStart)).toContain(mismatched);
            await expect(page.getByRole('alert')).toContainText('Price history could not be confirmed');
            await expect(page.getByText(/No cached OHLCV/)).toHaveCount(0);

            mode = 'empty';
            phaseStart = evidence.priceRequestsByStock.get(42).length;
            const emptyRequest = page.waitForRequest((request) => request.method() === 'GET'
                && new URL(request.url()).pathname === '/api/stocks/42/market-prices');
            await page.getByRole('button', { name: 'Retry price history', exact: true }).click();
            const retried = await emptyRequest;
            await settle(evidence.priceRequestsByStock.get(42).slice(phaseStart), evidence, 42);
            expect(evidence.priceRequestsByStock.get(42).slice(phaseStart)).toContain(retried);
            await expect(page.getByRole('alert')).toHaveCount(0);
            await expect(page.getByText(/No cached OHLCV/)).toBeVisible();
            await expect(page.getByText('No cached price history for this stock.', { exact: true })).toBeVisible();
            expectReadOnly(evidence);
        });
    });
}
