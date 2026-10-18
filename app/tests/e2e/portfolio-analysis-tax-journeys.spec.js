import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
async function prepare(page, viewport, name, allowed = () => false) {
    await seedDeterministicJourney(page, `analysis-tax-${name}-${viewport.name}`); await page.setViewportSize({ width: viewport.width, height: viewport.height }); await installInvestorWorkflowApiMocks(page);
    const blocked = []; const writes = [];
    return { blocked, writes, seal: () => page.route('**/*', route => {
        const request = route.request(); const url = new URL(request.url()); const write = /^(POST|PUT|PATCH|DELETE)$/.test(request.method());
        if (url.origin !== 'http://127.0.0.1:4177' || (write && url.pathname !== '/api/logs/frontend' && !allowed(request.method(), url.pathname))) { blocked.push(`${request.method()} ${url.pathname}`); return route.abort(); }
        if (write && url.pathname !== '/api/logs/frontend') writes.push({ method: request.method(), path: url.pathname }); return route.fallback();
    }) };
}
async function analysisReads(page) {
    const state = { unavailable: false, preferences: { primary_benchmark_id: 1, comparison_benchmark_ids: [], include_in_account_performance: true, include_in_account_tax: false, risk_free_rate: 0.04, annualization_days: 252 }, accountPreferences: { primary_benchmark_id: 1, comparison_benchmark_ids: [], risk_free_rate: 0.04, annualization_days: 252 }, selections: [] };
    await page.route('**/api/portfolios', r => r.fulfill({ json: { data: [{ id: 1, name: 'Main', is_default: true }, { id: 2, name: 'Retirement', is_default: false }] } }));
    await page.route('**/api/stocks?*', r => r.fulfill({ json: { data: [{ id: 42, symbol: 'TCS', name: 'Tata Consultancy Services' }] } }));
    await page.route(/\/api\/(analysis\/(performance|account-performance|attribution|preferences|benchmarks)|tax\/report)(\?.*)?$/, route => {
        const url = new URL(route.request().url()); const path = url.pathname;
        if (route.request().method() !== 'GET') return route.fallback();
        if (state.unavailable && path === '/api/analysis/performance') return route.fulfill({ status: 503, json: { message: 'Synthetic analysis unavailable.' } });
        const ids = url.searchParams.getAll('portfolio_ids[]').map(Number); if (path === '/api/analysis/account-performance') state.selections.push(ids);
        const data = path === '/api/analysis/preferences' ? url.searchParams.get('scope') === 'account' ? state.accountPreferences : state.preferences
            : path === '/api/analysis/benchmarks' ? [{ id: 1, name: 'Nifty', return_type: 'price' }, { id: 2, name: 'Sensex', return_type: 'price' }]
                : path === '/api/analysis/performance' ? { completeness: 'incomplete', xirr_percent: null, twr_percent: null, benchmark: null }
                    : path === '/api/analysis/account-performance' ? { portfolio_ids: ids.length ? ids : [1], calculation_mode: url.searchParams.has('portfolio_ids[]') ? 'what_if' : 'configured', xirr_percent: null, twr_percent: null }
                        : path === '/api/analysis/attribution' ? { method: 'synthetic', explained_net_realized: 0, reconciliation_residual: null, limitations: ['Missing historical observations.'] }
                            : { summary: {}, calculation_mode: url.searchParams.has('portfolio_ids[]') ? 'what_if' : 'configured', completeness: 'incomplete', limitations: ['Missing tax evidence.'] };
        return route.fulfill({ json: { data } });
    });
    return state;
}
function deferredResponse() {
    let resolve;
    const promise = new Promise((yes) => { resolve = yes; });
    return { promise, resolve };
}
for (const viewport of VIEWPORTS) {
    test.describe(`Portfolio analysis and tax ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });
        test(`E2E-25 retries historical reads, discloses missing valuations and compares dated external flows (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-25'); const safety = await prepare(page, viewport, 'history'); let historicalUnavailable = true; let compareUnavailable = true; const dates = [];
            await page.route('**/api/portfolio/historical-holdings?*', route => {
                dates.push(new URL(route.request().url()).searchParams.get('as_of'));
                if (historicalUnavailable) return route.fulfill({ status: 503, json: { message: 'Synthetic historical read unavailable.' } });
                return route.fulfill({ json: { as_of: dates[dates.length - 1], holdings: [{ stock_id: 42, symbol: 'TCS', name: 'Tata Consultancy Services', quantity: 10, avg_buy_price: 100, invested_amount: 1000, price_available: false, as_of_price: null, market_value: null }], warnings: [], totals: { invested_value: 1000, market_value: null, cash_balance: 0, total_value: null, unrealized_profit: null, valuation_complete: false }, completeness: { valuation_complete: false, total_value_complete: false, missing_price_count: 1 } } });
            });
            await page.route('**/api/portfolio/compare?*', route => {
                if (compareUnavailable) return route.fulfill({ status: 503, json: { message: 'Synthetic comparison unavailable.' } });
                const params = new URL(route.request().url()).searchParams;
                return route.fulfill({ json: { data: { endpoint_a: { date: params.get('date_a'), holdings_market_value: 1000, cash: 2000, total_value: 3000 }, endpoint_b: { date: params.get('date_b'), holdings_market_value: null, cash: 2500, total_value: null }, deltas: { label: 'Value change', total_value: null }, external_flows: { deposits: 1000, withdrawals: 500, net: 500, adjustments: 0 }, holdings: [{ stock_id: 42, symbol: 'TCS', classification: 'retained', quantity_a: 10, quantity_b: 10, quantity_delta: 0, market_value_a: 1000, market_value_b: null }], transactions: [] } } });
            });
            await safety.seal(); await page.goto('/portfolio/historical-holdings'); await expect(page.locator('.container-fluid [role="alert"]').filter({ hasText: 'Synthetic historical read unavailable.' })).toBeVisible(); historicalUnavailable = false; await page.getByRole('button', { name: 'Refresh', exact: true }).click();
            await expect(page.getByRole('status').filter({ hasText: 'Valuation is incomplete' })).toBeVisible(); await expect(page.getByRole('row').filter({ hasText: 'TCS' })).toContainText('Unavailable');
            await page.getByLabel('As-of date', { exact: true }).fill('01-Oct-2026'); await page.getByLabel('As-of date', { exact: true }).press('Tab'); await expect.poll(() => dates.includes('2026-10-01')).toBe(true);
            await page.getByRole('link', { name: 'Compare dates', exact: true }).click(); await expect(page.locator('.container-fluid [role="alert"]').filter({ hasText: 'Synthetic comparison unavailable.' })).toBeVisible();
            await page.getByLabel('Date A', { exact: true }).fill('2026-10-01'); await page.getByLabel('Date B', { exact: true }).fill('2026-10-01'); await expect(page.getByRole('button', { name: 'Compare', exact: true })).toBeDisabled(); await expect(page.getByText('Date A must be before Date B, and neither date may be in the future.', { exact: true })).toBeVisible();
            await page.getByLabel('Date A', { exact: true }).fill('2026-09-01'); compareUnavailable = false; await page.getByRole('button', { name: 'Compare', exact: true }).click(); await expect(page).toHaveURL(/date_a=2026-09-01&date_b=2026-10-01/);
            await expect(page.getByText(/Value change is not investment return/)).toBeVisible(); await expect(page.getByText(/External flows: deposits/)).toContainText('500'); await expect(page.getByRole('row').filter({ hasText: 'TCS' })).toContainText('Incomplete'); expect(safety.writes).toEqual([]); expect(safety.blocked).toEqual([]);
        });
        test(`E2E-25 keeps valid snapshots after refresh failure and rebuilds only after explicit confirmation (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-25'); const safety = await prepare(page, viewport, 'snapshots', (method, path) => method === 'POST' && path === '/api/portfolio/rebuild-history'); let readFails = false; let rebuildFails = true; const limits = []; const source = [{ id: 71, snapshot_date: '2026-10-01', portfolio_value: 110000, invested_value: 100000 }, { id: 72, snapshot_date: '2026-10-10', portfolio_value: 120000, invested_value: 100000 }]; const originalSource = JSON.stringify(source);
            await page.route('**/api/portfolio/snapshots?*', route => { limits.push(new URL(route.request().url()).searchParams.get('limit')); if (readFails) { readFails = false; return route.fulfill({ status: 503, json: { message: 'Synthetic snapshot read unavailable.' } }); } return route.fulfill({ json: { snapshots: source, meta: { count: 2 } } }); });
            await page.route('**/api/portfolio/rebuild-history', route => { if (rebuildFails) { rebuildFails = false; return route.fulfill({ status: 503, json: { message: 'Synthetic rebuild unavailable.' } }); } return route.fulfill({ json: { rebuild: { snapshots_written: 2 } } }); });
            await safety.seal(); await page.goto('/portfolio/snapshots'); const row = page.getByRole('row').filter({ has: page.getByLabel('Select snapshot 2026-10-10') }); await expect(row).toContainText('1,20,000');
            await page.getByRole('button', { name: '90 days', exact: true }).click(); await expect.poll(() => limits.includes('90')).toBe(true); readFails = true; await page.getByRole('button', { name: 'Refresh', exact: true }).click(); await expect(page.getByRole('alert').filter({ hasText: 'Could not load portfolio snapshots' })).toBeVisible(); await expect(row).toContainText('1,20,000'); await page.getByRole('button', { name: 'Refresh', exact: true }).click(); await expect(page.getByRole('alert').filter({ hasText: 'Could not load portfolio snapshots' })).toHaveCount(0);
            page.once('dialog', async dialog => { expect(dialog.message()).toContain('transactions and stock prices'); await dialog.dismiss(); }); await page.getByRole('button', { name: 'Rebuild history', exact: true }).click(); expect(safety.writes).toHaveLength(0);
            page.once('dialog', dialog => dialog.accept()); await page.getByRole('button', { name: 'Rebuild history', exact: true }).click(); await expect(page.getByText('Failed to rebuild portfolio history', { exact: true })).toBeVisible(); await expect(row).toBeVisible(); page.once('dialog', dialog => dialog.accept()); await page.getByRole('button', { name: 'Rebuild history', exact: true }).click(); await expect(page.getByText('Portfolio history rebuilt (2 snapshots).', { exact: true })).toBeVisible(); expect(JSON.stringify(source)).toBe(originalSource); expect(safety.writes).toHaveLength(2); expect(safety.blocked).toEqual([]);
        });
        test(`E2E-25 hides historical and snapshot values until the current profile and query are confirmed (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-25');
            const safety = await prepare(page, viewport, `history-identity-${viewport.name}`);
            const requests = [];
            const pendingB = deferredResponse();
            const pendingRange = deferredResponse();
            let bReadStarted;
            const bReadStartedPromise = new Promise(resolve => { bReadStarted = resolve; });
            let rangeReadStarted;
            const rangeReadStartedPromise = new Promise(resolve => { rangeReadStarted = resolve; });
            let rangeReadCount = 0;
            await page.route('**/api/portfolios', route => route.fulfill({ json: { data: [
                { id: 1, name: 'Main', is_default: true },
                { id: 2, name: 'Research', is_default: false },
            ] } }));
            await page.route('**/api/portfolio/historical-holdings?*', async route => {
                const request = route.request();
                const params = new URL(request.url()).searchParams;
                const profile = request.headers()['x-profile-id'];
                requests.push({ path: 'historical', profile, asOf: params.get('as_of') });
                if (profile === '2' && params.get('as_of') !== '2026-10-01') {
                    bReadStarted();
                    const payload = await pendingB.promise;
                    return route.fulfill({ json: payload });
                }
                if (profile === '2' && params.get('as_of') === '2026-10-01') {
                    if (requests.filter(item => item.path === 'historical' && item.asOf === '2026-10-01').length === 1) {
                        return route.fulfill({ status: 503, json: { message: 'Synthetic date read unavailable.' } });
                    }
                    return route.fulfill({ json: { as_of: '2026-10-01', holdings: [], warnings: [], totals: { invested_value: 0, market_value: null, cash_balance: 0, total_value: 0, unrealized_profit: null, unrealized_gain_percent: null, valuation_complete: true }, completeness: { valuation_complete: true, total_value_complete: true, missing_price_count: 0, holding_count: 0 } } });
                }
                return route.fulfill({ json: { as_of: params.get('as_of'), holdings: [{ stock_id: 42, symbol: 'TCS', name: 'Main source row', quantity: 2, avg_buy_price: 500, invested_amount: 1000, price_available: false, as_of_price: null, market_value: null, unrealized_profit: null }], warnings: [], totals: { invested_value: 1000, market_value: null, cash_balance: 500, total_value: null, unrealized_profit: null, unrealized_gain_percent: null, valuation_complete: false }, completeness: { valuation_complete: false, total_value_complete: false, missing_price_count: 1, holding_count: 1 } } });
            });
            await page.route('**/api/portfolio/snapshots?*', async route => {
                const request = route.request();
                const params = new URL(request.url()).searchParams;
                const profile = request.headers()['x-profile-id'];
                requests.push({ path: 'snapshots', profile, limit: params.get('limit') });
                if (params.get('limit') === '90') {
                    rangeReadCount += 1;
                    if (rangeReadCount === 1) {
                        rangeReadStarted();
                        await pendingRange.promise;
                        return route.fulfill({ status: 503, json: { message: 'Synthetic range read unavailable.' } });
                    }
                    return route.fulfill({ json: { snapshots: [], meta: { count: 0, from_date: null, to_date: null, limit: 90 } } });
                }
                return route.fulfill({ json: { snapshots: [{ id: '72', snapshot_date: '2026-10-10', portfolio_value: '120000', invested_value: '100000' }], meta: { count: 1, from_date: '2026-10-10', to_date: '2026-10-10', limit: Number(params.get('limit')) } } });
            });
            await safety.seal();
            await page.goto('/portfolio/historical-holdings');
            await expect(page.getByRole('row').filter({ hasText: 'TCS' })).toBeVisible();
            await page.getByTitle('Switch portfolio (per tab)').click();
            await page.getByRole('button', { name: 'Research', exact: true }).click();
            await bReadStartedPromise;
            await expect(page.getByRole('status').filter({ hasText: 'Loading historical holdings' })).toBeVisible();
            await expect(page.getByRole('row').filter({ hasText: 'TCS' })).toHaveCount(0);
            await expect(page.locator('.card-header').filter({ hasText: 'Invested:' })).toContainText('Unavailable');
            const bResponse = page.waitForResponse(response => response.url().includes('/api/portfolio/historical-holdings') && response.request().headers()['x-profile-id'] === '2');
            pendingB.resolve({ as_of: requests.find(item => item.path === 'historical' && item.profile === '2').asOf, holdings: [{ stock_id: 84, symbol: 'INFY', name: 'Research source row', quantity: 2, avg_buy_price: 1000, invested_amount: 2000, price_available: false, as_of_price: null, market_value: null, unrealized_profit: null }], warnings: [], totals: { invested_value: 2000, market_value: null, cash_balance: 700, total_value: null, unrealized_profit: null, unrealized_gain_percent: null, valuation_complete: false }, completeness: { valuation_complete: false, total_value_complete: false, missing_price_count: 1, holding_count: 1 } });
            const settledB = await bResponse;
            expect(await settledB.finished()).toBeNull();
            await expect(page.getByRole('row').filter({ hasText: 'INFY' })).toBeVisible();
            await expect(page.getByRole('row').filter({ hasText: 'TCS' })).toHaveCount(0);
            expect(requests.filter(item => item.path === 'historical').map(item => item.profile)).toEqual(['1', '2']);

            await page.getByLabel('As-of date', { exact: true }).fill('01-Oct-2026');
            await page.getByLabel('As-of date', { exact: true }).press('Tab');
            await expect(page.locator('.container-fluid [role="alert"]').filter({ hasText: 'Synthetic date read unavailable.' })).toBeVisible();
            await expect(page.getByRole('row').filter({ hasText: 'INFY' })).toHaveCount(0);
            await expect(page.locator('.card-header').filter({ hasText: 'Invested:' })).toContainText('Unavailable');
            await expect(page.getByText(/No open holdings on this date/)).toHaveCount(0);
            await page.getByRole('button', { name: 'Refresh', exact: true }).click();
            await expect(page.getByText(/No open holdings on this date/)).toBeVisible();
            expect(requests.filter(item => item.path === 'historical' && item.profile === '2').map(item => item.asOf)).toEqual([requests[1].asOf, '2026-10-01', '2026-10-01']);

            await page.goto('/portfolio/snapshots');
            const snapshotRow = page.getByRole('row').filter({ has: page.getByLabel('Select snapshot 2026-10-10') });
            await expect(snapshotRow).toBeVisible();
            expect(requests.at(-1)).toMatchObject({ path: 'snapshots', profile: '2', limit: '365' });
            await page.getByRole('button', { name: '90 days', exact: true }).click();
            await rangeReadStartedPromise;
            await expect(page.getByRole('row').filter({ has: page.getByLabel('Select snapshot 2026-10-10') })).toHaveCount(0);
            await expect(page.locator('.card-header').filter({ hasText: 'Portfolio growth' })).toContainText('Unavailable');
            await expect(page.getByText(/No portfolio snapshots yet/)).toHaveCount(0);
            const rangeResponse = page.waitForResponse(response => response.url().includes('/api/portfolio/snapshots') && response.request().method() === 'GET' && new URL(response.url()).searchParams.get('limit') === '90');
            pendingRange.resolve();
            const settledRange = await rangeResponse;
            expect(await settledRange.finished()).toBeNull();
            await expect(page.getByRole('alert').filter({ hasText: 'Could not load portfolio snapshots' })).toBeVisible();
            await expect(page.getByText(/No portfolio snapshots yet/)).toHaveCount(0);
            await page.getByRole('button', { name: 'Refresh', exact: true }).click();
            await expect(page.getByText(/No portfolio snapshots yet/)).toBeVisible();
            await expect(page.locator('.card-header').filter({ hasText: 'Portfolio growth' })).toContainText('0 snapshot(s)');
            expect(requests.filter(item => item.path === 'snapshots').map(item => [item.profile, item.limit])).toEqual([['2', '365'], ['2', '90'], ['2', '90']]);
            expect(safety.writes).toEqual([]);
            expect(safety.blocked).toEqual([]);
        });
        test(`E2E-26 retries incomplete reports/settings/evidence and keeps what-if inclusion separate from saved preferences (${viewport.name})`, async ({ page }, testInfo) => {
            test.setTimeout(60000); journeyId(testInfo, 'E2E-26'); const safety = await prepare(page, viewport, 'performance', (method, path) => (method === 'PUT' && path === '/api/analysis/preferences') || (method === 'POST' && path === '/api/analysis/evidence')); const state = await analysisReads(page); state.unavailable = true; let preferencesFail = true; let accountFail = true; let evidenceFail = true; let exportFail = true;
            await page.route('**/api/analysis/preferences', route => { if (route.request().method() === 'GET') return route.fallback(); const body = route.request().postDataJSON(); if (body.scope === 'account') { expect(body).toMatchObject({ primary_benchmark_id: 2, risk_free_rate: 0.03, annualization_days: 252 }); expect(body).not.toHaveProperty('include_in_account_tax'); if (accountFail) { accountFail = false; return route.fulfill({ status: 503, json: { message: 'Synthetic account preferences unavailable.' } }); } state.accountPreferences = body; } else { expect(body).toMatchObject({ primary_benchmark_id: 2, risk_free_rate: 0.05, include_in_account_performance: false, include_in_account_tax: true }); if (preferencesFail) { preferencesFail = false; return route.fulfill({ status: 503, json: { message: 'Synthetic preferences unavailable.' } }); } state.preferences = body; } return route.fulfill({ json: { data: body } }); });
            await page.route('**/api/analysis/evidence', route => { expect(route.request().postDataJSON()).toEqual({ calculation_type: 'account_tax', financial_year: '2026-27', portfolio_ids: [2] }); if (evidenceFail) { evidenceFail = false; return route.fulfill({ status: 503, json: { message: 'Synthetic evidence unavailable.' } }); } return route.fulfill({ status: 201, json: { data: { id: 21 } } }); });
            await page.route('**/api/tax/exports/summary?*', route => { const params = new URL(route.request().url()).searchParams; expect(params.get('financial_year')).toBe('2026-27'); expect(params.getAll('portfolio_ids[]')).toEqual(['2']); if (exportFail) { exportFail = false; return route.fulfill({ status: 503, body: 'Synthetic export unavailable.' }); } return route.fulfill({ contentType: 'text/csv', headers: { 'content-disposition': 'attachment; filename="synthetic-tax-summary.csv"' }, body: 'evidence_kind,value\nsynthetic_route_contract,incomplete\n' }); });
            await safety.seal(); await page.goto('/portfolio/performance-tax'); await expect(page.locator('.container-fluid [role="alert"]').filter({ hasText: 'Synthetic analysis unavailable.' })).toBeVisible(); state.unavailable = false; await page.getByRole('button', { name: 'Calculate', exact: true }).click(); await expect(page.getByRole('heading', { name: 'Portfolio performance', exact: true })).toBeVisible(); await expect(page.getByText(/Completeness: incomplete/).first()).toBeVisible();
            await page.getByLabel('Primary benchmark', { exact: true }).selectOption('2'); await page.getByLabel('Annual risk-free rate', { exact: true }).fill('0.05'); await page.getByLabel('Include in Account performance', { exact: true }).uncheck(); await page.getByLabel('Include in Account Tax', { exact: true }).check(); await page.getByRole('button', { name: 'Save settings', exact: true }).click(); await expect(page.getByRole('alert').filter({ hasText: 'Analysis preferences could not be saved' })).toBeVisible(); await expect(page.getByLabel('Primary benchmark', { exact: true })).toHaveValue('2'); await page.getByRole('button', { name: 'Save settings', exact: true }).click(); await expect(page.getByText('Analysis preferences saved', { exact: true })).toBeVisible(); await expect(page.getByRole('button', { name: 'Calculate', exact: true })).toBeEnabled();
            await page.getByLabel('Account benchmark', { exact: true }).selectOption('2'); await page.getByLabel('Account risk-free rate', { exact: true }).fill('0.03'); await page.getByRole('button', { name: 'Save Account overrides', exact: true }).click(); await expect(page.getByRole('alert').filter({ hasText: 'Account analysis overrides could not be saved' })).toBeVisible(); await expect(page.getByLabel('Account risk-free rate', { exact: true })).toHaveValue('0.03'); await page.getByRole('button', { name: 'Save Account overrides', exact: true }).click(); await expect(page.getByText('Account analysis overrides saved', { exact: true })).toBeVisible(); await expect(page.getByRole('button', { name: 'Calculate', exact: true })).toBeEnabled();
            await page.getByLabel('Tax financial year', { exact: true }).fill('invalid'); await expect(page.getByRole('button', { name: 'Calculate', exact: true })).toBeDisabled(); await page.getByLabel('Tax financial year', { exact: true }).fill('2026-27'); await expect(page.getByRole('button', { name: 'Calculate', exact: true })).toBeEnabled(); const configured = JSON.stringify(state.preferences); await page.getByLabel('What-if portfolio selection (does not change configured inclusion)', { exact: true }).check(); await page.getByLabel('Retirement', { exact: true }).check(); await expect.poll(() => state.selections.some(ids => ids.join(',') === '2')).toBe(true); expect(JSON.stringify(state.preferences)).toBe(configured);
            const taxActions = page.getByRole('heading', { name: 'Account Tax · FY 2026-27', exact: true }).locator('..'); await taxActions.getByRole('button', { name: 'Preserve evidence', exact: true }).click(); await expect(page.getByRole('alert').filter({ hasText: 'Calculation evidence could not be preserved' })).toBeVisible(); await taxActions.getByRole('button', { name: 'Preserve evidence', exact: true }).click(); await expect(page.getByText('Immutable calculation evidence preserved', { exact: true })).toBeVisible(); await page.getByRole('button', { name: 'Export summary', exact: true }).click(); await expect(page.getByText('Tax CSV export failed', { exact: true })).toBeVisible(); const downloadPromise = page.waitForEvent('download'); await page.getByRole('button', { name: 'Export summary', exact: true }).click(); const download = await downloadPromise; expect(download.suggestedFilename()).toBe('synthetic-tax-summary.csv'); expect(await download.failure()).toBeNull();
            await page.reload(); await expect(page.getByLabel('Include in Account performance', { exact: true })).not.toBeChecked(); await expect(page.getByLabel('Include in Account Tax', { exact: true })).toBeChecked(); await expect(page.getByLabel('What-if portfolio selection (does not change configured inclusion)', { exact: true })).not.toBeChecked(); expect(safety.writes).toHaveLength(6); expect(safety.blocked).toEqual([]);
        });
        test(`E2E-27 retains failed tax drafts and requires fresh valid CSV preview before explicit import (${viewport.name})`, async ({ page }, testInfo) => {
            test.setTimeout(60000); journeyId(testInfo, 'E2E-27'); const safety = await prepare(page, viewport, 'tax-evidence', (method, path) => method === 'POST' && ['/api/tax/dividends', '/api/tax/losses', '/api/tax/opening-lots', '/api/tax/dividends/import'].includes(path)); await analysisReads(page); const recorded = []; const attempts = new Map(); let previews = 0; let applies = 0;
            for (const path of ['/api/tax/dividends', '/api/tax/losses', '/api/tax/opening-lots']) await page.route(`**${path}`, route => { const body = route.request().postDataJSON(); if (path.endsWith('/dividends')) expect(body).toEqual({ received_on: '2026-10-01', amount: '125', source_reference: 'Synthetic dividend evidence' }); else if (path.endsWith('/losses')) expect(body).toEqual({ loss_type: 'long_term', amount: '200', status: 'confirmed', reason: 'Synthetic confirmed loss evidence', financial_year: '2026-27' }); else expect(body).toEqual({ stock_id: '42', acquired_on: '2026-09-01', quantity: '10', cost_basis: '1000', reason: 'Synthetic opening evidence' }); const n = (attempts.get(path) || 0) + 1; attempts.set(path, n); if (n === 1) return route.fulfill({ status: 503, json: { message: 'Synthetic tax write unavailable.' } }); recorded.push({ path, body }); return route.fulfill({ status: 201, json: { data: { id: recorded.length } } }); });
            await page.route('**/api/tax/dividends/import', route => { const body = route.request().postData(); expect(body).toContain('generic-dividend-csv.v1'); const dryRun = /name="dry_run"\r\n\r\n1/.test(body); if (dryRun) { previews++; if (previews === 1) return route.fulfill({ status: 503, json: { message: 'Synthetic preview unavailable.' } }); return route.fulfill({ json: { data: { valid: previews > 2, candidate_rows: 1, duplicates: 0, imported: 0 } } }); } applies++; expect(previews).toBe(4); if (applies === 1) return route.fulfill({ status: 503, json: { message: 'Synthetic import unavailable.' } }); return route.fulfill({ json: { data: { valid: true, candidate_rows: 1, duplicates: 0, imported: 1 } } }); });
            await safety.seal(); await page.goto('/portfolio/performance-tax'); const panel = page.getByRole('region', { name: 'Record tax evidence', exact: true }); await expect(panel).toBeVisible();
            await panel.getByRole('button', { name: 'Record dividend', exact: true }).click(); expect(safety.writes).toHaveLength(0); await panel.getByLabel('Dividend date').fill('2026-10-01'); await panel.getByLabel('Dividend amount').fill('125'); await panel.getByLabel('Dividend reference').fill('Synthetic dividend evidence'); await panel.getByRole('button', { name: 'Record dividend', exact: true }).click(); await expect(panel.getByRole('alert')).toContainText('Tax evidence could not be recorded'); await expect(panel.getByLabel('Dividend amount')).toHaveValue('125'); await panel.getByRole('button', { name: 'Record dividend', exact: true }).click(); await expect.poll(() => recorded.length).toBe(1);
            await panel.getByLabel('Loss term').selectOption('long_term'); await panel.getByLabel('Loss status').selectOption('confirmed'); await panel.getByLabel('Loss amount').fill('200'); await panel.getByRole('button', { name: 'Record loss', exact: true }).click(); expect(safety.writes).toHaveLength(2); await panel.getByLabel('Loss reason').fill('Synthetic confirmed loss evidence'); await panel.getByRole('button', { name: 'Record loss', exact: true }).click(); await expect(panel.getByRole('alert')).toContainText('Tax evidence could not be recorded'); await expect(panel.getByLabel('Loss reason')).toHaveValue('Synthetic confirmed loss evidence'); await panel.getByRole('button', { name: 'Record loss', exact: true }).click(); await expect.poll(() => recorded.length).toBe(2);
            await panel.getByLabel('Opening lot stock').selectOption('42'); await panel.getByLabel('Acquired date').fill('2026-09-01'); await panel.getByLabel('Lot quantity').fill('10'); await panel.getByLabel('Lot cost basis').fill('1000'); await panel.getByLabel('Lot reason').fill('Synthetic opening evidence'); await panel.getByRole('button', { name: 'Record opening lot', exact: true }).click(); await expect(panel.getByRole('alert')).toContainText('Tax evidence could not be recorded'); await expect(panel.getByLabel('Lot cost basis')).toHaveValue('1000'); await panel.getByRole('button', { name: 'Record opening lot', exact: true }).click(); await expect.poll(() => recorded.length).toBe(3);
            const upload = name => panel.getByLabel('Dividend CSV · generic-dividend-csv.v1', { exact: true }).setInputFiles({ name, mimeType: 'text/csv', buffer: Buffer.from('evidence_kind\nSYNTHETIC_ROUTE_CONTRACT_ONLY\n') }); await expect(panel.getByRole('button', { name: 'Import validated rows' })).toBeDisabled(); await upload('synthetic-dividend.csv'); await panel.getByRole('button', { name: 'Preview', exact: true }).click(); await expect(panel.getByRole('alert')).toContainText('Dividend CSV preview could not be loaded'); await expect(panel.getByRole('button', { name: 'Import validated rows' })).toBeDisabled(); await panel.getByRole('button', { name: 'Preview', exact: true }).click(); await expect(panel).toContainText('Valid false'); await expect(panel.getByRole('button', { name: 'Import validated rows' })).toBeDisabled(); await upload('corrected-synthetic-dividend.csv'); await panel.getByRole('button', { name: 'Preview', exact: true }).click(); await expect(panel.getByRole('button', { name: 'Import validated rows' })).toBeEnabled(); expect(applies).toBe(0); await upload('reviewed-synthetic-dividend.csv'); await expect(panel.getByRole('button', { name: 'Import validated rows' })).toBeDisabled(); await panel.getByRole('button', { name: 'Preview', exact: true }).click(); await panel.getByRole('button', { name: 'Import validated rows' }).click(); await expect(panel.getByRole('alert')).toContainText('Dividend CSV import could not be confirmed'); await panel.getByRole('button', { name: 'Import validated rows' }).click(); await expect(panel).toContainText('imported 1');
            const accessibility = await new AxeBuilder({ page }).include('[aria-label="Record tax evidence"]').withTags(['wcag2a', 'wcag2aa']).analyze(); expect(accessibility.violations).toEqual([]); expect(recorded).toHaveLength(3); expect(previews).toBe(4); expect(applies).toBe(2); expect(safety.writes).toHaveLength(12); expect(safety.blocked).toEqual([]);
        });
    });
}
