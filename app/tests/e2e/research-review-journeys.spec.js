import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { readOnlyReviewHeading } from './productionSmokeSelectors.js';
import { SAMPLE_REVIEW_REPORT } from '../js/tos/fixtures/tosApi.js';

const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
async function prepare(page, viewport, name, allowed = () => false) {
    await seedDeterministicJourney(page, `research-review-${name}-${viewport.name}`); await page.setViewportSize({ width: viewport.width, height: viewport.height }); await installInvestorWorkflowApiMocks(page);
    const blocked = []; const writes = [];
    return { blocked, writes, seal: () => page.route('**/*', route => {
        const request = route.request(); const url = new URL(request.url()); const write = /^(POST|PUT|PATCH|DELETE)$/.test(request.method());
        if (url.origin !== 'http://127.0.0.1:4177' || (write && url.pathname !== '/api/logs/frontend' && !allowed(request.method(), url.pathname))) { blocked.push(`${request.method()} ${url.pathname}`); return route.abort(); }
        if (write && url.pathname !== '/api/logs/frontend') writes.push({ method: request.method(), path: url.pathname }); return route.fallback();
    }) };
}
const failure = (route, message = 'Synthetic research unavailable.') => route.fulfill({ status: 503, json: { message } });
for (const viewport of VIEWPORTS) {
    test.describe(`Research and persisted review ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });
        test(`E2E-35 retries dated price reads, distinguishes ranges and retains Investor sync boundaries (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-35'); const safety = await prepare(page, viewport, 'prices'); let fail = true; const ranges = [];
            await page.route('**/api/stocks/42/prices?*', route => { const range = new URL(route.request().url()).searchParams.get('range'); ranges.push(range); if (fail) return failure(route); return route.fulfill({ json: { stock: { id: 42, symbol: 'TCS' }, data: range === 'all' ? [{ price_date: '2026-10-01', open_price: 100, high_price: 105, low_price: 95, close_price: 101, volume: 10, data_source: 'SYNTHETIC_FIXTURE_ONLY' }] : [], has_price_history: range === 'all', price_count: range === 'all' ? 1 : 0, all_from_date: '2026-10-01', since_buy_from_date: '2026-10-02' } }); });
            await safety.seal(); await page.goto('/holdings/42/prices'); await expect(page.getByRole('alert').filter({ hasText: 'Failed to load price history' })).toBeVisible(); const initialReads = ranges.length; expect(initialReads).toBeGreaterThan(0); expect(initialReads).toBeLessThanOrEqual(2); expect(ranges).toEqual(Array(initialReads).fill('all')); fail = false; await page.getByRole('button', { name: 'Retry price history' }).click(); await expect(page.getByText('TCS — Price History', { exact: true })).toBeVisible(); await expect(page.getByRole('cell', { name: 'SYNTHETIC_FIXTURE_ONLY' })).toBeVisible(); await expect(page.getByRole('button', { name: 'Force sync historical prices' })).toHaveCount(0); await page.getByRole('group', { name: 'Stock history range' }).getByRole('button', { name: 'Since Buy', exact: true }).click(); await expect(page.getByText('Price history unavailable — ask an administrator to review source coverage')).toBeVisible(); await expect(page.getByRole('cell', { name: 'SYNTHETIC_FIXTURE_ONLY' })).toHaveCount(0); await page.getByRole('group', { name: 'Stock history range' }).getByRole('button', { name: 'All', exact: true }).click(); await expect(page.getByRole('cell', { name: 'SYNTHETIC_FIXTURE_ONLY' })).toBeVisible(); expect(ranges).toEqual([...Array(initialReads).fill('all'), 'all', 'since_buy', 'all']); expect(safety.writes).toHaveLength(0); expect(safety.blocked).toEqual([]);
        });
        test(`E2E-36 recovers index overview/history/constituents and changes only local comparison selection (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-36'); const safety = await prepare(page, viewport, 'indices'); let pageFails = true; let priceFails = true; let constituentFails = true;
            const index = { stock_id: 501, symbol: 'NIFTY50', name: 'Nifty 50', exchange: 'NSE', tier: 'broad', has_price_history: true, price_from: '2026-09-01', price_to: '2026-10-01', price_count: 20, latest_close: 200, latest_price_date: '2026-10-01', change_percent: {}, constituents_available: true };
            await page.route('**/api/indexes/page', route => { if (pageFails) return failure(route); return route.fulfill({ json: { data: { indexes: [index] } } }); }); await page.route('**/api/indexes/comparison?*', route => { expect(new URL(route.request().url()).searchParams.get('months')).toBe('12'); return route.fulfill({ json: { data: { series: [] } } }); });
            await page.route('**/api/stocks/501/market-prices', route => { if (priceFails) { priceFails = false; return failure(route); } return route.fulfill({ json: { stock: { id: 501, symbol: 'NIFTY50' }, data: [] } }); }); await page.route('**/api/indexes/NIFTY50/constituents', route => { if (constituentFails) { constituentFails = false; return failure(route); } return route.fulfill({ json: { data: { symbol: 'NIFTY50', name: 'Nifty 50', available: true, constituents: [{ stock_id: 42, symbol: 'TCS', name: 'Tata Consultancy Services' }] } } }); });
            await safety.seal(); await page.goto('/indices'); await expect(page.getByRole('alert').filter({ hasText: 'Failed to load index data' })).toContainText('Failed to load index data'); pageFails = false; await page.getByRole('button', { name: 'Retry index data' }).click(); const legend = page.getByRole('group', { name: 'Broad market' }).getByRole('checkbox', { name: 'Nifty 50' }); await expect(legend).toBeChecked(); await legend.uncheck(); await expect(legend).not.toBeChecked(); await legend.check(); await page.getByRole('button', { name: /^Nifty 50 \(NIFTY50\)/ }).click(); const panel = page.getByRole('region', { name: /^Nifty 50 \(NIFTY50\)/ }); await expect(panel.getByRole('alert').filter({ hasText: 'Index history could not be loaded' })).toBeVisible(); await expect(panel.getByRole('alert').filter({ hasText: 'Index constituents could not be loaded' })).toBeVisible(); await panel.getByRole('button', { name: 'Retry index history' }).click(); await expect(panel).toContainText('No price points returned for this index.'); await panel.getByRole('button', { name: 'Retry index constituents' }).click(); await expect(panel.getByRole('link', { name: 'TCS', exact: true })).toBeVisible(); await panel.getByRole('searchbox', { name: 'Search NIFTY50 constituents' }).fill('Tata'); await expect(panel.getByRole('link', { name: 'TCS', exact: true })).toBeVisible(); expect(safety.writes).toHaveLength(0); expect(safety.blocked).toEqual([]);
        });
        test(`E2E-37 retries failed breadth snapshots and preserves dated percent/count evidence without backfill (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-37'); const safety = await prepare(page, viewport, 'breadth'); let fail = true; const dates = [];
            await page.route('**/api/market-depth*', route => { const date = new URL(route.request().url()).searchParams.get('date') || '2026-10-01'; dates.push(date); if (fail) return failure(route); return route.fulfill({ json: { available_dates: ['2026-10-01', '2026-09-30'], as_of_date: date, chart_history: [], matrix: { as_of_date: date, columns: [{ key: 'rising', label: 'Rising' }], rows: [{ symbol: 'NIFTY50', name: 'Nifty 50', eligible: 10, pct_rising: date === '2026-10-01' ? 50 : null, pass_rising: date === '2026-10-01' ? 5 : null, scanned_rising: date === '2026-10-01' ? 10 : null }] } } }); });
            await safety.seal(); await page.goto('/market-depth'); await expect(page.getByRole('alert').filter({ hasText: 'Market breadth could not be loaded' })).toContainText('Market breadth could not be loaded'); await expect(page.getByText(/Run cpanel-backfill/)).toHaveCount(0); const initialReads = dates.length; expect(initialReads).toBeGreaterThan(0); expect(initialReads).toBeLessThanOrEqual(2); expect(dates).toEqual(Array(initialReads).fill('2026-10-01')); fail = false; await page.getByRole('button', { name: 'Retry market breadth' }).click(); await expect(page.getByLabel('Date', { exact: true })).toHaveValue('2026-10-01'); await expect(page.locator('.lido-market-depth-cell')).toHaveText('50%'); await page.getByRole('group', { name: 'Value mode' }).getByRole('button', { name: 'Count', exact: true }).click(); await expect(page.locator('.lido-market-depth-cell')).toHaveText('5'); await page.getByLabel('Date', { exact: true }).selectOption('2026-09-30'); await expect(page.locator('.lido-market-depth-cell')).toHaveText('—'); await page.getByRole('group', { name: 'Value mode' }).getByRole('button', { name: '%', exact: true }).click(); await expect(page.locator('.lido-market-depth-cell')).toHaveText('—'); expect(dates).toEqual([...Array(initialReads).fill('2026-10-01'), '2026-10-01', '2026-09-30']); expect(safety.writes).toHaveLength(0); expect(safety.blocked).toEqual([]);
        });
        test(`E2E-38 resolves production review smoke beside the navigation title (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-38'); const safety = await prepare(page, viewport, 'production-review-selector');
            await page.route('**/api/v1/review/dashboard', route => route.fulfill({ json: { data: { counts: {}, portfolio: {}, outcomes: [], informational_outcomes: [] } } }));
            await page.route('**/api/v1/orders', route => route.fulfill({ json: { data: [] } }));
            await safety.seal(); await page.goto('/review');
            await expect(page.getByRole('heading', { name: 'Review', exact: true })).toHaveCount(2);
            await expect(readOnlyReviewHeading(page)).toBeVisible(); await expect(readOnlyReviewHeading(page)).toHaveCount(1);
            expect(safety.writes).toEqual([]); expect(safety.blocked).toEqual([]);
        });
        test(`E2E-38 separates failed observational reads, stored report identity and explicit generation retry (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-38'); const safety = await prepare(page, viewport, 'reports', (method, path) => method === 'POST' && path === '/api/v1/reviews/generate'); let dashboardFails = true; let listFails = true; let detailFails = true; let generateFails = true; const report = structuredClone(SAMPLE_REVIEW_REPORT); const original = JSON.stringify(report);
            await page.route('**/api/v1/review/dashboard', route => { if (dashboardFails) { dashboardFails = false; return failure(route); } return route.fulfill({ json: { data: { portfolio: {}, actionable_counts: { accepted: 3, executed: 2 }, outcomes: [] } } }); }); await page.route('**/api/v1/orders*', route => route.fulfill({ json: { data: [] } })); await page.route('**/api/v1/reviews?*', route => { if (listFails) { listFails = false; return failure(route); } return route.fulfill({ json: { data: [report], meta: { page: 1, pageSize: 20, total: 1, lastPage: 1 } } }); }); await page.route('**/api/v1/reviews/12', route => { if (detailFails) return failure(route); return route.fulfill({ json: { data: report } }); }); await page.route('**/api/v1/reviews/15', route => route.fulfill({ json: { data: { ...report, id: 15, period_start: '2026-10-01', period_end: '2026-10-10' } } })); await page.route('**/api/v1/reviews/generate?*', route => { const params = new URL(route.request().url()).searchParams; expect(params.get('period_start')).toBe('2026-10-01'); expect(params.get('period_end')).toBe('2026-10-10'); if (generateFails) { generateFails = false; return failure(route, 'Synthetic generation unavailable.'); } return route.fulfill({ json: { data: { report: { id: 15 } } } }); });
            await safety.seal(); await page.goto('/review'); await expect(page.getByRole('alert').filter({ hasText: 'Review dashboard could not be loaded' })).toBeVisible(); await expect(page.getByText('Loading…', { exact: true })).toHaveCount(0); await page.getByRole('button', { name: 'Refresh', exact: true }).click(); await expect(page.getByText('Trade recommendations (actionable)', { exact: true })).toBeVisible(); await page.getByRole('link', { name: 'Reports', exact: true }).click(); await expect(page.getByRole('alert').filter({ hasText: 'Stored review reports are unavailable' })).toBeVisible(); await page.locator('.container-fluid').getByRole('button', { name: 'Retry', exact: true }).click(); await page.getByTestId('review-reports-table').getByRole('button', { name: 'Open', exact: true }).click(); await expect(page.getByRole('alert').filter({ hasText: 'Review report could not be loaded' })).toBeVisible(); await expect(page.getByTestId('review-report-not-found')).toHaveCount(0); detailFails = false; await page.getByRole('button', { name: 'Retry review report' }).click(); await expect(page.getByTestId('review-report-methodology')).toContainText('Share of sell transactions'); await expect(page.getByText('Accepted (not executed)', { exact: true })).toBeVisible(); await expect(page.getByText('Executed', { exact: true })).toBeVisible(); await page.getByRole('link', { name: 'Back to reports', exact: true }).click(); await page.getByLabel('From (optional)', { exact: true }).fill('2026-10-01'); await page.getByLabel('To (optional)', { exact: true }).fill('2026-10-10'); await page.getByRole('button', { name: 'Generate', exact: true }).click(); await expect(page.getByText('Synthetic generation unavailable.', { exact: true })).toBeVisible(); await expect(page.getByLabel('From (optional)', { exact: true })).toHaveValue('2026-10-01'); await page.getByRole('button', { name: 'Generate', exact: true }).click(); await expect(page).toHaveURL('/review/reports/15'); await expect(page.getByText('2026-10-01 / 2026-10-10', { exact: true })).toBeVisible(); expect(JSON.stringify(report)).toBe(original); expect(safety.writes).toHaveLength(2); expect(safety.blocked).toEqual([]);
        });
        test(`E2E-38 ignores delayed report responses after switching report identity (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-38'); const safety = await prepare(page, viewport, 'report-read-identity');
            let releaseFirstA; let releaseB; let releaseCurrentA;
            const reportAFirst = new Promise(resolve => { releaseFirstA = resolve; });
            const reportB = new Promise(resolve => { releaseB = resolve; });
            const reportACurrent = new Promise(resolve => { releaseCurrentA = resolve; });
            let reportAPhase = 'old'; const phaseRequests = { oldA: [], reportB: [], currentA: [] };
            page.on('request', request => {
                const url = new URL(request.url());
                if (url.origin !== 'http://127.0.0.1:4177' || request.method() !== 'GET') return;
                if (url.pathname === '/api/v1/reviews/12') phaseRequests[reportAPhase === 'old' ? 'oldA' : 'currentA'].push(request);
                if (url.pathname === '/api/v1/reviews/13') phaseRequests.reportB.push(request);
            });
            const settlePhase = async (phase, expectedStatus) => {
                const requests = phaseRequests[phase].slice();
                expect(requests.length).toBeGreaterThan(0);
                await Promise.all(requests.map(async request => {
                    const response = await request.response();
                    expect(response).not.toBeNull();
                    expect(response.status()).toBe(expectedStatus);
                    expect(await response.finished()).toBeNull();
                }));
            };
            await page.route('**/api/v1/reviews?*', route => route.fulfill({ json: { data: [
                { id: 12, status: 'fixture A', period_start: '2026-09-01', period_end: '2026-09-30', metrics: {}, methodology: {} },
                { id: 13, status: 'fixture B', period_start: '2026-10-01', period_end: '2026-10-10', metrics: {}, methodology: {} },
            ], meta: { page: 1, pageSize: 20, total: 2, lastPage: 1 } } }));
            await page.route('**/api/v1/reviews/12', async route => {
                const phase = reportAPhase === 'old' ? 'oldA' : 'currentA'; const gate = phase === 'oldA' ? reportAFirst : reportACurrent;
                await gate;
                if (phase === 'oldA') return route.fulfill({ status: 503, json: { message: 'Synthetic old A failure.' } });
                return route.fulfill({ json: { data: { id: 12, status: 'current report A', period_start: '2026-09-01', period_end: '2026-09-30', metrics: {}, methodology: { source: 'current A' } } } });
            });
            await page.route('**/api/v1/reviews/13', async route => { await reportB; return route.fulfill({ json: { data: { id: 13, status: 'old report B', period_start: '2026-10-01', period_end: '2026-10-10', metrics: {}, methodology: {} } } }); });
            await safety.seal(); await page.goto('/review/reports');
            const reportTable = page.getByTestId('review-reports-table'); const rowA = reportTable.getByRole('button', { name: /12.*fixture A/ }); const rowB = reportTable.getByRole('button', { name: /13.*fixture B/ });
            await rowA.click(); await expect(page.getByRole('status')).toHaveText('Loading review report…'); await expect.poll(() => phaseRequests.oldA.length).toBeGreaterThan(0);
            await page.getByRole('link', { name: 'Back to reports' }).click(); await rowB.click(); await expect(page.getByRole('status')).toHaveText('Loading review report…'); await expect.poll(() => phaseRequests.reportB.length).toBeGreaterThan(0);
            await page.getByRole('link', { name: 'Back to reports' }).click(); reportAPhase = 'current'; await rowA.click();
            await expect(page.getByRole('status')).toHaveText('Loading review report…'); await expect.poll(() => phaseRequests.currentA.length).toBeGreaterThan(0);
            releaseB(); await settlePhase('reportB', 200); await expect(page.getByRole('status')).toHaveText('Loading review report…'); await expect(page.getByText('old report B', { exact: true })).toHaveCount(0);
            releaseFirstA(); await settlePhase('oldA', 503); await expect(page.getByRole('status')).toHaveText('Loading review report…');
            releaseCurrentA(); await settlePhase('currentA', 200); await expect(page.getByText('current report A', { exact: true })).toBeVisible();
            expect(phaseRequests.oldA.length).toBeGreaterThan(0); expect(phaseRequests.reportB.length).toBeGreaterThan(0); expect(phaseRequests.currentA.length).toBeGreaterThan(0); expect(safety.writes).toEqual([]); expect(safety.blocked).toEqual([]);
        });
        test(`E2E-39 recovers fundamental resolution and uses educational patterns without trading (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-39'); const safety = await prepare(page, viewport, 'fundamentals'); let resolutionUnavailable = true; let insightUnavailable = true;
            await page.route('**/api/stocks/search?*', route => { if (resolutionUnavailable) return failure(route, 'Synthetic symbol resolution unavailable.'); return route.fulfill({ json: { data: [{ id: 42, symbol: 'TCS', exchange: 'NSE', name: 'Tata Consultancy Services' }] } }); }); await page.route('**/api/v1/stocks/42/fundamentals?*', route => { if (insightUnavailable) return failure(route, 'Synthetic fundamental read unavailable.'); return route.fulfill({ json: { data: { as_of: '2026-10-01', freshness: { status: 'stale' }, insights: null } } }); });
            await safety.seal(); await page.goto('/fundamentals/insights/TCS'); await expect(page.getByRole('alert')).toContainText('Synthetic symbol resolution unavailable'); resolutionUnavailable = false; await page.getByRole('button', { name: 'Retry fundamental insights' }).click(); await expect(page.getByRole('alert')).toContainText('Synthetic fundamental read unavailable'); insightUnavailable = false; await page.getByRole('button', { name: 'Retry fundamental insights' }).click(); await expect(page.getByText('As of 2026-10-01 · Freshness: stale', { exact: true })).toBeVisible(); await expect(page.getByText('No insight payload returned for this symbol.', { exact: true })).toBeVisible(); await expect(page.getByText('Not investment advice.', { exact: false })).toBeVisible(); await page.goto('/patterns'); await expect(page.getByText('This page is educational only — not trading advice.', { exact: false })).toBeVisible(); await page.getByLabel('Filter', { exact: true }).fill('no-synthetic-pattern-exists'); await expect(page.getByText('No patterns match your search.', { exact: true })).toBeVisible(); await page.getByLabel('Filter', { exact: true }).fill(''); await page.getByRole('group', { name: 'Pattern guide section' }).getByRole('button', { name: 'Candlesticks', exact: true }).click(); await expect(page.getByText('Candle OHLCV terms', { exact: true })).toBeVisible(); await page.reload(); await expect(page.getByText('Candle OHLCV terms', { exact: true })).toBeVisible(); expect(safety.writes).toHaveLength(0); expect(safety.blocked).toEqual([]);
        });
        test(`E2E-39 ignores late symbol evidence while the selected stock read is pending (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-39'); const safety = await prepare(page, viewport, 'fundamental-read-identity');
            let releaseOldA; let releaseCurrentB; let oldAReleased = false; let currentBReleased = false;
            const oldA = new Promise(resolve => { releaseOldA = resolve; });
            const currentB = new Promise(resolve => { releaseCurrentB = resolve; });
            const reads = []; const oldARequests = [];
            page.on('request', request => {
                const url = new URL(request.url());
                if (url.origin === 'http://127.0.0.1:4177' && url.pathname === '/api/v1/stocks/42/fundamentals' && request.method() === 'GET') oldARequests.push(request);
            });
            await page.route('**/api/stocks/search?*', async route => {
                const url = new URL(route.request().url()); const query = url.searchParams.get('q'); const limit = url.searchParams.get('limit');
                if (query === 'TCS' && limit === '8') return route.fulfill({ json: { data: [{ id: 42, symbol: 'TCS', exchange: 'NSE', name: 'Tata Consultancy Services' }] } });
                if (query === 'INFY' && limit === '20') return route.fulfill({ json: { data: [{ id: 43, symbol: 'INFY', exchange: 'NSE', name: 'Infosys Limited' }] } });
                if (query === 'INFY' && limit === '8') return route.fulfill({ json: { data: [{ id: 43, symbol: 'INFY', exchange: 'NSE', name: 'Infosys Limited' }] } });
                throw new Error(`Unexpected symbol search ${url.pathname}${url.search}`);
            });
            await page.route('**/api/v1/stocks/42/fundamentals?*', async route => {
                const url = new URL(route.request().url()); expect(url.searchParams.get('include_insights')).toBe('1'); expect(url.searchParams.get('include_ai_insights')).toBe('1'); reads.push(42);
                if (!oldAReleased) await oldA;
                return route.fulfill({ status: 503, json: { message: 'Synthetic superseded TCS read failure.' } });
            });
            await page.route('**/api/v1/stocks/43/fundamentals?*', async route => {
                const url = new URL(route.request().url()); expect(url.searchParams.get('include_insights')).toBe('1'); expect(url.searchParams.get('include_ai_insights')).toBe('1'); reads.push(43);
                if (!currentBReleased) await currentB;
                return route.fulfill({ json: { data: { as_of: '2026-10-18', freshness: { status: 'current' }, insights: null } } });
            });
            await safety.seal(); await page.goto('/fundamentals/insights/TCS'); await expect.poll(() => reads.includes(42)).toBe(true);
            const symbolInput = page.getByLabel('Stock', { exact: true }); await symbolInput.fill('INFY'); await page.getByRole('button', { name: /INFY.*NSE/ }).click();
            await expect.poll(() => reads.includes(43)).toBe(true);
            await expect(symbolInput).toHaveValue('INFY');
            await expect(page.getByRole('status')).toHaveText('Loading insights…');
            await expect(page.getByRole('heading', { name: 'INFYInfosys Limited', exact: true })).toHaveCount(0);
            const oldARequestCount = oldARequests.length;
            expect(oldARequestCount).toBeGreaterThan(0);
            const oldAResponse = page.waitForResponse(response => response.url().includes('/api/v1/stocks/42/fundamentals') && response.status() === 503);
            oldAReleased = true; releaseOldA();
            const settledOldA = await oldAResponse;
            expect(await settledOldA.finished()).toBeNull();
            await Promise.all(oldARequests.slice(0, oldARequestCount).map(async request => {
                const response = await request.response();
                expect(response).not.toBeNull();
                expect(await response.finished()).toBeNull();
            }));
            await expect(page.getByRole('status')).toHaveText('Loading insights…'); await expect(page.getByRole('alert')).toHaveCount(0); await expect(page.getByText('Synthetic superseded TCS read failure.')).toHaveCount(0);
            const currentBResponse = page.waitForResponse(response => response.url().includes('/api/v1/stocks/43/fundamentals') && response.status() === 200);
            currentBReleased = true; releaseCurrentB();
            const settledB = await currentBResponse;
            expect(await settledB.finished()).toBeNull();
            await expect(page.getByText('As of 2026-10-18 · Freshness: current', { exact: true })).toBeVisible(); await expect(page.getByText('No insight payload returned for this symbol.', { exact: true })).toBeVisible();
            await expect(page.getByRole('heading', { name: 'INFYInfosys Limited', exact: true })).toBeVisible();
            expect(reads[0]).toBe(42); expect(reads).toContain(43); expect(safety.writes).toEqual([]); expect(safety.blocked).toEqual([]);
        });
    });
}
