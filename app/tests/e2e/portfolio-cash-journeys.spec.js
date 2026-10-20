import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { TEST_PORTFOLIO } from '../js/tos/fixtures/tosApi.js';
import { createApiFailureDiagnosticCollector, installApiFailureDiagnosticBoundary, handleApiFailureDiagnostic, registerApiFailureDiagnosticAfterEach } from './apiFailureDiagnosticBoundary.js';

registerApiFailureDiagnosticAfterEach(test);



const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
async function prepare(page, viewport, allowed) {
    await seedDeterministicJourney(page, `portfolio-cash-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await installInvestorWorkflowApiMocks(page);
    const blocked = [];
    const writes = [];
    const diagnosticReports = createApiFailureDiagnosticCollector(page);
    await installApiFailureDiagnosticBoundary(page, { origin: 'http://127.0.0.1:4177', collector: diagnosticReports });
    const seal = () => page.route('**/*', async route => {
        const request = route.request(); const url = new URL(request.url());
        if (await handleApiFailureDiagnostic(route, { origin: 'http://127.0.0.1:4177', collector: diagnosticReports })) return;
        if (url.origin !== 'http://127.0.0.1:4177' || (/^(POST|PUT|PATCH|DELETE)$/.test(request.method()) && !allowed(request.method(), url.pathname))) {
            blocked.push(`${request.method()} ${url.origin}${url.pathname}`); return route.abort();
        }
        if (/^(POST|PUT|PATCH|DELETE)$/.test(request.method()) && !url.pathname.endsWith('/logs/frontend')) writes.push({ method: request.method(), path: url.pathname, payload: request.postDataJSON() });
        return route.fallback();
    });
    await page.route('**/api/replays', route => route.fulfill({ json: { data: [] } }));
    return { blocked, writes, seal };
}
function portfolioWrites(method, path) {
    return path === '/api/logs/frontend' || (method === 'POST' && /^\/api\/portfolios(?:$|\/1\/clone-as-paper$|\/2\/set-default$|\/3\/simulation\/(?:pause|resume)$)/.test(path)) || (method === 'PUT' && path === '/api/portfolios/3') || (method === 'DELETE' && path === '/api/portfolios/3');
}
const rowFor = (page, name) => page.locator('li.list-group-item').filter({ has: page.getByText(name, { exact: true }) });

for (const viewport of VIEWPORTS) {
    test.describe(`Portfolio and cash ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });
        test(`E2E-20 recovers portfolio creation, rename, default and confirmed deletion (${viewport.name})`, async ({ page }, testInfo) => {
            test.setTimeout(60000); journeyId(testInfo, 'E2E-20');
            const evidence = await prepare(page, viewport, portfolioWrites);
            let portfolios = [{ ...TEST_PORTFOLIO, portfolio_type: 'live' }, { id: 2, name: 'Retirement', portfolio_type: 'live', is_default: false }];
            let createFails = true; let renameFails = true; let defaultFails = true; let deleteFails = true;
            await page.route('**/api/portfolios', route => {
                if (route.request().method() === 'GET') return route.fulfill({ json: { data: portfolios } });
                expect(route.request().postDataJSON()).toEqual({ name: 'Research', portfolio_type: 'live' });
                if (createFails) { createFails = false; return route.fulfill({ status: 503, json: { message: 'Synthetic portfolio create unavailable.' } }); }
                portfolios.push({ id: 3, name: 'Research', portfolio_type: 'live', is_default: false });
                return route.fulfill({ status: 201, json: { data: portfolios[2] } });
            });
            await page.route('**/api/portfolios/3', route => {
                if (route.request().method() === 'PUT') {
                    expect(route.request().postDataJSON()).toEqual({ name: 'Research Long Term' });
                    if (renameFails) { renameFails = false; return route.fulfill({ status: 503, json: { message: 'Synthetic rename unavailable.' } }); }
                    portfolios = portfolios.map(p => p.id === 3 ? { ...p, name: 'Research Long Term' } : p);
                    return route.fulfill({ json: { data: portfolios.find(p => p.id === 3) } });
                }
                expect(route.request().method()).toBe('DELETE');
                if (deleteFails) { deleteFails = false; return route.fulfill({ status: 503, json: { message: 'Synthetic delete unavailable.' } }); }
                portfolios = portfolios.filter(p => p.id !== 3); return route.fulfill({ status: 204, body: '' });
            });
            await page.route('**/api/portfolios/2/set-default', route => {
                if (defaultFails) { defaultFails = false; return route.fulfill({ status: 503, json: { message: 'Synthetic default unavailable.' } }); }
                portfolios = portfolios.map(p => ({ ...p, is_default: p.id === 2 })); return route.fulfill({ json: { data: portfolios[1] } });
            });
            await evidence.seal(); await page.goto('/portfolios');
            const create = page.getByRole('button', { name: 'Create', exact: true });
            await page.getByLabel('Name', { exact: true }).fill('Invalid!'); await expect(create).toBeDisabled(); expect(evidence.writes).toEqual([]);
            await page.getByLabel('Name', { exact: true }).fill('Research'); await create.click();
            await expect(page.getByText('Synthetic portfolio create unavailable.', { exact: true })).toBeVisible();
            await expect(page.getByLabel('Name', { exact: true })).toHaveValue('Research'); await expect(rowFor(page, 'Research')).toHaveCount(0);
            await create.click(); await expect(rowFor(page, 'Research')).toBeVisible();
            await rowFor(page, 'Research').getByRole('button', { name: 'Rename', exact: true }).click();
            await page.getByLabel('New name for Research', { exact: true }).fill('Research Long Term');
            const editor = page.locator('li.list-group-item').filter({ has: page.getByLabel('New name for Research', { exact: true }) });
            await editor.getByRole('button', { name: 'Save', exact: true }).click();
            await expect(page.getByText('Synthetic rename unavailable.', { exact: true })).toBeVisible(); await expect(page.getByLabel('New name for Research', { exact: true })).toHaveValue('Research Long Term');
            await editor.getByRole('button', { name: 'Cancel', exact: true }).click(); await expect(rowFor(page, 'Research')).toBeVisible();
            await rowFor(page, 'Research').getByRole('button', { name: 'Rename', exact: true }).click(); await page.getByLabel('New name for Research', { exact: true }).fill('Research Long Term'); await editor.getByRole('button', { name: 'Save', exact: true }).click();
            const retirement = rowFor(page, 'Retirement'); await retirement.getByRole('button', { name: 'Set default' }).click();
            await expect(page.getByText('Synthetic default unavailable.', { exact: true })).toBeVisible(); await expect(rowFor(page, 'Main').getByText('Default', { exact: true })).toBeVisible();
            await retirement.getByRole('button', { name: 'Set default' }).click(); await expect(retirement.getByText('Default', { exact: true })).toBeVisible();
            await rowFor(page, 'Research Long Term').getByRole('button', { name: 'Use in this tab' }).click(); await expect(rowFor(page, 'Research Long Term').getByText('Active in this tab')).toBeVisible(); await expect(rowFor(page, 'Research Long Term').getByRole('button', { name: 'Delete', exact: true })).toHaveCount(0);
            await rowFor(page, 'Main').getByRole('button', { name: 'Use in this tab' }).click();
            page.once('dialog', async dialog => { expect(dialog.message()).toContain('cannot be undone'); await dialog.dismiss(); });
            await rowFor(page, 'Research Long Term').getByRole('button', { name: 'Delete', exact: true }).click(); expect(evidence.writes.filter(w => w.method === 'DELETE')).toEqual([]);
            page.once('dialog', dialog => dialog.accept()); await rowFor(page, 'Research Long Term').getByRole('button', { name: 'Delete', exact: true }).click();
            await expect(page.getByText('Synthetic delete unavailable.', { exact: true })).toBeVisible(); await expect(rowFor(page, 'Research Long Term')).toBeVisible();
            page.once('dialog', dialog => dialog.accept()); await rowFor(page, 'Research Long Term').getByRole('button', { name: 'Delete', exact: true }).click();
            await expect(rowFor(page, 'Research Long Term')).toHaveCount(0); await page.reload(); await expect(retirement.getByText('Default', { exact: true })).toBeVisible();
            expect(portfolios.map(p => p.id)).toEqual([1, 2]); expect(evidence.blocked).toEqual([]); expect(evidence.writes.map(w => `${w.method} ${w.path}`)).toEqual(['POST /api/portfolios', 'POST /api/portfolios', 'PUT /api/portfolios/3', 'PUT /api/portfolios/3', 'POST /api/portfolios/2/set-default', 'POST /api/portfolios/2/set-default', 'DELETE /api/portfolios/3', 'DELETE /api/portfolios/3']);
        });

        test(`E2E-21 clones separate paper evidence and retries pause without touching the live source (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-21'); const evidence = await prepare(page, viewport, portfolioWrites);
            let portfolios = [{ ...TEST_PORTFOLIO, portfolio_type: 'live' }]; let cloneFails = true; let pauseFails = true;
            await page.route('**/api/portfolios', route => route.fulfill({ json: { data: portfolios } }));
            await page.route('**/api/portfolios/1/clone-as-paper', route => {
                expect(route.request().postDataJSON()).toEqual({ name: 'Main Paper', copy_holdings: false, starting_cash: 75000, simulation_price_method: 'next_close' });
                if (cloneFails) { cloneFails = false; return route.fulfill({ status: 503, json: { message: 'Synthetic clone unavailable.' } }); }
                portfolios.push({ id: 3, name: 'Main Paper', is_default: false, portfolio_type: 'paper', simulation_state: 'running' }); return route.fulfill({ status: 201, json: { data: portfolios[1] } });
            });
            await page.route(/\/api\/portfolios\/3\/simulation\/(pause|resume)$/, route => {
                const action = new URL(route.request().url()).pathname.split('/').pop();
                if (action === 'pause' && pauseFails) { pauseFails = false; return route.fulfill({ status: 503, json: { message: 'Synthetic pause unavailable.' } }); }
                portfolios = portfolios.map(p => p.id === 3 ? { ...p, simulation_state: action === 'pause' ? 'paused' : 'running' } : p); return route.fulfill({ json: { data: portfolios[1] } });
            });
            await evidence.seal(); await page.goto('/portfolios'); const main = rowFor(page, 'Main');
            await main.getByRole('button', { name: 'Clone as Paper' }).click(); await main.getByRole('button', { name: 'Cancel', exact: true }).click(); expect(evidence.writes).toEqual([]);
            await main.getByRole('button', { name: 'Clone as Paper' }).click(); await main.getByLabel('Simulated cash').fill('75000'); await main.getByLabel('Fill price').selectOption('next_close'); await main.getByLabel('Copy holdings').uncheck();
            await main.getByRole('button', { name: 'Clone', exact: true }).click(); await expect(page.getByText('Synthetic clone unavailable.', { exact: true })).toBeVisible(); await expect(main.getByLabel('Simulated cash')).toHaveValue('75000');
            await main.getByRole('button', { name: 'Clone', exact: true }).click(); const paper = rowFor(page, 'Main Paper'); await expect(paper.getByText('PAPER', { exact: true })).toBeVisible(); await expect(main.getByText('LIVE', { exact: true })).toBeVisible();
            await paper.getByRole('button', { name: 'Pause simulation' }).click(); await expect(page.getByText('Synthetic pause unavailable.', { exact: true })).toBeVisible(); await expect(paper.getByRole('button', { name: 'Pause simulation' })).toBeVisible();
            await paper.getByRole('button', { name: 'Pause simulation' }).click(); await expect(paper.getByRole('button', { name: 'Resume simulation' })).toBeVisible(); await paper.getByRole('button', { name: 'Resume simulation' }).click();
            await page.reload(); await expect(paper.getByRole('button', { name: 'Pause simulation' })).toBeVisible(); expect(portfolios[0]).toEqual({ ...TEST_PORTFOLIO, portfolio_type: 'live' }); expect(evidence.blocked).toEqual([]); expect(evidence.writes).toHaveLength(5);
        });

        test(`E2E-22 preserves cash drafts on failure and distinguishes reservations from ledger records (${viewport.name})`, async ({ page }, testInfo) => {
            test.setTimeout(60000); journeyId(testInfo, 'E2E-22');
            const evidence = await prepare(page, viewport, (method, path) => path === '/api/logs/frontend' || (method === 'POST' && /^\/api\/cash\/(deposit|withdraw|adjust)$/.test(path)));
            let balance = 10000; const reserved = 2000; let entries = []; const fail = { deposit: true, withdraw: true, adjust: true };
            await page.route(/\/api\/cash(?:\?.*)?$/, route => route.fulfill({ json: { data: { cash_balance: balance, reserved_cash: reserved, available_physical_cash: balance - reserved, available_investable_cash: balance - reserved, reserve_shortfall_exists: true, strategies: [], reservations: [{ recommendation_id: 41, symbol: 'INFY', ui_label: 'Open', reserved_amount: reserved, suggested_quantity: 2, reference_price: 1000 }] } } }));
            await page.route('**/api/cash/statement*', route => route.fulfill({ json: { data: { entries, opening_balance: 10000, closing_balance: balance } } }));
            for (const resource of ['recalls', 'bridge-loans', 'pending-sale-proceeds']) await page.route(`**/api/v1/capital/${resource}*`, route => route.fulfill({ json: { data: [] } }));
            for (const operation of ['deposit', 'withdraw', 'adjust']) await page.route(`**/api/cash/${operation}`, route => {
                const payload = route.request().postDataJSON(); expect(payload).toEqual({ amount: operation === 'deposit' ? 3000 : operation === 'withdraw' ? 1000 : -500, remarks: `Synthetic ${operation}`, transaction_date: '2026-10-10' });
                if (fail[operation]) { fail[operation] = false; return route.fulfill({ status: 503, json: { message: `Synthetic ${operation} unavailable.` } }); }
                const amount = operation === 'withdraw' ? -payload.amount : payload.amount; balance += amount; entries.push({ id: entries.length + 1, entry_date: payload.transaction_date, entry_type: operation === 'withdraw' ? 'withdrawal' : operation === 'adjust' ? 'adjustment' : 'deposit', amount, running_balance: balance, reason: payload.remarks }); return route.fulfill({ status: 201, json: { data: entries.at(-1) } });
            });
            await evidence.seal(); await page.goto('/cash'); await page.getByRole('button', { name: 'See reservation details' }).click(); await expect(page.getByRole('link', { name: '#41', exact: true })).toBeVisible();
            const form = page.locator('form').filter({ has: page.getByLabel('Amount', { exact: true }) });
            for (const operation of ['deposit', 'withdraw', 'adjust']) {
                const label = operation[0].toUpperCase() + operation.slice(1);
                await page.getByRole('group', { name: 'Cash operation' }).getByRole('button', { name: label, exact: true }).click();
                if (operation === 'withdraw') { await page.getByLabel('Amount', { exact: true }).fill('12000'); await form.getByRole('button', { name: label, exact: true }).click(); expect(evidence.writes.filter(w => w.path.endsWith('/withdraw'))).toHaveLength(0); }
                await page.getByLabel('Amount', { exact: true }).fill(operation === 'deposit' ? '3000' : operation === 'withdraw' ? '1000' : '-500');
                await page.getByLabel('Transaction date', { exact: true }).fill('10-Oct-2026'); await page.getByLabel('Transaction date', { exact: true }).press('Tab');
                if (operation === 'adjust') { await page.getByLabel('Remarks (required)', { exact: true }).fill(''); await form.getByRole('button', { name: label, exact: true }).click(); expect(evidence.writes.filter(w => w.path.endsWith('/adjust'))).toHaveLength(0); }
                await page.getByLabel(operation === 'adjust' ? 'Remarks (required)' : 'Remarks', { exact: true }).fill(`Synthetic ${operation}`);
                await form.getByRole('button', { name: label, exact: true }).click(); await expect(page.getByText(`Synthetic ${operation} unavailable.`, { exact: true })).toBeVisible(); await expect(page.getByLabel('Amount', { exact: true })).toHaveValue(operation === 'deposit' ? '3000' : operation === 'withdraw' ? '1000' : '-500');
                await form.getByRole('button', { name: label, exact: true }).click(); await expect(page.getByRole('cell', { name: `Synthetic ${operation}`, exact: true })).toBeVisible();
            }
            await page.reload(); for (const operation of ['deposit', 'withdraw', 'adjust']) await expect(page.getByRole('cell', { name: `Synthetic ${operation}`, exact: true })).toBeVisible();
            expect(balance).toBe(11500); expect(entries).toHaveLength(3); expect(evidence.writes).toHaveLength(6); expect(evidence.blocked).toEqual([]);
        });
    });
}
