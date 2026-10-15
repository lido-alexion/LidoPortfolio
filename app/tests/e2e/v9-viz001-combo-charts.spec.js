import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

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

async function prepare(page, viewport) {
    await seedDeterministicJourney(page, `v9-viz001-${viewport}`);
    await page.setViewportSize(viewport === 'mobile' ? { width: 390, height: 844 } : { width: 1440, height: 900 });
    await installInvestorWorkflowApiMocks(page);
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
    await page.route('**/*', route => {
        const request = route.request();
        const url = new URL(request.url());
        const write = /^(POST|PUT|PATCH|DELETE)$/.test(request.method());
        if (url.origin !== 'http://127.0.0.1:4177' || (write && url.pathname !== '/api/logs/frontend')) {
            blocked.push(`${request.method()} ${url.pathname}`);
            return route.abort();
        }
        if (write && url.pathname !== '/api/logs/frontend') writes.push(`${request.method()} ${url.pathname}`);
        return route.fallback();
    });
    return { writes, blocked };
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
});
