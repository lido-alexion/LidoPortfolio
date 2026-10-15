import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
async function prepare(page, viewport, allowedWrites = []) {
    await seedDeterministicJourney(page, `investor-overview-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    const rejected = [];
    const seal = () => page.route('**/*', route => {
        const request = route.request();
        const url = new URL(request.url());
        const read = ['GET', 'HEAD'].includes(request.method());
        const allowed = [['POST', '/api/logs/frontend'], ...allowedWrites].some(([method, path]) => request.method() === method && url.pathname === path);
        if (url.origin !== 'http://127.0.0.1:4177' || (!read && !allowed)) {
            rejected.push(`${request.method()} ${url.pathname}`);
            return route.abort();
        }
        return route.fallback();
    });
    await installInvestorWorkflowApiMocks(page);
    return { rejected, seal };
}

for (const viewport of VIEWPORTS) {
    test.describe(`Investor overview ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });
        test(`E2E-14 retains prior dashboard values on refresh failure, retries and restores cached values (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-14');
            const { rejected, seal } = await prepare(page, viewport);
            let value = 100000;
            let fail = false;
            let reads = 0;
            await page.route('**/api/dashboard', route => {
                reads++;
                if (fail) { fail = false; return route.fulfill({ status: 503, json: { message: 'Synthetic overview unavailable.' } }); }
                return route.fulfill({ json: { portfolio_value: value, invested_value: 90000, total_gain_loss: value - 90000, portfolio_growth: [], alerts: [] } });
            });
            await page.route('**/api/v1/broker/status', route => route.fulfill({ json: { success: true, data: { connected: false, usable: false, configured: true } } }));
            await page.route('**/api/reconciliation', route => route.fulfill({ json: { data: { execution_blocked: false, discrepancies: [], runs: [] } } }));
            await seal();
            await page.goto('/');
            const metric = page.locator('[data-assistant-label="Portfolio Value"]');
            await expect(metric).toContainText('1,00,000');
            fail = true;
            await page.getByRole('button', { name: 'Refresh dashboard', exact: true }).click();
            await expect(page.getByRole('alert').filter({ hasText: 'Failed to load dashboard' })).toBeVisible();
            await expect(metric).toContainText('1,00,000');
            value = 120000;
            await page.getByRole('button', { name: 'Refresh dashboard', exact: true }).click();
            await expect(metric).toContainText('1,20,000');
            await expect(page.getByRole('alert').filter({ hasText: 'Failed to load dashboard' })).toHaveCount(0);
            const freshReads = reads;
            await page.reload();
            await expect(metric).toContainText('1,20,000');
            await expect(page.getByText(/Last refreshed/)).toBeVisible();
            expect(reads).toBe(freshReads);
            expect(rejected).toEqual([]);
        });

        test(`E2E-48 recovers named Dashboard reads, autosave and default confirmation (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-48');
            const { rejected, seal } = await prepare(page, viewport, [['PUT', '/api/dashboard-layouts/7']]);
            const layout = {
                schema: 'stox.dashboard.layout',
                version: 1,
                locked: false,
                desktop: { cards: [], summaryFields: [] },
                mobile: { cards: [], summaryFields: [] },
            };
            let listUnavailable = true;
            let named = { id: 7, name: 'Investor', is_default: false, definition: layout, revision: 1 };
            let autosaveAttempts = 0;
            let defaultAttempts = 0;
            await page.route('**/api/dashboard-layouts', route => {
                expect(route.request().method()).toBe('GET');
                if (listUnavailable) {
                    return route.fulfill({ status: 503, json: { message: 'Synthetic named layouts unavailable.' } });
                }
                return route.fulfill({ json: { data: [named] } });
            });
            await page.route('**/api/dashboard-layouts/7', route => {
                expect(route.request().method()).toBe('PUT');
                const body = route.request().postDataJSON();
                if (body.definition) {
                    autosaveAttempts++;
                    expect(body.expected_revision).toBe(named.revision);
                    expect(body.definition[viewport.name === 'mobile' ? 'mobile' : 'desktop'].summaryFields.find(field => field.id === 'xirr')?.visible).toBe(false);
                    if (autosaveAttempts === 1) return route.fulfill({ status: 503, json: { message: 'Synthetic layout save unavailable.' } });
                    named = { ...named, definition: body.definition, revision: named.revision + 1 };
                    return route.fulfill({ json: { data: named } });
                }
                defaultAttempts++;
                expect(body).toEqual({ is_default: true });
                if (defaultAttempts === 1) return route.fulfill({ status: 503, json: { message: 'Synthetic default unavailable.' } });
                named = { ...named, is_default: true };
                return route.fulfill({ json: { data: named } });
            });
            await seal();
            await page.goto('/');
            const toolbar = page.getByTestId('dashboard-layout-toolbar');
            await expect(toolbar.getByRole('alert')).toContainText('Named dashboards could not be loaded');
            await expect(toolbar.getByLabel('Named dashboard')).toHaveCount(0);
            listUnavailable = false;
            await toolbar.getByRole('button', { name: 'Retry named dashboards' }).click();
            await toolbar.getByLabel('Named dashboard').selectOption('7');
            await toolbar.getByRole('button', { name: 'Customize' }).click();
            await toolbar.getByLabel('Editing variant').selectOption(viewport.name === 'mobile' ? 'mobile' : 'desktop');
            await toolbar.getByRole('checkbox', { name: 'XIRR' }).uncheck();
            await expect(toolbar.getByText('Failed', { exact: true })).toBeVisible();
            await toolbar.getByRole('button', { name: 'Retry named dashboard save' }).click();
            await expect(toolbar.getByText('Saved', { exact: true })).toBeVisible();
            await toolbar.getByRole('button', { name: 'Set default' }).click();
            await expect(toolbar.getByRole('alert')).toContainText('Default dashboard could not be confirmed');
            await expect(toolbar.getByLabel('Named dashboard')).toHaveValue('7');
            await toolbar.getByRole('button', { name: 'Set default' }).click();
            await expect(toolbar.getByLabel('Named dashboard')).toContainText('Investor (default)');
            const accessibility = await new AxeBuilder({ page }).include('[data-testid="dashboard-layout-toolbar"]').withTags(['wcag2a', 'wcag2aa']).analyze();
            expect(accessibility.violations).toEqual([]);
            expect({ autosaveAttempts, defaultAttempts }).toEqual({ autosaveAttempts: 2, defaultAttempts: 2 });
            expect(rejected).toEqual([]);
        });

        test(`E2E-15 recovers unavailable stock history with temporary manual RS then retries fetched analysis (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-15');
            const { rejected, seal } = await prepare(page, viewport, [['POST', '/api/analytics/explore']]);
            let available = false;
            await page.route('**/api/indexes', route => route.fulfill({ json: { data: { primary_symbol: 'NIFTY50', indexes: [{ symbol: 'NIFTY50', name: 'Nifty 50', exchange: 'NSE', is_primary: true }] } } }));
            await page.route('**/api/analytics/explore', route => {
                expect(route.request().postDataJSON()).toMatchObject({ symbol: 'TCS', benchmark_symbol: 'NIFTY50', periods: [1, 3, 6, 12] });
                if (!available) return route.fulfill({ status: 422, json: { message: 'Synthetic history unavailable.' } });
                const closes = Object.fromEntries([1, 3, 6, 12].map(months => [`${months}m`, { stock_start_close: 100, stock_end_close: 120, benchmark_start_close: 1000, benchmark_end_close: 1100, start_date: '2026-04-01' }]));
                return route.fulfill({ json: { data: { stock: { symbol: 'TCS', name: 'Tata Consultancy Services', exchange: 'NSE' }, latest_close: 120, benchmark: { symbol: 'NIFTY50', name: 'Nifty 50', latest_close: 1100 }, period_closes: closes, relative_strength: { '1m': 10, '3m': 10, '6m': 10, '12m': 10 }, chart: [], normalized_gain_chart: [] } } });
            });
            await seal();
            await page.goto('/explorer?symbol=TCS&benchmark=NIFTY50');
            await expect(page.getByText('Synthetic history unavailable.', { exact: true })).toBeVisible();
            await expect(page.getByLabel('Benchmark index', { exact: true })).toHaveValue('NIFTY50');
            const manual = page.getByRole('button', { name: 'Calculate RS from manual values', exact: true });
            await expect(manual).toBeDisabled();
            await page.getByLabel('TCS latest close', { exact: true }).fill('120');
            await page.getByLabel('NIFTY50 latest close', { exact: true }).fill('1100');
            await page.getByLabel(/^TCS close 6 months ago/).fill('100');
            await page.getByLabel(/^NIFTY50 close 6 months ago/).fill('1000');
            await manual.click();
            await expect(page.getByText('Manual values are applied. Expand to edit and recalculate.', { exact: true })).toBeVisible();
            await expect(page.getByText('10.00%', { exact: true }).first()).toBeVisible();
            available = true;
            await page.getByRole('button', { name: 'Calculate relative strength', exact: true }).click();
            await expect(page.getByText('Manual Relative Strength Input', { exact: true })).toHaveCount(0);
            await expect(page.getByText('Relative strength (1 month)', { exact: true })).toBeVisible();
            await expect(page).toHaveURL(/symbol=TCS.*benchmark=NIFTY50/);
            const a11y = await new AxeBuilder({ page }).include('.contentPane').withTags(['wcag2a', 'wcag2aa']).analyze();
            expect(a11y.violations).toEqual([]);
            expect(rejected).toEqual([]);
        });

        test(`E2E-16 retries alert creation/evaluation, persists disable and respects delete confirmation (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-16');
            const { rejected, seal } = await prepare(page, viewport, [['POST', '/api/alert-policies'], ['PUT', '/api/alert-policies/73'], ['DELETE', '/api/alert-policies/73'], ['POST', '/api/alert-policies/evaluate']]);
            let policies = [];
            let failSave = true;
            let failEvaluation = true;
            const meta = { columns: [{ key: 'latest_close', label: 'Latest close' }], condition_operators: [{ value: 'lt', label: 'Less than' }], compare_types: [{ value: 'constant', label: 'Constant' }], action_types: [{ value: 'sell', label: 'Sell' }] };
            await page.route('**/api/alert-policies/meta', route => route.fulfill({ json: { data: meta } }));
            await page.route('**/api/alert-policies', route => {
                if (route.request().method() === 'GET') return route.fulfill({ json: { data: policies } });
                if (failSave) { failSave = false; return route.fulfill({ status: 503, json: { message: 'Synthetic alert save unavailable.' } }); }
                const body = route.request().postDataJSON();
                expect(body).toMatchObject({ name: 'Close below threshold', condition_column: 'latest_close', condition_operator: 'lt', compare_type: 'constant', compare_constant: 100, is_enabled: true });
                policies = [{ ...body, id: 73, is_system: false }];
                return route.fulfill({ status: 201, json: { data: policies[0] } });
            });
            await page.route('**/api/alert-policies/73', route => {
                if (route.request().method() === 'DELETE') { policies = []; return route.fulfill({ status: 204, body: '' }); }
                expect(route.request().method()).toBe('PUT');
                policies = [{ ...policies[0], ...route.request().postDataJSON() }];
                return route.fulfill({ json: { data: policies[0] } });
            });
            await page.route('**/api/alert-policies/evaluate', route => {
                if (failEvaluation) { failEvaluation = false; return route.fulfill({ status: 503, json: { message: 'Synthetic evaluation unavailable.' } }); }
                return route.fulfill({ json: { data: { policies: 0, holdings_checked: 0, generated: 0, skipped: 0, details: [] } } });
            });
            await seal();
            await page.goto('/settings/alert-policies');
            await page.getByRole('button', { name: 'New policy', exact: true }).click();
            await page.getByLabel('Alert name', { exact: true }).fill('Close below threshold');
            await page.getByLabel('Alert definition', { exact: true }).fill('Observe a close below 100.');
            await page.getByLabel('Column', { exact: true }).selectOption('latest_close');
            await page.getByLabel('Compare value type', { exact: true }).selectOption('constant');
            await page.getByLabel('Constant value', { exact: true }).fill('100');
            await page.getByLabel('Alert message', { exact: true }).fill('Review the holding.');
            await page.getByRole('button', { name: 'Create policy', exact: true }).click();
            await expect(page.getByText('Synthetic alert save unavailable.', { exact: true })).toBeVisible();
            await expect(page.getByLabel('Alert name', { exact: true })).toHaveValue('Close below threshold');
            await page.getByRole('button', { name: 'Create policy', exact: true }).click();
            await expect(page.locator('.list-group-item').filter({ hasText: 'Close below threshold' })).toBeVisible();
            await page.getByRole('button', { name: 'Edit', exact: true }).click();
            await page.getByLabel('Policy enabled', { exact: true }).uncheck();
            await page.getByRole('button', { name: 'Update policy', exact: true }).click();
            await page.reload();
            await expect(page.locator('.list-group-item').filter({ hasText: 'Close below threshold' })).toContainText('Disabled');
            await page.getByRole('button', { name: 'Run policies now', exact: true }).click();
            await expect(page.getByText('Synthetic evaluation unavailable.', { exact: true })).toBeVisible();
            await page.getByRole('button', { name: 'Run policies now', exact: true }).click();
            await expect(page.getByText('Last evaluation', { exact: true })).toBeVisible();
            await expect(page.getByText('0 generated', { exact: true })).toBeVisible();
            page.once('dialog', dialog => dialog.dismiss());
            await page.getByRole('button', { name: 'Delete', exact: true }).click();
            expect(policies).toHaveLength(1);
            page.once('dialog', dialog => dialog.accept());
            await page.getByRole('button', { name: 'Delete', exact: true }).click();
            await expect(page.getByText('No alert policies yet. Create one to generate alerts from holdings.', { exact: true })).toBeVisible();
            expect(policies).toEqual([]);
            expect(rejected).toEqual([]);
        });
    });
}
