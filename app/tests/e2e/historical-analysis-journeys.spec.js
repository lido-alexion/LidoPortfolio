import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
const original = { id: 11, name: 'Historical original', status: 'completed', strategy_name: 'Historical strategy', strategy_version: '1.0.0', strategy_version_id: 71, from_date: '2026-09-01', to_date: '2026-10-01', range_key: '1y', initial_capital: 250000, tags: ['research'], notes: 'Original research', execution_assumptions: { price_method: 'next_close', adverse_slippage_percent: 0.25 }, statistics: { return_pct: null }, snapshots: [], trades: [], transactions: [], timeline: [] };
async function prepare(page, viewport, name, allowed) {
    await seedDeterministicJourney(page, `historical-${name}-${viewport.name}`); await page.setViewportSize({ width: viewport.width, height: viewport.height }); await installInvestorWorkflowApiMocks(page);
    const blocked = []; const writes = [];
    return { blocked, writes, seal: () => page.route('**/*', route => {
        const request = route.request(); const url = new URL(request.url()); const write = /^(POST|PUT|PATCH|DELETE)$/.test(request.method());
        if (url.origin !== 'http://127.0.0.1:4177' || (write && url.pathname !== '/api/logs/frontend' && !allowed(request.method(), url.pathname))) { blocked.push(`${request.method()} ${url.pathname}`); return route.abort(); }
        if (write && url.pathname !== '/api/logs/frontend') writes.push({ method: request.method(), path: url.pathname, body: request.postDataJSON() }); return route.fallback();
    }) };
}
const backtestRow = (page, name) => page.getByRole('row').filter({ has: page.getByRole('link', { name, exact: true }) });

for (const viewport of VIEWPORTS) {
    test.describe(`Historical analysis ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });
        test(`E2E-23 retries history/start/detail, preserves metadata draft and duplicates a separate current-strategy experiment (${viewport.name})`, async ({ page }, testInfo) => {
            test.setTimeout(60000); journeyId(testInfo, 'E2E-23');
            const safety = await prepare(page, viewport, 'backtest-start', (method, path) => (method === 'POST' && path === '/api/v1/backtests') || (method === 'PUT' && path === '/api/v1/backtests/12'));
            let historyUnavailable = true; let detailUnavailable = true; let startFails = true; let saveFails = true; let runs = [{ ...original }]; let starts = 0;
            await page.route('**/api/v1/backtests/meta', route => route.fulfill({ json: { data: { ranges: [{ id: '1y', label: '1 year' }], configurable_parameters: [] } } }));
            await page.route('**/api/v1/backtests', route => {
                if (route.request().method() === 'GET') return historyUnavailable ? route.fulfill({ status: 503, json: { message: 'Synthetic history unavailable.' } }) : route.fulfill({ json: { data: { runs } } });
                starts++; const body = route.request().postDataJSON();
                if (starts <= 2) {
                    expect(body).toMatchObject({ name: 'New research', range_key: '1y', initial_capital: 250000, notes: 'New notes', tags: ['research', 'bounded'], price_method: 'next_close', adverse_slippage_percent: 0.25, parameter_overrides: {} }); expect(body.session_token).toMatch(/^[a-z0-9-]+$/i);
                    if (startFails) { startFails = false; return route.fulfill({ status: 503, json: { error: { message: 'Synthetic start unavailable.' } } }); }
                } else {
                    expect(body).toMatchObject({ from_date: original.from_date, to_date: original.to_date, initial_capital: 250000, tags: ['research'], notes: 'Original research', price_method: 'next_close', adverse_slippage_percent: 0.25 }); expect(body).not.toHaveProperty('strategy_version_id'); expect(body).not.toHaveProperty('statistics');
                }
                const run = { ...original, id: starts === 2 ? 12 : 13, name: starts === 2 ? 'New research' : 'Current strategy duplicate', strategy_name: 'Current strategy', strategy_version: '2.0.0', strategy_version_id: 82, notes: starts === 2 ? 'New notes' : original.notes, tags: starts === 2 ? ['research', 'bounded'] : original.tags };
                runs.push(run); return route.fulfill({ status: 201, json: { data: { run, completed: true, continued: false } } });
            });
            await page.route('**/api/v1/backtests/12', route => {
                if (route.request().method() === 'GET') return detailUnavailable ? route.fulfill({ status: 503, json: { message: 'Synthetic detail unavailable.' } }) : route.fulfill({ json: { data: runs.find(r => r.id === 12) } });
                expect(route.request().postDataJSON()).toEqual({ name: 'New research', notes: 'Retained metadata notes', tags: ['research', 'bounded'] });
                if (saveFails) { saveFails = false; return route.fulfill({ status: 503, json: { error: { message: 'Synthetic metadata unavailable.' } } }); }
                runs = runs.map(r => r.id === 12 ? { ...r, notes: 'Retained metadata notes' } : r); return route.fulfill({ json: { data: runs.find(r => r.id === 12) } });
            });
            await page.route('**/api/v1/backtests/13', route => route.fulfill({ json: { data: runs.find(r => r.id === 13) } }));
            await safety.seal(); await page.goto('/backtests'); await expect(page.getByRole('alert').filter({ hasText: 'Backtest history could not be loaded' })).toBeVisible(); await expect(page.getByText(/No backtests yet/)).toHaveCount(0);
            historyUnavailable = false; await page.getByRole('button', { name: 'Retry backtest history' }).click(); await expect(backtestRow(page, original.name)).toBeVisible();
            await page.getByRole('button', { name: 'New Backtest', exact: true }).click(); let modal = page.getByRole('dialog', { name: 'New Strategy Backtest' }); await modal.getByRole('button', { name: 'Cancel', exact: true }).click(); expect(starts).toBe(0);
            await page.getByRole('button', { name: 'New Backtest', exact: true }).click(); await modal.getByLabel('Name', { exact: true }).fill('New research'); await modal.getByLabel('Initial capital').fill('250000'); await modal.getByLabel('Execution price').selectOption('next_close'); await modal.getByLabel('Adverse slippage %').fill('0.25'); await modal.getByLabel('Notes', { exact: true }).fill('New notes'); await modal.getByLabel('Tags', { exact: true }).fill('research, bounded');
            await modal.getByRole('button', { name: 'Start', exact: true }).click(); await expect(page.getByText('Synthetic start unavailable.', { exact: true })).toBeVisible(); await expect(modal.getByLabel('Notes', { exact: true })).toHaveValue('New notes');
            await modal.getByRole('button', { name: 'Start', exact: true }).click(); await expect(page).toHaveURL(/\/backtests\/12$/); await expect(page.getByText('Backtest could not be loaded. Please retry.', { exact: true })).toBeVisible(); await expect(page.getByText('Backtest not found.', { exact: true })).toHaveCount(0);
            detailUnavailable = false; await page.getByRole('button', { name: 'Retry loading backtest' }).click(); await expect(page.getByRole('heading', { name: 'New research', exact: true })).toBeVisible(); await expect(page.getByText('Current strategy · v2.0.0')).toBeVisible();
            await page.getByLabel('Notes', { exact: true }).fill('Retained metadata notes'); await page.getByRole('button', { name: 'Save name / notes / tags' }).click(); await expect(page.getByText('Synthetic metadata unavailable.', { exact: true })).toBeVisible(); await expect(page.getByLabel('Notes', { exact: true })).toHaveValue('Retained metadata notes'); await page.getByRole('button', { name: 'Save name / notes / tags' }).click(); await expect.poll(() => runs.find(r => r.id === 12)?.notes).toBe('Retained metadata notes');
            await page.getByRole('link', { name: 'All backtests' }).click(); await backtestRow(page, original.name).getByRole('button', { name: 'Duplicate', exact: true }).click(); await expect(page).toHaveURL(/\/backtests\/13$/); await expect(page.getByRole('heading', { name: 'Current strategy duplicate', exact: true })).toBeVisible(); expect(runs[0]).toEqual(original); expect(starts).toBe(3); expect(safety.blocked).toEqual([]); expect(safety.writes).toHaveLength(5);
        });

        test(`E2E-23 discloses comparison differences and retains checkpoint cancellation until confirmed (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-23');
            const safety = await prepare(page, viewport, 'backtest-lifecycle', (method, path) => (method === 'POST' && ['/api/v1/backtests/11/cancel', '/api/v1/backtests/compare'].includes(path)) || (method === 'DELETE' && path === '/api/v1/backtests/11'));
            let runs = [{ ...original, status: 'running' }, { ...original, id: 12, name: 'Different assumptions', execution_assumptions: { price_method: 'next_open' } }]; let cancelFails = true; let deleteFails = true;
            await page.route('**/api/v1/backtests', route => route.fulfill({ json: { data: { runs } } })); await page.route('**/api/v1/backtests/meta', route => route.fulfill({ json: { data: { ranges: [] } } }));
            await page.route('**/api/v1/backtests/compare', route => { expect(route.request().postDataJSON()).toEqual({ run_ids: [11, 12] }); return route.fulfill({ json: { data: { compatible: false, assumption_differences: ['price_method'], runs, disclosure: 'Synthetic partial statistics are unavailable, not zero.' } } }); });
            await page.route('**/api/v1/backtests/11/cancel', route => { if (cancelFails) { cancelFails = false; return route.fulfill({ status: 503, json: { error: { message: 'Synthetic cancel unavailable.' } } }); } runs = runs.map(r => r.id === 11 ? { ...r, status: 'cancelled', checkpoint_date: '2026-09-05' } : r); return route.fulfill({ json: { data: runs[0] } }); });
            await page.route('**/api/v1/backtests/11', route => { expect(route.request().method()).toBe('DELETE'); if (deleteFails) { deleteFails = false; return route.fulfill({ status: 503, json: { error: { message: 'Synthetic delete unavailable.' } } }); } runs = runs.filter(r => r.id !== 11); return route.fulfill({ status: 204, body: '' }); });
            await safety.seal(); await page.goto('/backtests'); const row = backtestRow(page, original.name);
            await page.getByLabel('Compare Backtest 11').check(); await page.getByLabel('Compare Backtest 12').check(); await page.getByRole('button', { name: 'Compare selected (2)' }).click(); await expect(page.getByTestId('backtest-comparison')).toContainText('Backtest compatibility differs'); await expect(page.getByTestId('backtest-comparison')).toContainText('price method'); await expect(page.getByTestId('backtest-comparison')).toContainText('Unavailable');
            page.once('dialog', async dialog => { expect(dialog.message()).toContain('durable checkpoint'); await dialog.dismiss(); }); await row.getByRole('button', { name: 'Cancel', exact: true }).click(); expect(safety.writes.filter(w => w.path.endsWith('/cancel'))).toHaveLength(0);
            page.once('dialog', dialog => dialog.accept()); await row.getByRole('button', { name: 'Cancel', exact: true }).click(); await expect(page.getByText('Synthetic cancel unavailable.', { exact: true })).toBeVisible(); await expect(row).toContainText('running');
            page.once('dialog', dialog => dialog.accept()); await row.getByRole('button', { name: 'Cancel', exact: true }).click(); await expect(row).toContainText('cancelled'); await expect(row.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
            page.once('dialog', async dialog => { expect(dialog.message()).toContain('cannot be undone'); await dialog.dismiss(); }); await row.getByRole('button', { name: 'Delete', exact: true }).click(); expect(safety.writes.filter(w => w.method === 'DELETE')).toHaveLength(0);
            page.once('dialog', dialog => dialog.accept()); await row.getByRole('button', { name: 'Delete', exact: true }).click(); await expect(page.getByText('Synthetic delete unavailable.', { exact: true })).toBeVisible(); await expect(row).toBeVisible();
            page.once('dialog', dialog => dialog.accept()); await row.getByRole('button', { name: 'Delete', exact: true }).click(); await expect(row).toHaveCount(0); expect(runs.map(r => r.id)).toEqual([12]); expect(safety.blocked).toEqual([]); expect(safety.writes).toHaveLength(5);
        });

        test(`E2E-24 distinguishes unavailable replay history from confirmed empty history and retries only on request (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-24');
            const safety = await prepare(page, viewport, 'replay-list-read', () => false);
            const replay = { id: 41, created_at: '2026-10-10T10:00:00Z', period_start: '2026-09-01', period_end: '2026-10-01', starting_mode: 'new_simulated', price_method: 'next_close', adverse_slippage_percent: 0.25, status: 'completed' };
            let phase = 'unavailable'; let initialReads = 0;
            const trackedReads = { started: 0, completed: 0, inFlight: new Set() };
            const isReplayListRead = request => request.method() === 'GET' && new URL(request.url()).pathname === '/api/replays';
            page.on('request', request => {
                if (isReplayListRead(request)) { trackedReads.started += 1; trackedReads.inFlight.add(request); }
            });
            const finishTrackedRead = request => {
                if (isReplayListRead(request)) { trackedReads.completed += 1; trackedReads.inFlight.delete(request); }
            };
            page.on('requestfinished', finishTrackedRead);
            page.on('requestfailed', finishTrackedRead);
            await page.route('**/api/replays', route => {
                if (route.request().method() !== 'GET') return route.fallback();
                if (phase === 'unavailable') { initialReads += 1; return route.fulfill({ status: 503, json: { message: 'Synthetic replay history unavailable.' } }); }
                return route.fulfill({ json: { data: phase === 'rows' ? [replay] : [] } });
            });
            await safety.seal(); await page.goto('/portfolios');
            const panel = page.getByRole('region', { name: 'Portfolio Replay' });
            await expect(panel.getByRole('alert')).toContainText('Replay history could not be loaded. Retry to check saved runs.');
            await expect(panel.getByText('No Portfolio Replay runs.', { exact: true })).toHaveCount(0);
            await expect.poll(() => trackedReads.inFlight.size).toBe(0);
            expect(initialReads).toBeGreaterThan(0);
            expect(trackedReads.started).toBe(trackedReads.completed);
            const initialBaseline = trackedReads.started;

            phase = 'rows';
            await panel.getByRole('button', { name: 'Retry replay history', exact: true }).click();
            await expect(panel.getByLabel('Compare Replay 41')).toBeVisible();
            await expect.poll(() => trackedReads.inFlight.size).toBe(0);
            expect(trackedReads.started).toBe(initialBaseline + 1);
            expect(trackedReads.completed).toBe(trackedReads.started);
            expect(safety.blocked).toEqual([]);
            expect(safety.writes).toEqual([]);
        });

        test(`E2E-24 checks readiness blockers and retains isolated replay evidence through read/cancel/delete recovery (${viewport.name})`, async ({ page }, testInfo) => {
            test.setTimeout(60000); journeyId(testInfo, 'E2E-24');
            const safety = await prepare(page, viewport, 'replay', (method, path) => (method === 'POST' && ['/api/replays/readiness', '/api/replays', '/api/replays/31/cancel', '/api/replays/compare'].includes(path)) || (method === 'DELETE' && path === '/api/replays/31'));
            const replay = { id: 31, created_at: '2026-10-10T10:00:00Z', period_start: '2026-09-01', period_end: '2026-10-01', starting_mode: 'new_simulated', price_method: 'next_close', adverse_slippage_percent: 0.25, status: 'queued', evidence_summary: { checkpoint_count: 0, transaction_count: 0, recommendation_count: 0 }, results: { limitations: ['missing_benchmark'] } };
            let runs = [{ ...replay, id: 32, status: 'completed', price_method: 'next_open' }]; let checks = 0; let startFails = true; let readFails = true; let cancelFails = true; let deleteFails = true;
            const checkPayload = route => expect(route.request().postDataJSON()).toMatchObject({ starting_mode: 'new_simulated', period_start: replay.period_start, period_end: replay.period_end, starting_cash: 75000, price_method: 'next_close', adverse_slippage_percent: 0.25 });
            await page.route('**/api/replays/readiness', route => { checkPayload(route); checks++; if (checks === 1) return route.fulfill({ status: 503, json: { message: 'Synthetic readiness unavailable.' } }); return route.fulfill({ json: { data: { status: checks === 2 ? 'blocked' : 'ready_with_limitations', limitations: [checks === 2 ? 'missing_historical_reconstruction' : 'missing_benchmark'] } } }); });
            await page.route('**/api/replays', route => { if (route.request().method() === 'GET') return route.fulfill({ json: { data: runs } }); checkPayload(route); if (startFails) { startFails = false; return route.fulfill({ status: 503, json: { message: 'Synthetic replay start unavailable.' } }); } runs.push({ ...replay }); return route.fulfill({ status: 201, json: { data: { ...replay, readiness: { status: 'ready_with_limitations', limitations: ['missing_benchmark'] } } } }); });
            await page.route('**/api/replays/31', route => { if (route.request().method() === 'DELETE') { if (deleteFails) { deleteFails = false; return route.fulfill({ status: 503, json: { message: 'Synthetic replay delete unavailable.' } }); } runs = runs.filter(r => r.id !== 31); return route.fulfill({ status: 204, body: '' }); } if (readFails) { readFails = false; return route.fulfill({ status: 503, json: { message: 'Synthetic replay evidence unavailable.' } }); } return route.fulfill({ json: { data: runs.find(r => r.id === 31) } }); });
            await page.route('**/api/replays/31/cancel', route => { if (cancelFails) { cancelFails = false; return route.fulfill({ status: 503, json: { message: 'Synthetic replay cancel unavailable.' } }); } runs = runs.map(r => r.id === 31 ? { ...r, status: 'cancelled' } : r); return route.fulfill({ json: { data: runs.find(r => r.id === 31) } }); });
            await page.route('**/api/replays/compare', route => { expect(route.request().postDataJSON()).toEqual({ run_ids: [31, 32] }); return route.fulfill({ json: { data: { compatible: false, assumption_differences: ['price_method'], runs, disclosure: 'Synthetic assumptions differ; partial statistics remain unavailable.' } } }); });
            await safety.seal(); await page.goto('/portfolios'); const panel = page.getByRole('region', { name: 'Portfolio Replay' });
            await panel.getByLabel('From', { exact: true }).fill(replay.period_start); await panel.getByLabel('To', { exact: true }).fill(replay.period_end); await panel.getByLabel('Starting cash').fill('75000'); await panel.getByLabel('Fill method').selectOption('next_close'); await panel.getByLabel('Slippage %').fill('0.25');
            await panel.getByRole('button', { name: 'Check', exact: true }).click(); await expect(page.getByText('Synthetic readiness unavailable.', { exact: true })).toBeVisible(); await expect(panel.getByLabel('Starting cash')).toHaveValue('75000');
            await panel.getByRole('button', { name: 'Check', exact: true }).click(); await expect(panel).toContainText('missing historical reconstruction'); await expect(panel.getByRole('button', { name: 'Start', exact: true })).toBeDisabled();
            await panel.getByRole('button', { name: 'Check', exact: true }).click(); await expect(panel).toContainText('ready with limitations');
            await panel.getByRole('button', { name: 'Start', exact: true }).click(); await expect(page.getByText('Synthetic replay start unavailable.', { exact: true })).toBeVisible(); await panel.getByRole('button', { name: 'Start', exact: true }).click();
            const row = panel.getByRole('row').filter({ has: page.getByLabel('Compare Replay 31') }); await expect(row).toContainText('queued');
            await row.getByRole('button', { name: 'Details', exact: true }).click(); await expect(page.getByText('Synthetic replay evidence unavailable.', { exact: true })).toBeVisible(); await expect(page.getByTestId('replay-evidence-detail')).toHaveCount(0); await row.getByRole('button', { name: 'Details', exact: true }).click(); await expect(page.getByTestId('replay-evidence-detail')).toContainText('missing benchmark');
            await panel.getByLabel('Compare Replay 31').check(); await panel.getByLabel('Compare Replay 32').check(); await panel.getByRole('button', { name: 'Compare selected (2)' }).click(); await expect(page.getByTestId('replay-comparison')).toContainText('Replay assumptions differ materially');
            const accessibility = await new AxeBuilder({ page }).include('[aria-label="Portfolio Replay"]').withTags(['wcag2a', 'wcag2aa']).analyze(); expect(accessibility.violations).toEqual([]);
            await row.getByRole('button', { name: 'Cancel', exact: true }).click(); await expect(page.getByText('Synthetic replay cancel unavailable.', { exact: true })).toBeVisible(); await expect(row).toContainText('queued'); await row.getByRole('button', { name: 'Cancel', exact: true }).click(); await expect(row).toContainText('cancelled');
            page.once('dialog', async dialog => { expect(dialog.message()).toContain('audit tombstone'); await dialog.dismiss(); }); await row.getByRole('button', { name: 'Delete', exact: true }).click(); expect(safety.writes.filter(w => w.method === 'DELETE')).toHaveLength(0);
            page.once('dialog', dialog => dialog.accept()); await row.getByRole('button', { name: 'Delete', exact: true }).click(); await expect(page.getByText('Synthetic replay delete unavailable.', { exact: true })).toBeVisible(); await expect(row).toBeVisible(); page.once('dialog', dialog => dialog.accept()); await row.getByRole('button', { name: 'Delete', exact: true }).click(); await expect(row).toHaveCount(0);
            expect(runs.map(r => r.id)).toEqual([32]); expect(safety.blocked).toEqual([]); expect(safety.writes).toHaveLength(10);
        });
    });
}
