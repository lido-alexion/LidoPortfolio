import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { hasPresetChartGeometry, waitForPresetChartGeometry } from './productionSmokeDiagnostics.js';

const dates = ['2025-10-01', '2026-01-01', '2026-04-01', '2026-07-01'];
const prices = dates.map((price_date, index) => ({
    price_date,
    open_price: 100 + index,
    high_price: 105 + index,
    low_price: 95 + index,
    close_price: 101 + index,
    volume: 1000 + index,
    data_source: 'SYNTHETIC_FIXTURE_ONLY',
}));
const points = dates.map((as_of, index) => ({ as_of, period_end: as_of, value: 3 + index }));
const metricNames = ['pe', 'pb', 'ps', 'eps', 'revenue', 'net_profit'];

function createDensePriceHistory() {
    const rows = [];
    const cursor = new Date('2005-10-03T00:00:00Z');
    while (rows.length < 5458) {
        const weekday = cursor.getUTCDay();
        if (weekday !== 0 && weekday !== 6) {
            const price_date = cursor.toISOString().slice(0, 10);
            const close_price = 100 + (rows.length % 700) / 10;
            rows.push({ price_date, open_price: close_price - 1, high_price: close_price + 2, low_price: close_price - 2, close_price, volume: 1000 + rows.length, data_source: 'SYNTHETIC_FIXTURE_ONLY' });
        }
        cursor.setUTCDate(cursor.getUTCDate() + 1);
    }
    return rows;
}

function deferred() {
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    return { promise, resolve };
}

async function togglePlottedSeriesLikeProductionSmoke(chart, name, seriesSelector, expectedGeometryAfterOff) {
    const checkbox = chart.getByRole('checkbox', { name: `Show ${name}`, exact: true });
    const series = chart.locator(seriesSelector);
    const geometryCount = () => series.evaluateAll(nodes => nodes.filter(node => Boolean(node.getAttribute('d')?.trim())).length);
    await expect(checkbox).toBeVisible();
    await expect(checkbox).toBeChecked();
    await expect.poll(geometryCount).toBeGreaterThan(0);
    const geometryBeforeToggle = await geometryCount();

    // Match the authenticated production smoke exactly: read checked state,
    // then use Playwright's actionability-checked pointer setChecked path.
    const wasChecked = await checkbox.isChecked();
    await checkbox.setChecked(!wasChecked);
    await expect(checkbox).toHaveJSProperty('checked', !wasChecked);
    await expect.poll(geometryCount).toBe(expectedGeometryAfterOff);
    await checkbox.setChecked(wasChecked);
    await expect(checkbox).toHaveJSProperty('checked', wasChecked);
    await expect.poll(geometryCount).toBe(geometryBeforeToggle);
}

async function progressiveDenseLegendScenario(page, viewport) {
    const safety = await prepare(page, viewport);
    // Playwright's browser.newContext() default used by the production smoke.
    await page.setViewportSize(viewport === 'mobile' ? { width: 390, height: 844 } : { width: 1280, height: 720 });
    const longPriceHistory = createDensePriceHistory();
    const releaseMetric = Object.fromEntries(metricNames.map(metric => [metric, deferred()]));
    const requestedMetrics = new Set();
    const observationDates = [-378, -252, -126, -1].map(offset => longPriceHistory.at(offset).price_date);
    const progressivePoints = observationDates.map((as_of, index) => ({ as_of, period_end: as_of, value: 3 + index }));

    // The long synthetic history stresses actual Recharts geometry while six
    // independent metric histories resolve one at a time. No production data
    // or preference writes are involved.
    await page.route('**/api/stocks/42/market-prices', route => route.fulfill({ json: { data: longPriceHistory, has_price_history: true, price_count: longPriceHistory.length } }));
    await page.route('**/api/v1/stocks/42/fundamentals/metrics/*/history?*', async route => {
        const routeMetric = new URL(route.request().url()).pathname.split('/').at(-2);
        const metric = routeMetric === 'net_income' ? 'net_profit' : routeMetric;
        requestedMetrics.add(metric);
        await releaseMetric[metric]?.promise;
        return route.fulfill({ json: { data: { points: progressivePoints } } });
    });

    const startedAt = Date.now();
    const deadline = startedAt + 20_000;
    const remaining = () => Math.max(1, deadline - Date.now());
    await page.goto('/watchlist/TCS');
    await expect(page.locator('.lido-guided-tour-modal[aria-labelledby="guided-tour-welcome-title"]')).toHaveCount(0);
    const chart = page.getByRole('region', { name: 'Stock Details combo chart', exact: true });
    await expect(chart).toBeVisible({ timeout: remaining() });
    const selector = chart.getByLabel('Combo chart preset');
    await expect(selector).toHaveValue('price-volume', { timeout: remaining() });
    await expect(chart.getByText(/Loading the saved default’s history/)).toBeVisible({ timeout: remaining() });
    await expect.poll(() => chart.locator('.recharts-line-curve').evaluateAll(paths => paths.filter(path => Boolean(path.getAttribute('d')?.trim())).length), { timeout: remaining() }).toBe(1);
    await expect.poll(() => chart.locator('.recharts-bar-rectangle .recharts-rectangle').evaluateAll(rectangles => rectangles.filter(rect => Boolean(rect.getAttribute('d')?.trim())).length), { timeout: remaining() }).toBeGreaterThan(0);

    await togglePlottedSeriesLikeProductionSmoke(chart, 'Price', '.recharts-line-curve', 0);
    await togglePlottedSeriesLikeProductionSmoke(chart, 'Volume', '.recharts-bar-rectangle .recharts-rectangle', 0);

    // Resolve metrics progressively, checking default availability only after
    // its own history arrives. In particular, PE availability selects the
    // saved valuation default and must provide real line geometry.
    for (const metric of metricNames) {
        await expect.poll(() => requestedMetrics.has(metric), { timeout: 5_000 }).toBe(true);
        releaseMetric[metric].resolve();
        const option = chart.locator(`option[value="price-${metric === 'net_profit' ? 'net-profit' : metric}"]`);
        await expect(option).toBeEnabled({ timeout: 5_000 });
        if (metric === 'pe') {
            await expect(selector).toHaveValue('price-pe', { timeout: remaining() });
            await expect.poll(() => chart.locator('.recharts-line-curve').evaluateAll(paths => paths.filter(path => Boolean(path.getAttribute('d')?.trim())).length), { timeout: remaining() }).toBeGreaterThanOrEqual(2);
            await togglePlottedSeriesLikeProductionSmoke(chart, 'Price', '.recharts-line-curve', 1);
        }
        if (metric === 'pb') {
            const next = chart.getByRole('button', { name: 'Next combo chart' });
            await next.focus();
            await page.keyboard.press('Enter');
            await expect(selector).toHaveValue('price-pb');
            await expect.poll(() => chart.locator('.recharts-line-curve').evaluateAll(paths => paths.filter(path => Boolean(path.getAttribute('d')?.trim())).length)).toBeGreaterThanOrEqual(2);
            await togglePlottedSeriesLikeProductionSmoke(chart, 'Price', '.recharts-line-curve', 1);
        }
    }

    expect(longPriceHistory).toHaveLength(5458);
    expect(safety.writes).toEqual([]);
    expect(safety.blocked).toEqual([]);
}

async function prepare(page, viewport, { showWelcomePrompt = false, allowMockedTourActions = false } = {}) {
    await seedDeterministicJourney(page, `v9-viz001-${viewport}`);
    await page.setViewportSize(viewport === 'mobile' ? { width: 390, height: 844 } : { width: 1440, height: 900 });
    await installInvestorWorkflowApiMocks(page, showWelcomePrompt ? { guidedTourState: {
        eligible: true,
        show_welcome_prompt: true,
        can_manual_relaunch: true,
        welcome_shown_at: null,
        completed_at: null,
        tour_in_progress: false,
    } } : {});
    await page.route('**/api/watchlists', route => route.fulfill({ json: { data: [{ id: 5, name: 'Synthetic research', item_count: 0 }] } }));
    await page.route('**/api/watchlists/5/items*', route => route.fulfill({ json: { data: [] } }));
    await page.route('**/api/watchlist/membership*', route => route.fulfill({ json: { watchlist_ids: [] } }));
    await page.route('**/api/stocks/42/market-prices', route => route.fulfill({ json: { data: prices, has_price_history: true, price_count: prices.length, from_date: dates[0], to_date: dates.at(-1), latest_close: 104, latest_price_date: dates.at(-1) } }));
    await page.route('**/api/stocks/42/pattern-scan*', route => route.fulfill({ json: { data: { matches: [] } } }));
    await page.route('**/api/v1/stocks/42/fundamentals/metrics/*/history?*', route => route.fulfill({ json: { data: { points } } }));
    await page.route('**/api/v1/stock-details/combo-chart-preference', route => {
        if (route.request().method() === 'GET') return route.fulfill({ json: { data: { default_preset_id: 'price-pe' } } });
        return route.fulfill({ status: 500, json: { message: 'This read-only journey must not save preferences.' } });
    });
    const writes = [];
    const blocked = [];
    let welcomeSkipUiActionInProgress = false;
    await page.route('**/*', route => {
        const request = route.request();
        const url = new URL(request.url());
        const write = /^(POST|PUT|PATCH|DELETE)$/.test(request.method());
        let tourAction = null;
        try {
            const payload = request.postDataJSON();
            if (payload && typeof payload === 'object' && !Array.isArray(payload) && Object.keys(payload).length === 1) tourAction = payload.action;
        } catch {}
        const mockedTourAction = allowMockedTourActions && url.origin === 'http://127.0.0.1:4177'
            && url.pathname === '/api/guided-tour' && request.method() === 'PUT'
            && (tourAction === 'record_welcome_shown' || (tourAction === 'skip_prompt' && welcomeSkipUiActionInProgress));
        if (url.origin !== 'http://127.0.0.1:4177' || (write && url.pathname !== '/api/logs/frontend' && !mockedTourAction)) {
            blocked.push(`${request.method()} ${url.pathname}`);
            return route.abort();
        }
        if (write && url.pathname !== '/api/logs/frontend') {
            writes.push(`${request.method()} ${url.pathname}${tourAction ? ` ${tourAction}` : ''}`);
        }
        return route.fallback();
    });
    return { writes, blocked, setWelcomeSkipUiActionInProgress(value) { welcomeSkipUiActionInProgress = value; } };
}

async function inspectChart(page, viewport, testInfo) {
    journeyId(testInfo, 'VIZ-01');
    const safety = await prepare(page, viewport);
    await page.goto('/watchlist/TCS');
    const chart = page.getByRole('region', { name: 'Stock Details combo chart', exact: true });
    await expect(chart).toBeVisible();
    const selector = chart.getByLabel('Combo chart preset');
    await expect(selector).toHaveValue('price-pe');
    await expect(selector.locator('optgroup')).toHaveCount(3);
    await expect(chart.getByRole('button', { name: 'Default chart', exact: true })).toBeVisible();
    await expect(page.locator('.lido-price-volume-chart')).toHaveCount(1);
    await expect(page.locator('.lido-combo-chart')).toHaveCount(1);

    const next = chart.getByRole('button', { name: 'Next combo chart' });
    await next.focus();
    await page.keyboard.press('Enter');
    await expect(selector).toHaveValue('price-pb');
    const smokeStyleLegend = chart.getByRole('checkbox').first();
    const wasChecked = await smokeStyleLegend.isChecked();
    await smokeStyleLegend.setChecked(!wasChecked);
    await expect(smokeStyleLegend).toHaveJSProperty('checked', !wasChecked);
    await smokeStyleLegend.setChecked(wasChecked);
    await expect(smokeStyleLegend).toHaveJSProperty('checked', wasChecked);
    await selector.selectOption('price-revenue');
    const revenueLegend = chart.getByRole('checkbox', { name: 'Show Revenue' });
    await revenueLegend.focus();
    await page.keyboard.press('Space');
    await expect(revenueLegend).not.toBeChecked();
    await page.keyboard.press('Space');
    await expect(revenueLegend).toBeChecked();
    const revenueBar = chart.locator('.recharts-bar-rectangle').first();
    await expect(revenueBar).toBeVisible();
    if (viewport === 'mobile') await revenueBar.tap();
    else await revenueBar.hover();
    const tooltip = chart.locator('.lido-combo-tooltip');
    await expect(tooltip).toBeVisible();
    await expect(tooltip).toContainText('Price');
    await expect(tooltip).toContainText('Revenue');
    await expect(tooltip).toContainText(/202[56]/);

    const range = chart.getByRole('group', { name: 'Combo chart time range' });
    await range.getByRole('button', { name: '1Y', exact: true }).click();
    await expect(range.getByRole('button', { name: '1Y', exact: true })).toHaveAttribute('aria-pressed', 'true');
    const sampling = chart.getByRole('group', { name: 'Combo chart sampling frequency' });
    await sampling.getByRole('button', { name: 'Quarterly', exact: true }).click();
    await expect(sampling.getByRole('button', { name: 'Quarterly', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(chart.getByText(/Fundamental values stay on their reported observation dates/)).toBeVisible();
    const bounds = await chart.boundingBox();
    expect(bounds).not.toBeNull();
    expect(bounds.width).toBeLessThanOrEqual(viewport === 'mobile' ? 390 : 1440);
    const accessibility = await new AxeBuilder({ page }).include('.lido-combo-chart').withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(accessibility.violations).toEqual([]);
    expect(safety.writes).toEqual([]);
    expect(safety.blocked).toEqual([]);
}

test('VIZ-01 supports desktop keyboard preset, legend and in-chart controls without writes', async ({ page }, testInfo) => {
    await inspectChart(page, 'desktop', testInfo);
});

test('production geometry predicate accepts plotted data in the labelled implicit section region', async ({ page }, testInfo) => {
    journeyId(testInfo, 'VIZ-01');
    await prepare(page, 'desktop');
    await page.goto('/watchlist/TCS');
    const chart = page.getByRole('region', { name: 'Stock Details combo chart', exact: true });
    await expect(chart).toBeVisible();
    const semantics = await chart.evaluate(region => ({ tagName: region.tagName, explicitRole: region.getAttribute('role') }));
    expect(semantics).toEqual({ tagName: 'SECTION', explicitRole: null });
    const readGeometry = () => chart.evaluate(region => ({
        linePathData: [...region.querySelectorAll('.recharts-line-curve')].map(path => path.getAttribute('d')),
        barPathData: [...region.querySelectorAll('.recharts-bar-rectangle .recharts-rectangle')].map(rect => rect.getAttribute('d')),
    }));
    await expect.poll(async () => hasPresetChartGeometry(await readGeometry(), 'price-pe')).toBe(true);
    expect(hasPresetChartGeometry({ linePathData: [], barPathData: [] }, 'price-pe')).toBe(false);
});

test('production geometry polling terminates on an empty implicit section within its budget', async ({ page }, testInfo) => {
    journeyId(testInfo, 'VIZ-01');
    await prepare(page, 'desktop');
    await page.goto('/watchlist/TCS');
    const chart = page.getByRole('region', { name: 'Stock Details combo chart', exact: true });
    await expect(chart).toBeVisible();
    await expect(chart.locator('.recharts-line-curve')).toHaveCount(2);
    await chart.evaluate(region => {
        region.querySelectorAll('.recharts-line-curve, .recharts-bar-rectangle .recharts-rectangle')
            .forEach(path => path.setAttribute('d', ''));
    });
    const semantics = await chart.evaluate(region => ({ tagName: region.tagName, explicitRole: region.getAttribute('role') }));
    expect(semantics).toEqual({ tagName: 'SECTION', explicitRole: null });
    let elapsedMs = 0;
    const accepted = await waitForPresetChartGeometry({
        presetId: 'price-pe',
        deadline: 25,
        now: () => elapsedMs,
        wait: async milliseconds => {
            elapsedMs += milliseconds;
            if (elapsedMs > 25) throw new Error('geometry polling exceeded its deterministic deadline');
        },
        scheduleTimeout: () => 1,
        cancelTimeout: () => {},
        readGeometry: () => chart.evaluate(region => ({
            linePathData: [...region.querySelectorAll('.recharts-line-curve')].map(path => path.getAttribute('d')),
            barPathData: [...region.querySelectorAll('.recharts-bar-rectangle .recharts-rectangle')].map(rect => rect.getAttribute('d')),
        })),
    });
    expect(accepted).toBe(false);
    expect(elapsedMs).toBe(25);
});

test('symbol deep links prefer the data-bearing NSE listing over a BSE-first watchlist row', async ({ page }, testInfo) => {
    journeyId(testInfo, 'VIZ-01');
    const safety = await prepare(page, 'desktop');
    const requestedPriceStockIds = [];
    let searchCalled = false;

    await page.route('**/api/watchlists/5/items*', route => route.fulfill({ json: { data: [{
        id: 1,
        stock_id: 4573,
        stock: { id: 4573, symbol: 'TCS', name: 'Tata Consultancy Services', exchange: 'BSE' },
    }] } }));
    await page.route('**/api/stocks/search*', route => {
        searchCalled = true;
        return route.fulfill({ json: { data: [
            { id: 4573, symbol: 'TCS', name: 'Tata Consultancy Services', exchange: 'BSE' },
            { id: 1886, symbol: 'TCS', name: 'Tata Consultancy Services', exchange: 'NSE' },
        ] } });
    });
    await page.route('**/api/stocks/*/market-prices', route => {
        const stockId = Number(new URL(route.request().url()).pathname.match(/\/stocks\/(\d+)\/market-prices$/)?.[1]);
        requestedPriceStockIds.push(stockId);
        return route.fulfill({ json: { data: stockId === 1886 ? prices : [], has_price_history: stockId === 1886, price_count: stockId === 1886 ? prices.length : 0 } });
    });
    await page.route('**/api/v1/stocks/*/fundamentals/metrics/*/history*', route => route.fulfill({ json: { data: { points } } }));

    await page.goto('/watchlist/TCS');
    const chart = page.getByRole('region', { name: 'Stock Details combo chart', exact: true });
    await expect(chart).toBeVisible();
    expect(searchCalled).toBe(true);
    expect(requestedPriceStockIds).toContain(1886);
    expect(requestedPriceStockIds).not.toContain(4573);
    await expect(chart.getByLabel('Combo chart preset')).toHaveValue('price-pe');
    expect(safety.writes).toEqual([]);
    expect(safety.blocked).toEqual([]);
});

test('a deliberate BSE selection survives late watchlist rows for the same symbol', async ({ page }, testInfo) => {
    journeyId(testInfo, 'VIZ-01');
    const safety = await prepare(page, 'desktop');
    const requestedPriceStockIds = [];
    let releaseWatchlistItems;
    const watchlistItemsReady = new Promise(resolve => { releaseWatchlistItems = resolve; });

    await page.route('**/api/watchlists/5/items*', async route => {
        await watchlistItemsReady;
        return route.fulfill({ json: { data: [
            { id: 1, stock_id: 4573, stock: { id: 4573, symbol: 'TCS', name: 'Tata Consultancy Services', exchange: 'BSE' } },
            { id: 2, stock_id: 1886, stock: { id: 1886, symbol: 'TCS', name: 'Tata Consultancy Services', exchange: 'NSE' } },
        ] } });
    });
    await page.route('**/api/stocks/search*', route => route.fulfill({ json: { data: [
        { id: 4573, symbol: 'TCS', name: 'Tata Consultancy Services', exchange: 'BSE' },
        { id: 1886, symbol: 'TCS', name: 'Tata Consultancy Services', exchange: 'NSE' },
    ] } }));
    await page.route('**/api/stocks/*/market-prices', route => {
        const stockId = Number(new URL(route.request().url()).pathname.match(/\/stocks\/(\d+)\/market-prices$/)?.[1]);
        requestedPriceStockIds.push(stockId);
        return route.fulfill({ json: { data: stockId === 1886 ? prices : [], has_price_history: stockId === 1886, price_count: stockId === 1886 ? prices.length : 0 } });
    });
    await page.route('**/api/v1/stocks/*/fundamentals/metrics/*/history*', route => route.fulfill({ json: { data: { points } } }));

    await page.goto('/watchlist/TCS');
    await expect(page.getByRole('region', { name: 'Stock Details combo chart', exact: true })).toBeVisible();
    const search = page.getByLabel('Stock', { exact: true });
    await search.fill('TC');
    await search.fill('TCS');
    const bseOption = page.getByRole('button', { name: /TCS.*BSE/ });
    await expect(bseOption).toBeVisible();
    await bseOption.click();
    await expect.poll(() => requestedPriceStockIds.at(-1)).toBe(4573);

    releaseWatchlistItems();
    await expect.poll(() => requestedPriceStockIds.at(-1)).toBe(4573);
    expect(requestedPriceStockIds).toContain(4573);
    expect(requestedPriceStockIds.slice(-1)[0]).not.toBe(1886);
    expect(safety.writes).toEqual([]);
    expect(safety.blocked).toEqual([]);
});

test('renders price and volume while the saved metric history is delayed, then honors that default', async ({ page }, testInfo) => {
    journeyId(testInfo, 'VIZ-01');
    const safety = await prepare(page, 'desktop');
    let releasePe;
    const peReady = new Promise(resolve => { releasePe = resolve; });
    let peRequested = false;
    await page.route('**/api/v1/stocks/42/fundamentals/metrics/*/history?*', async route => {
        const metric = new URL(route.request().url()).pathname.split('/').at(-2);
        if (metric === 'pe') {
            peRequested = true;
            await peReady;
        }
        return route.fulfill({ json: { data: { points } } });
    });

    await page.goto('/watchlist/TCS');
    const chart = page.getByRole('region', { name: 'Stock Details combo chart', exact: true });
    await expect(chart).toBeVisible();
    const selector = chart.getByLabel('Combo chart preset');
    await expect(selector).toHaveValue('price-volume');
    await expect(chart.getByRole('checkbox', { name: 'Show Price' })).toBeVisible();
    await expect(chart.locator('.recharts-line')).toBeVisible();
    await expect(chart.locator('.recharts-bar')).toBeVisible();
    await expect(chart.getByText(/Loading the saved default’s history/)).toBeVisible();
    await expect.poll(() => peRequested).toBe(true);
    await page.evaluate(() => {
        window.__viz001DefaultAvailabilityMismatch = false;
        const selector = document.querySelector('#combo-chart-preset');
        const observeAvailabilityCommit = () => {
            const savedDefault = selector?.querySelector('option[value="price-pe"]');
            if (savedDefault && !savedDefault.disabled && selector.value !== 'price-pe') {
                window.__viz001DefaultAvailabilityMismatch = true;
            }
        };
        new MutationObserver(observeAvailabilityCommit).observe(selector, { attributes: true, childList: true, subtree: true, attributeFilter: ['disabled'] });
    });
    expect(safety.writes).toEqual([]);
    expect(safety.blocked).toEqual([]);

    releasePe();
    await expect(selector).toHaveValue('price-pe');
    expect(await page.evaluate(() => window.__viz001DefaultAvailabilityMismatch)).toBe(false);
    await expect(chart.getByRole('button', { name: 'Default chart', exact: true })).toBeDisabled();
});

test('renders real combo series with 5,458 price rows while every unrelated history is pending', async ({ page }, testInfo) => {
    journeyId(testInfo, 'VIZ-01');
    const safety = await prepare(page, 'desktop');
    const longPriceHistory = createDensePriceHistory();
    const historiesReady = new Promise(() => {});
    let pendingHistoryRequests = 0;
    await page.route('**/api/stocks/42/market-prices', route => route.fulfill({ json: { data: longPriceHistory, has_price_history: true, price_count: longPriceHistory.length } }));
    await page.route('**/api/v1/stocks/42/fundamentals/metrics/*/history?*', async route => {
        pendingHistoryRequests += 1;
        await historiesReady;
        return route.fulfill({ json: { data: { points } } });
    });

    await page.goto('/watchlist/TCS');
    const chart = page.getByRole('region', { name: 'Stock Details combo chart', exact: true });
    await expect(chart).toBeVisible({ timeout: 20_000 });
    const selector = chart.getByLabel('Combo chart preset');
    await expect(selector).toHaveValue('price-volume');
    await expect(chart.getByText(/Loading the saved default’s history/)).toBeVisible();
    await expect(chart.getByRole('group', { name: 'Combo chart sampling frequency' }).getByRole('button', { name: 'Monthly', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(chart.getByText(/Monthly sampling is selected for this long history/)).toBeVisible();
    await expect(chart.locator('.recharts-line')).toBeVisible();
    await expect(chart.locator('.recharts-bar')).toBeVisible();
    const priceVolume = page.locator('.lido-price-volume-chart');
    await expect(priceVolume.getByRole('group', { name: 'Chart sampling interval' }).getByRole('button', { name: '1 month', exact: true })).toHaveAttribute('aria-pressed', 'true');
    const sampling = chart.getByRole('group', { name: 'Combo chart sampling frequency' });
    await sampling.getByRole('button', { name: 'Quarterly', exact: true }).click();
    await expect(sampling.getByRole('button', { name: 'Quarterly', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await chart.scrollIntoViewIfNeeded();
    await expect.poll(() => chart.locator('.recharts-line-curve').evaluateAll(paths => paths.filter(path => Boolean(path.getAttribute('d')?.trim())).length)).toBeGreaterThan(0);
    await expect.poll(() => chart.locator('.recharts-bar-rectangle .recharts-rectangle').evaluateAll(rects => rects.filter(rect => Boolean(rect.getAttribute('d')?.trim())).length)).toBeGreaterThan(0);
    expect(pendingHistoryRequests).toBeGreaterThanOrEqual(6);
    expect(safety.writes).toEqual([]);
    expect(safety.blocked).toEqual([]);
});

test('production pointer legend toggle removes and restores dense plotted series while metrics resolve progressively', async ({ page }, testInfo) => {
    journeyId(testInfo, 'VIZ-01');
    await progressiveDenseLegendScenario(page, 'desktop');
});

test('an open welcome prompt blocks the production pointer legend action until skipped through its normal UI', async ({ page }, testInfo) => {
    journeyId(testInfo, 'VIZ-01');
    const safety = await prepare(page, 'desktop', { showWelcomePrompt: true, allowMockedTourActions: true });
    await page.setViewportSize({ width: 1280, height: 720 });
    const longPriceHistory = createDensePriceHistory();
    await page.route('**/api/stocks/42/market-prices', route => route.fulfill({ json: { data: longPriceHistory, has_price_history: true, price_count: longPriceHistory.length } }));

    await page.goto('/watchlist/TCS');
    const chart = page.getByRole('region', { name: 'Stock Details combo chart', exact: true });
    const welcome = page.getByRole('dialog', { name: 'Welcome to StoX', exact: true });
    await expect(welcome).toBeVisible();
    await expect(chart).toBeVisible();
    const selector = chart.getByLabel('Combo chart preset');
    await expect(selector).toHaveValue('price-pe');
    const next = chart.getByRole('button', { name: 'Next combo chart' });
    await next.focus();
    await page.keyboard.press('Enter');
    await expect(selector).toHaveValue('price-pb');
    await chart.scrollIntoViewIfNeeded();

    const pricePaths = chart.locator('.recharts-line-curve');
    await expect.poll(() => pricePaths.evaluateAll(paths => paths.filter(path => Boolean(path.getAttribute('d')?.trim())).length)).toBeGreaterThanOrEqual(2);
    const legend = chart.getByRole('checkbox').first();
    await expect(legend).toBeChecked();
    let pointerError;
    try {
        await legend.setChecked(false, { timeout: 750 });
    } catch (error) {
        pointerError = error;
    }
    expect(pointerError).toBeTruthy();
    expect(pointerError.message).toMatch(/intercepts pointer events/i);
    await expect(legend).toBeChecked();
    await expect(welcome).toBeVisible();

    // A skip-shaped request without the real button action, including an
    // unexpected extra field, is blocked by the read-only request guard.
    await page.evaluate(async () => {
        const send = body => fetch('/api/guided-tour', { method: 'PUT', headers: { 'content-type': 'application/json' }, body });
        await send('{"action":"skip_prompt"}').catch(() => {});
        await send('{"action":"skip_prompt","extra":true}').catch(() => {});
    });
    expect(safety.blocked).toEqual(['PUT /api/guided-tour', 'PUT /api/guided-tour']);

    // Exercise the user's real onboarding choice in the local API mock only.
    // Production smoke remains read-only and never clicks these mutating actions.
    safety.setWelcomeSkipUiActionInProgress(true);
    try {
        await welcome.getByRole('button', { name: 'Skip for now', exact: true }).click();
    } finally {
        safety.setWelcomeSkipUiActionInProgress(false);
    }
    await expect(welcome).toBeHidden();
    const pathsBefore = await pricePaths.evaluateAll(paths => paths.filter(path => Boolean(path.getAttribute('d')?.trim())).length);
    await legend.setChecked(false);
    await expect(legend).not.toBeChecked();
    await expect.poll(() => pricePaths.evaluateAll(paths => paths.filter(path => Boolean(path.getAttribute('d')?.trim())).length)).toBe(pathsBefore - 1);
    await legend.setChecked(true);
    await expect(legend).toBeChecked();
    await expect.poll(() => pricePaths.evaluateAll(paths => paths.filter(path => Boolean(path.getAttribute('d')?.trim())).length)).toBe(pathsBefore);
    expect(safety.writes).toEqual([
        'PUT /api/guided-tour record_welcome_shown',
        'PUT /api/guided-tour skip_prompt',
    ]);
    expect(safety.blocked).toEqual(['PUT /api/guided-tour', 'PUT /api/guided-tour']);
});

test.describe('VIZ-01 mobile and touch', () => {
    test.use({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    test('keeps the combo chart and touch targets inside the mobile viewport without writes', async ({ page }, testInfo) => {
        await inspectChart(page, 'mobile', testInfo);
        const chart = page.getByRole('region', { name: 'Stock Details combo chart', exact: true });
        for (const name of ['Previous combo chart', 'Next combo chart']) {
            const bounds = await chart.getByRole('button', { name }).boundingBox();
            expect(bounds).not.toBeNull();
            expect(bounds.width).toBeGreaterThanOrEqual(40);
            expect(bounds.height).toBeGreaterThanOrEqual(40);
        }
    });

    test('production pointer legend toggle removes and restores dense plotted series while metrics resolve progressively', async ({ page }, testInfo) => {
        journeyId(testInfo, 'VIZ-01');
        await progressiveDenseLegendScenario(page, 'mobile');
    });
});
