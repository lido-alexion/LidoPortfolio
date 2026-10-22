import { test, expect } from '@playwright/test';
import { TEST_USER } from '../js/tos/fixtures/tosApi.js';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { createApiFailureDiagnosticCollector, installApiFailureDiagnosticBoundary, handleApiFailureDiagnostic, registerApiFailureDiagnosticAfterEach } from './apiFailureDiagnosticBoundary.js';

registerApiFailureDiagnosticAfterEach(test);

const ORIGIN = 'http://127.0.0.1:4177';
const ROOT = '/api/v1/admin/fundamentals/bootstrap-operations';
const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
const snap = value => {
    const copy = structuredClone(value);
    const freeze = item => {
        if (item && typeof item === 'object' && !Object.isFrozen(item)) {
            Object.values(item).forEach(freeze);
            Object.freeze(item);
        }
        return item;
    };
    return freeze(copy);
};

async function installFixture(page, options = {}) {
    const rows = [];
    const stock = { id: 17, symbol: 'AAA', name: 'Alpha Ltd', exchange: 'NSE' };
    const gapRows = [21, 22, 23].map((id, i) => ({ id, run_id: 70, stock_id: 17 + i, stock: { id: 17 + i, symbol: ['AAA', 'BBB', 'CCC'][i] }, metric: 'revenue', cadence: 'quarterly', reason: 'source_unavailable', state: 'actionable' }));
    const warningRows = [];
    let gaps = gapRows;
    let schedule = { id: 6, name: 'Quarterly fundamentals', frequency: 'monthly', interval: 1, monthday: 1, local_time: '09:15:00', timezone: 'Asia/Kolkata', enabled: false, version: 1, generation: 1, configuration: { scope: 'all' }, starts_at_local: '2026-11-01 09:15:00', next_occurrence_at: '2026-11-01T03:45:00Z' };
    let schedules = [schedule];
    let runs = [
        { id: 71, status: 'running', scope: 'all', counts: { requested: 4, completed: 2, failed: 0, queued: 1, running: 1 }, request: { scope: 'all', stock_ids: [] } },
        { id: 70, status: 'completed', scope: 'stocks', counts: { requested: 3, completed: 2, failed: 1, queued: 0, running: 0 }, request: { scope: 'stocks', stock_ids: [17, 18, 19] } },
    ];
    let runId = 72;
    let cancelCount = 0;
    let launchCount = 0;
    let runNowCount = 0;
    let runNowAccepted = false;
    let presets = options.presets || [];

    const finish = async (route, body, status = 200) => {
        const request = route.request();
        const responseBody = snap(body);
        await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(responseBody) });
        const response = await request.response();
        rows.push({ method: request.method(), path: new URL(request.url()).pathname, query: new URL(request.url()).search, requestBody: snap(request.postDataJSON?.() ?? null), status, responseBody, responseFinished: await response.finished() === null, request });
    };
    const list = values => ({ data: values, current_page: 1, last_page: 1, per_page: 25, total: values.length });
    const presentRun = run => ({
        id: run.id, trigger: run.trigger || 'manual', scope: run.scope, status: run.status,
        request: { scope: run.request?.scope || run.scope, stock_count: run.counts?.requested ?? 1, forced: Boolean(run.request?.force), reason: run.request?.reason ?? null, origin_run_id: run.request?.origin_run_id ?? null, stock_ids: run.request?.stock_ids ?? [], schedule: null, gaps: run.request?.gaps ?? null },
        counts: { requested: run.counts?.requested ?? 1, queued: run.counts?.queued ?? 0, running: run.counts?.running ?? 0, completed: run.counts?.completed ?? 0, failed: run.counts?.failed ?? 0 },
        failure_reason: null, actor: { id: 1, name: 'Operations Admin' }, created_at: '2026-10-22T10:00:00Z', started_at: null, completed_at: run.status === 'completed' || run.status === 'cancelled' ? '2026-10-22T10:05:00Z' : null, updated_at: '2026-10-22T10:05:00Z',
    });
    const presentSchedule = value => ({
        id: value.id, name: value.name, preset_id: value.preset_id ?? null, preset_revision: value.preset_revision ?? null,
        configuration: value.configuration_snapshot ?? value.configuration ?? { scope: 'all' }, version: value.version, generation: value.generation ?? 1,
        frequency: value.frequency, interval: value.interval ?? 1, weekday: value.weekday ?? null, monthday: value.monthday ?? null,
        local_time: value.local_time, timezone: value.timezone, starts_at_local: value.starts_at_local,
        ends_on_local: value.ends_on_local ?? null, max_occurrences: value.max_occurrences ?? null,
        occurrences_created: value.occurrences_created ?? 0, next_occurrence_at: value.next_occurrence_at,
        enabled: value.enabled, occurrences_count: value.occurrences_count ?? null,
    });

    await page.route('**/*', async route => {
        if (await handleApiFailureDiagnostic(route, { origin: ORIGIN, collector: page.__diagnostics })) return;
        const url = new URL(route.request().url());
        const { pathname, searchParams } = url;
        const req = route.request();
        const method = req.method();
        if (url.origin !== ORIGIN) return route.abort();
        if (pathname === '/sanctum/csrf-cookie' && method === 'GET') return route.fulfill({ status: 204, body: '' });
        if (!pathname.startsWith('/api/')) return route.continue();
        if (pathname === '/api/auth/csrf-token' && method === 'GET') return finish(route, { token: 'ops001-fixture' });
        if (pathname === '/api/auth/me' && method === 'GET') return finish(route, { user: { ...TEST_USER, is_admin: true } });
        if (pathname === '/api/portfolios' && method === 'GET') return finish(route, { data: [] });
        if (pathname === '/api/notification-center' && method === 'GET') return finish(route, { data: [], meta: { unread_count: 0, active_critical_count: 0 } });
        if (pathname === '/api/guided-tour' && method === 'GET') return finish(route, { success: true, data: { eligible: false, show_welcome_prompt: false } });
        if (pathname === '/api/stocks/search' && method === 'GET') return finish(route, { data: [stock] });
        if (pathname === '/api/v1/admin/fundamentals' && method === 'GET') return finish(route, { success: true, data: {
            settings: { quarterly_freshness_months: 5, annual_freshness_months: 15, request_delay_ms: 750, max_attempts: 3, paused: false, nse_official_fallback_enabled: false, bse_official_fallback_enabled: false, nse_official_feed_url: '', bse_official_feed_url: '' },
            latest_run: null, coverage: { quarterly: { stocks: 0 }, annual: { stocks: 0 } }, queued_jobs: 0, running: false,
            bootstrap: { latest_run: null, terminal_counts: [], queued_jobs: 0, running_jobs: 0 }, ai_insights: {},
            exchange_fallbacks: { nse: { feed_url: '', configured: false }, bse: { feed_url: '', configured: false } },
        }, meta: {} });
        if (!pathname.startsWith(ROOT)) {
            rows.push({ method, path: pathname, query: url.search, requestBody: snap(req.postDataJSON?.() ?? null), status: null, responseBody: null, responseFinished: false, request: req });
            return route.abort();
        }
        if (pathname === `${ROOT}/health` && method === 'GET') return finish(route, { data: { active_run: runs.find(r => r.status === 'running') ? presentRun(runs.find(r => r.status === 'running')) : null, queued_count: runs.filter(r => r.status === 'queued').length, last_successful_run: null, unresolved_failed_items: 0, unresolved_warnings: 0, unresolved_gaps: gaps.filter(g => ['actionable', 'reopened'].includes(g.state)).length, accepted_gaps: gaps.filter(g => g.state === 'accepted').length, next_schedule: null } });
        if (pathname === `${ROOT}/coverage` && method === 'GET') return finish(route, { success: true, data: { eligible_equity_population: 10, catalog_version: 'fixture-catalog', coverage: [{ metric: 'revenue', cadence: 'quarterly', covered_stocks: 8, eligible_stocks: 10, coverage_percent: 80, applicability: 'unknown' }], period_coverage: [], period_coverage_truncated: false, unresolved_gaps: gaps.filter(g => ['actionable', 'reopened'].includes(g.state)).length, unresolved_warnings: 0 }, meta: {} });
        if (pathname === `${ROOT}/runs` && method === 'GET') return finish(route, { data: runs.map(presentRun), current_page: Number(searchParams.get('page') || 1), last_page: 1, per_page: 25, total: runs.length });
        if (/\/runs\/\d+$/.test(pathname) && method === 'GET') {
            const id = Number(pathname.split('/').at(-1));
            const run = runs.find(r => r.id === id) || { id, status: 'cancelled', counts: { completed: 2, failed: 0 } };
            const jobs = id === 70 ? [{ id: 700, stock: { id: 17, symbol: 'AAA', name: 'Alpha Ltd' }, status: 'complete_good', attempts: 1, failure_reason: null, quarterly_status: 'complete', annual_status: 'complete', coverage: { cadences: {} }, period: { from: '2024-03-31', to: '2026-03-31' }, facts: { inserted: 4, upgraded: 0, deduped: 0, rejected: 0 }, updated_at: '2026-10-22T09:00:00Z' }] : [];
            return finish(route, { data: { run: presentRun(run), progress: { completed: run.counts?.completed ?? 0, failed: run.counts?.failed ?? 0, pending: 0, last_updated_at: '2026-10-22T10:00:00Z' }, jobs: { data: jobs, current_page: 1, last_page: 1, per_page: 50, total: jobs.length } } });
        }
        if (pathname === `${ROOT}/gaps` && method === 'GET') {
            const visibleGaps = searchParams.get('state') ? gaps.filter(g => g.state === searchParams.get('state')) : gaps;
            return finish(route, { success: true, data: list(visibleGaps), meta: {} });
        }
        if (pathname === `${ROOT}/warnings` && method === 'GET') return finish(route, { success: true, data: list(warningRows), meta: {} });
        if (pathname === `${ROOT}/presets` && method === 'GET') return finish(route, { data: list(presets) });
        if (pathname === `${ROOT}/schedules` && method === 'GET') return finish(route, { data: { ...list(schedules.map(presentSchedule)), current_page: Number(searchParams.get('page') || 1), last_page: 2, total: 26 } });
        const occurrence = pathname.match(new RegExp(`^${ROOT}/schedules/(\\d+)/occurrences$`));
        if (occurrence && method === 'GET') {
            const pageNumber = Number(searchParams.get('page') || 1);
            const occurrenceRow = pageNumber === 2
                ? { id: 82, scheduled_at_local: '2026-12-01 09:15:00', timezone: 'Asia/Kolkata', status: 'queued', run_id: 73 }
                : { id: 81, scheduled_at_local: '2026-11-01 09:15:00', timezone: 'Asia/Kolkata', status: 'queued', run_id: 72 };
            return finish(route, { data: { ...list([occurrenceRow]), current_page: pageNumber, last_page: 2, total: 26 } });
        }
        if (pathname === `${ROOT}/runs` && method === 'POST') {
            launchCount += 1;
            if (options.uncertainLaunch && launchCount === 1) return finish(route, { message: 'Synthetic write result unavailable.' }, 503);
            const body = req.postDataJSON();
            const created = { id: runId++, trigger: body.force ? 'forced_rerun' : 'manual', status: 'queued', scope: body.scope, counts: { requested: body.stock_ids?.length || 1, completed: 0, failed: 0, queued: body.stock_ids?.length || 1, running: 0 }, request: { ...body, stock_ids: body.stock_ids || [] } };
            runs = [created, ...runs];
            return finish(route, { data: presentRun(created) }, 201);
        }
        if (pathname.endsWith('/retry-failed') && method === 'POST') {
            const sourceId = Number(pathname.split('/').at(-2));
            const created = { id: runId++, trigger: 'retry', status: 'queued', scope: 'stocks', counts: { requested: 1, completed: 0, failed: 0, queued: 1, running: 0 }, request: { scope: 'stocks', origin_run_id: sourceId, stock_ids: [17] } };
            runs = [created, ...runs];
            return finish(route, { data: presentRun(created) }, 201);
        }
        if (pathname.endsWith('/cancel') && method === 'POST') {
            cancelCount += 1;
            if (cancelCount === 1) return finish(route, { error: { code: 'bootstrap_cancellation_busy', message: 'Another bootstrap slice is active; retry cancellation after it finishes.' } }, 409);
            runs = runs.map(r => r.id === Number(pathname.split('/').at(-2)) ? { ...r, status: 'cancelled', counts: { ...r.counts, queued: 0, running: 0 } } : r);
            return finish(route, { data: { id: Number(pathname.split('/').at(-2)), status: 'cancelled', cancelled_jobs: 2 } });
        }
        if (pathname === `${ROOT}/gaps/acknowledge` && method === 'POST') {
            gaps = gaps.map(g => req.postDataJSON().ids.includes(g.id) ? { ...g, state: 'accepted', acknowledgement: { actor_id: 1, at: '2026-10-22T10:00:00Z', note: req.postDataJSON().note }, acknowledgement_history: [{ actor_id: 1, note: req.postDataJSON().note, at: '2026-10-22T10:00:00Z' }] } : g);
            return finish(route, { success: true, data: { acknowledged: req.postDataJSON().ids.length }, meta: {} });
        }
        if (pathname === `${ROOT}/runs/from-gaps` && method === 'POST') {
            const chosenGapIds = req.postDataJSON().gap_ids;
            const chosen = gaps.filter(g => chosenGapIds.includes(g.id) && ['actionable', 'reopened'].includes(g.state));
            const stockIds = [...new Set(chosen.map(g => g.stock_id))].sort((a, b) => a - b);
            const created = { id: runId++, trigger: 'gap_driven', status: 'queued', scope: 'stocks', counts: { requested: stockIds.length, completed: 0, failed: 0, queued: stockIds.length, running: 0 }, request: { scope: 'stocks', stock_ids: stockIds, gaps: { ids: chosenGapIds, metric: chosen[0].metric, cadence: chosen[0].cadence, execution_scope: 'whole_stock' } } };
            runs = [created, ...runs];
            return finish(route, { data: presentRun(created) }, 201);
        }
        if (pathname === `${ROOT}/schedules` && method === 'POST') {
            const body = req.postDataJSON();
            schedule = { ...body, id: 6, preset_revision: 1, configuration_snapshot: { scope: 'all' }, version: 1, generation: 1, weekday: null, monthday: body.monthday, local_time: `${body.local_time}:00`, starts_at_local: '2026-11-01 09:15:00', ends_on_local: null, max_occurrences: null, occurrences_created: 0, enabled: false, next_occurrence_at: '2026-11-01T03:45:00Z' };
            schedules = [schedule];
            return finish(route, { data: presentSchedule(schedule) }, 201);
        }
        if (pathname === `${ROOT}/schedules/6/clone` && method === 'POST') {
            const clone = { ...schedule, id: 7, name: req.postDataJSON().name, enabled: false, version: 1, generation: 1 };
            schedules = [...schedules, clone];
            return finish(route, { data: presentSchedule(clone) }, 201);
        }
        if (pathname === `${ROOT}/schedules/6/enabled` && method === 'PUT') {
            schedule = { ...schedule, enabled: req.postDataJSON().enabled, version: schedule.version + 1 };
            schedules = schedules.map(item => item.id === 6 ? schedule : item);
            return finish(route, { data: presentSchedule(schedule) });
        }
        if (pathname === `${ROOT}/schedules/6/run-now` && method === 'POST') {
            runNowCount += 1;
            if (runNowCount === 1) {
                runNowAccepted = true;
                return finish(route, { message: 'Synthetic Run now receipt interrupted.' }, 503);
            }
            if (!runNowAccepted) throw new Error('Run now replay arrived without an accepted first request.');
            return finish(route, { data: { run_id: 90, created: false } }, 200);
        }
        if (pathname === `${ROOT}/presets` && method === 'POST') {
            const preset = { id: 3, ...req.postDataJSON(), revision: 1, schedules_count: 0, created_at: '2026-10-22T10:00:00Z', updated_at: '2026-10-22T10:00:00Z' };
            presets = [preset];
            return finish(route, { data: preset }, 201);
        }
        rows.push({ method, path: pathname, query: url.search, requestBody: snap(req.postDataJSON?.() ?? null), status: null, responseBody: null, responseFinished: false, request: req });
        return route.abort();
    });
    const diagnostics = createApiFailureDiagnosticCollector(page, ORIGIN);
    page.__diagnostics = diagnostics;
    await installApiFailureDiagnosticBoundary(page, { origin: ORIGIN, collector: diagnostics });
    return { rows, get schedule() { return schedule; }, get runNowCount() { return runNowCount; } };
}

async function open(page, viewport, scenario, options) {
    await seedDeterministicJourney(page, `ops001-${scenario}-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    const fixture = await installFixture(page, options);
    await page.goto('/settings/fundamentals');
    return { fixture, panel: page.locator('#bootstrap-operations') };
}

function mutationRows(rows) { return rows.filter(row => row.method !== 'GET' && row.method !== 'OPTIONS'); }

async function assertReceipts(rows) {
    for (const row of rows) {
        if (row.status === null) throw new Error(`Unanswered request: ${row.method} ${row.path}`);
        expect(row.responseFinished, `${row.method} ${row.path} terminal response`).toBe(true);
        const response = await row.request.response();
        expect(response?.status(), `${row.method} ${row.path} status`).toBe(row.status);
        expect(await response.json()).toEqual(row.responseBody);
    }
}

for (const viewport of VIEWPORTS) {
    test(`OPS001-01 run lifecycle, force guard, uncertain launch recovery and cancellation (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'OPS001-01');
        const { fixture, panel } = await open(page, viewport, '01', { uncertainLaunch: true });
        await expect(panel).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Fundamental Data' })).toBeVisible();
        await panel.getByRole('button', { name: 'Start backfill' }).click();
        await expect(panel.getByRole('status').filter({ hasText: /action result is uncertain/i })).toBeVisible();
        expect(mutationRows(fixture.rows)).toHaveLength(1);
        const firstLaunch = mutationRows(fixture.rows)[0];
        expect(firstLaunch).toMatchObject({ method: 'POST', path: `${ROOT}/runs`, status: 503, requestBody: { scope: 'all' } });
        expect(firstLaunch.responseBody).toEqual({ message: 'Synthetic write result unavailable.' });
        const start = panel.getByRole('button', { name: 'Start backfill' });
        // The hook already reconciles server state before reporting the uncertain result.
        await expect(start).toBeEnabled();
        await panel.getByRole('button', { name: 'Refresh' }).first().click();
        await expect.poll(() => fixture.rows.some(r => r.method === 'GET' && r.path === `${ROOT}/runs` && fixture.rows.indexOf(r) > fixture.rows.indexOf(firstLaunch))).toBe(true);
        await expect(start).toBeEnabled();
        expect(fixture.rows.slice(fixture.rows.indexOf(firstLaunch) + 1).some(r => r.method === 'GET' && r.path === `${ROOT}/runs`)).toBe(true);
        await start.click();
        await expect.poll(() => mutationRows(fixture.rows).length).toBe(2);
        const secondLaunch = mutationRows(fixture.rows)[1];
        expect(secondLaunch).toMatchObject({ method: 'POST', path: `${ROOT}/runs`, status: 201, requestBody: { scope: 'all' } });
        expect(secondLaunch.responseBody).toMatchObject({ data: { id: 72, status: 'queued', scope: 'all', trigger: 'manual', request: { scope: 'all', stock_ids: [], forced: false, reason: null, origin_run_id: null, gaps: null }, counts: { requested: 1, queued: 1, running: 0, completed: 0, failed: 0 } } });
        expect(secondLaunch.request.headers()['idempotency-key']).toBe(firstLaunch.request.headers()['idempotency-key']);
        await expect(panel.getByRole('row').filter({ hasText: '#72' })).toBeVisible();
        const firstLaunchIndex = fixture.rows.indexOf(firstLaunch);
        const secondLaunchIndex = fixture.rows.indexOf(secondLaunch);
        expect(fixture.rows.slice(firstLaunchIndex + 1, secondLaunchIndex).some(r => r.method === 'GET' && r.path === `${ROOT}/runs`)).toBe(true);

        const priorSuccess = panel.getByRole('row').filter({ hasText: '#70' });
        await priorSuccess.getByRole('button', { name: 'View', exact: true }).click();
        const detail = panel.getByRole('dialog', { name: 'Run #70 details' });
        await expect(detail.getByRole('row').filter({ hasText: 'AAA' })).toContainText('complete_good');
        await detail.getByRole('button', { name: 'Close run details' }).click();

        const force = panel.getByRole('checkbox', { name: /force rerun/i });
        await expect(force).toBeDisabled();
        expect(mutationRows(fixture.rows)).toHaveLength(2);
        await panel.getByLabel('Stocks to include').selectOption('stocks');
        await panel.getByLabel('Add an eligible stock').fill('AAA');
        await panel.getByRole('button', { name: /AAA/i }).click();
        await expect(force).toBeEnabled();
        await force.check();
        await panel.getByLabel('Why is this rerun needed?').fill('Corrected historical source mapping');
        await panel.getByRole('button', { name: 'Start backfill' }).click();
        await expect(panel.getByRole('row').filter({ hasText: '#73' })).toBeVisible();
        await expect.poll(() => mutationRows(fixture.rows).length).toBe(3);
        expect(mutationRows(fixture.rows)[2]).toMatchObject({ method: 'POST', path: `${ROOT}/runs`, status: 201, requestBody: { scope: 'stocks', stock_ids: [17], force: true, reason: 'Corrected historical source mapping' }, responseBody: { data: { id: 73, status: 'queued', trigger: 'forced_rerun', request: { scope: 'stocks', stock_ids: [17], forced: true, reason: 'Corrected historical source mapping' }, counts: { requested: 1, queued: 1, running: 0, completed: 0, failed: 0 } } } });
        await panel.getByRole('row').filter({ hasText: '#70' }).getByRole('button', { name: 'Retry failed', exact: true }).click();
        await expect(panel.getByRole('row').filter({ hasText: '#74' })).toBeVisible();
        await expect.poll(() => mutationRows(fixture.rows).length).toBe(4);
        expect(mutationRows(fixture.rows)[3]).toMatchObject({ method: 'POST', path: `${ROOT}/runs/70/retry-failed`, status: 201, requestBody: {}, responseBody: { data: { id: 74, status: 'queued', trigger: 'retry', request: { scope: 'stocks', stock_ids: [17], forced: false, reason: null, origin_run_id: 70 }, counts: { requested: 1, queued: 1, running: 0, completed: 0, failed: 0 } } } });
        expect(fixture.rows.filter(r => r.method === 'GET' && r.path === `${ROOT}/runs`).at(-1).responseBody.data.find(r => r.id === 70)).toMatchObject({ status: 'completed', counts: { completed: 2, failed: 1 } });

        const originalRun = panel.getByRole('row').filter({ hasText: '#71' });
        await originalRun.getByRole('button', { name: 'Cancel', exact: true }).click();
        await panel.getByLabel('Reason', { exact: true }).fill('Stop this run safely');
        await panel.getByRole('button', { name: 'Confirm cancellation' }).click();
        await expect(panel.getByRole('status').filter({ hasText: /conflict.*no success was reported/i })).toBeVisible();
        const firstCancel = mutationRows(fixture.rows).at(-1);
        expect(firstCancel).toMatchObject({ method: 'POST', path: `${ROOT}/runs/71/cancel`, status: 409, requestBody: { reason: 'Stop this run safely' } });
        expect(firstCancel.responseBody).toEqual({ error: { code: 'bootstrap_cancellation_busy', message: 'Another bootstrap slice is active; retry cancellation after it finishes.' } });
        await panel.getByRole('button', { name: 'Refresh' }).first().click();
        await panel.getByRole('row').filter({ hasText: '#71' }).getByRole('button', { name: 'Cancel', exact: true }).click();
        await panel.getByLabel('Reason', { exact: true }).fill('Finish gracefully');
        await panel.getByRole('button', { name: 'Confirm cancellation' }).click();
        await expect.poll(() => mutationRows(fixture.rows).length).toBe(6);
        expect(mutationRows(fixture.rows).at(-1)).toMatchObject({ method: 'POST', path: `${ROOT}/runs/71/cancel`, status: 200, requestBody: { reason: 'Finish gracefully' }, responseBody: { data: { id: 71, status: 'cancelled', cancelled_jobs: 2 } } });
        await expect.poll(() => fixture.rows.some(r => r.method === 'GET' && r.path === `${ROOT}/runs` && JSON.stringify(r.responseBody).includes('cancelled'))).toBe(true);
        const cancelledRun = panel.getByRole('row').filter({ hasText: '#71' });
        await expect(cancelledRun).toContainText('cancelled');
        await expect(cancelledRun).toContainText('2');
        await expect(cancelledRun.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
        await expect(cancelledRun.getByRole('button', { name: /resume/i })).toHaveCount(0);
        await expect(panel.getByText(/checkpoint|raw provider payload/i)).toHaveCount(0);
        await expect(panel.getByLabel(/provider/i)).toHaveCount(0);
        await expect(panel.getByLabel(/historical.*(value|fact)/i)).toHaveCount(0);
        await assertReceipts(fixture.rows);
        expect(mutationRows(fixture.rows).map(r => `${r.method} ${r.path}`)).toEqual([`POST ${ROOT}/runs`, `POST ${ROOT}/runs`, `POST ${ROOT}/runs`, `POST ${ROOT}/runs/70/retry-failed`, `POST ${ROOT}/runs/71/cancel`, `POST ${ROOT}/runs/71/cancel`]);
        expect(mutationRows(fixture.rows).map(r => r.requestBody)).toEqual([
            { scope: 'all' }, { scope: 'all' },
            { scope: 'stocks', stock_ids: [17], force: true, reason: 'Corrected historical source mapping' }, {},
            { reason: 'Stop this run safely' }, { reason: 'Finish gracefully' },
        ]);
    });

    test(`OPS001-02 compatible gap review and targeted stock launch (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'OPS001-02');
        const { fixture, panel } = await open(page, viewport, '02');
        await panel.getByRole('tab', { name: 'Coverage and review' }).click();
        await expect(panel.getByText(/unknown/i).first()).toBeVisible();
        await expect(panel.getByText(/not automatically an ingestion failure/i)).toBeVisible();
        await panel.getByRole('checkbox', { name: 'Select gap 21' }).check();
        await panel.getByRole('checkbox', { name: 'Select gap 22' }).check();
        await panel.getByLabel('Optional review note').fill('Expected source gap; reviewed by Admin');
        await panel.getByRole('button', { name: 'Mark expected missing' }).click();
        await panel.getByLabel('Gap state').selectOption('accepted');
        await expect.poll(() => fixture.rows.some(r => r.method === 'GET' && r.path === `${ROOT}/gaps` && r.query.includes('state=accepted'))).toBe(true);
        const acceptedGapReceipt = fixture.rows.filter(r => r.method === 'GET' && r.path === `${ROOT}/gaps` && r.query.includes('state=accepted')).at(-1);
        const acknowledgedGap = acceptedGapReceipt.responseBody.data.data[0];
        expect(acknowledgedGap).toMatchObject({ id: 21, state: 'accepted', acknowledgement: { note: 'Expected source gap; reviewed by Admin', actor_id: 1, at: '2026-10-22T10:00:00Z' }, acknowledgement_history: [{ note: 'Expected source gap; reviewed by Admin', actor_id: 1, at: '2026-10-22T10:00:00Z' }] });
        const acknowledgedRow = panel.getByRole('row').filter({ hasText: 'AAA' });
        await expect(acknowledgedRow.getByText('Expected source gap; reviewed by Admin', { exact: true })).toBeVisible();
        await expect(acknowledgedRow.getByText('accepted', { exact: true })).toBeVisible();
        await expect.poll(() => mutationRows(fixture.rows).length).toBe(1);
        expect(mutationRows(fixture.rows)[0]).toMatchObject({ method: 'POST', path: `${ROOT}/gaps/acknowledge`, status: 200, requestBody: { ids: [21, 22], note: 'Expected source gap; reviewed by Admin' }, responseBody: { success: true, data: { acknowledged: 2 }, meta: {} } });
        await panel.getByRole('checkbox', { name: 'Select gap 21' }).check();
        await expect(panel.getByRole('button', { name: 'Start targeted stock backfill' })).toBeDisabled();
        await panel.getByLabel('Gap state').selectOption('actionable');
        await panel.getByRole('tab', { name: 'Backfills and history' }).click();
        await panel.getByRole('tab', { name: 'Coverage and review' }).click();
        // The fixture keeps a third actionable gap after the compatible pair is acknowledged.
        await panel.getByRole('checkbox', { name: 'Select gap 23' }).check();
        await panel.getByRole('button', { name: 'Start targeted stock backfill' }).click();
        await expect(panel.getByRole('tab', { name: 'Backfills and history' })).toBeVisible();
        await panel.getByRole('tab', { name: 'Backfills and history' }).click();
        await expect(panel.getByRole('row').filter({ hasText: '#72' })).toBeVisible();
        await expect.poll(() => mutationRows(fixture.rows).length).toBe(2);
        expect(mutationRows(fixture.rows)[1]).toMatchObject({ method: 'POST', path: `${ROOT}/runs/from-gaps`, status: 201, requestBody: { gap_ids: [23] }, responseBody: { data: { status: 'queued', trigger: 'gap_driven', scope: 'stocks', request: { scope: 'stocks', stock_ids: [19], gaps: { ids: [23], metric: 'revenue', cadence: 'quarterly', execution_scope: 'whole_stock' } } } } });
        await panel.getByRole('tab', { name: 'Coverage and review' }).click();
        await expect(panel.getByText(/selected metric, cadence, and period do not restrict/i)).toBeVisible();
        await assertReceipts(fixture.rows);
        expect(mutationRows(fixture.rows)).toHaveLength(2);
        expect(mutationRows(fixture.rows).map(r => r.requestBody)).toEqual([
            { ids: [21, 22], note: 'Expected source gap; reviewed by Admin' }, { gap_ids: [23] },
        ]);
    });

    test(`OPS001-03 schedule recurrence, history pagination and run CSV scope (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'OPS001-03');
        const { fixture, panel } = await open(page, viewport, '03');
        await panel.getByRole('tab', { name: 'Presets and schedules' }).click();
        await panel.getByLabel('Preset name').fill('Eligible universe');
        await panel.getByRole('button', { name: 'Save preset' }).click();
        await expect(panel.getByRole('row').filter({ has: page.getByText('Eligible universe', { exact: true }) })).toBeVisible();
        await expect.poll(() => mutationRows(fixture.rows).length).toBe(1);
        expect(mutationRows(fixture.rows)[0]).toMatchObject({ method: 'POST', path: `${ROOT}/presets`, status: 201, requestBody: { name: 'Eligible universe', configuration: { scope: 'all' } }, responseBody: { data: { id: 3, name: 'Eligible universe', configuration: { scope: 'all' }, revision: 1, schedules_count: 0 } } });
        await panel.getByLabel('Schedule name').fill('Quarterly operations');
        await panel.getByLabel('Configuration').selectOption('3');
        await panel.getByLabel('Repeat').selectOption('monthly');
        await panel.getByLabel('Local time').fill('09:15');
        await panel.getByLabel('Timezone').fill('Asia/Kolkata');
        await panel.getByLabel('Starts (local date and time)').fill('2026-11-01T09:15');
        await panel.getByRole('button', { name: 'Create disabled schedule' }).click();
        await expect(panel.getByRole('row').filter({ hasText: 'Quarterly operations' })).toContainText('Disabled');
        await expect.poll(() => mutationRows(fixture.rows).length).toBe(2);
        expect(mutationRows(fixture.rows)[1]).toMatchObject({ method: 'POST', path: `${ROOT}/schedules`, status: 201, requestBody: { name: 'Quarterly operations', preset_id: 3, frequency: 'monthly', interval: 1, monthday: 1, timezone: 'Asia/Kolkata', local_time: '09:15', starts_at_local: '2026-11-01 09:15' }, responseBody: { data: { id: 6, name: 'Quarterly operations', preset_id: 3, preset_revision: 1, configuration: { scope: 'all' }, frequency: 'monthly', interval: 1, weekday: null, monthday: 1, timezone: 'Asia/Kolkata', local_time: '09:15:00', starts_at_local: '2026-11-01 09:15:00', ends_on_local: null, max_occurrences: null, occurrences_created: 0, enabled: false, version: 1, generation: 1, next_occurrence_at: '2026-11-01T03:45:00Z', occurrences_count: null } } });
        expect(mutationRows(fixture.rows)[1].requestBody).not.toHaveProperty('configuration');
        expect(mutationRows(fixture.rows)[1].responseBody.data.enabled).toBe(false);
        const nextBefore = fixture.schedule.next_occurrence_at;
        const configuredScheduleRow = panel.getByRole('row').filter({ has: page.getByText('Quarterly operations', { exact: true }) });
        await configuredScheduleRow.getByRole('button', { name: 'Clone', exact: true }).click();
        await expect(panel.getByRole('row').filter({ hasText: 'Quarterly operations copy' })).toContainText('Disabled');
        await expect.poll(() => mutationRows(fixture.rows).length).toBe(3);
        expect(mutationRows(fixture.rows)[2]).toMatchObject({ method: 'POST', path: `${ROOT}/schedules/6/clone`, status: 201, requestBody: { name: 'Quarterly operations copy' }, responseBody: { data: { id: 7, name: 'Quarterly operations copy', preset_id: 3, preset_revision: 1, configuration: { scope: 'all' }, frequency: 'monthly', interval: 1, weekday: null, monthday: 1, timezone: 'Asia/Kolkata', local_time: '09:15:00', starts_at_local: '2026-11-01 09:15:00', enabled: false, version: 1, generation: 1, next_occurrence_at: '2026-11-01T03:45:00Z' } } });
        await configuredScheduleRow.getByRole('button', { name: 'Enable', exact: true }).click();
        await expect(configuredScheduleRow).toContainText('Enabled');
        await expect.poll(() => mutationRows(fixture.rows).length).toBe(4);
        expect(mutationRows(fixture.rows)[3]).toMatchObject({ method: 'PUT', path: `${ROOT}/schedules/6/enabled`, status: 200, requestBody: { enabled: true }, responseBody: { data: { id: 6, name: 'Quarterly operations', preset_id: 3, preset_revision: 1, configuration: { scope: 'all' }, frequency: 'monthly', interval: 1, weekday: null, monthday: 1, timezone: 'Asia/Kolkata', local_time: '09:15:00', starts_at_local: '2026-11-01 09:15:00', enabled: true, version: 2, generation: 1, next_occurrence_at: '2026-11-01T03:45:00Z' } } });
        expect(fixture.schedule.version).toBe(2);
        await configuredScheduleRow.getByRole('button', { name: 'Run now', exact: true }).click();
        await expect(panel.getByRole('status').filter({ hasText: /action result is uncertain/i })).toBeVisible();
        await panel.getByRole('button', { name: 'Refresh' }).first().click();
        await configuredScheduleRow.getByRole('button', { name: 'Run now', exact: true }).click();
        await expect(panel.getByRole('status').filter({ hasText: 'Run #90 is already linked to this Run now request.' })).toBeVisible();
        expect(fixture.schedule.next_occurrence_at).toBe(nextBefore);
        expect(fixture.runNowCount).toBe(2);
        expect(mutationRows(fixture.rows).slice(4, 6).map(r => [r.method, r.path, r.status, r.requestBody])).toEqual([
            ['POST', `${ROOT}/schedules/6/run-now`, 503, {}], ['POST', `${ROOT}/schedules/6/run-now`, 200, {}],
        ]);
        expect(mutationRows(fixture.rows)[4].responseBody).toEqual({ message: 'Synthetic Run now receipt interrupted.' });
        expect(mutationRows(fixture.rows)[5].responseBody).toEqual({ data: { run_id: 90, created: false } });
        expect(mutationRows(fixture.rows)[4].request.headers()['idempotency-key']).toBeTruthy();
        expect(mutationRows(fixture.rows)[4].request.headers()['idempotency-key']).toBe(mutationRows(fixture.rows)[5].request.headers()['idempotency-key']);
        await panel.getByRole('button', { name: 'Refresh' }).first().click();
        await expect.poll(() => fixture.rows.some(r => r.method === 'GET' && r.path === `${ROOT}/schedules` && r.responseBody?.data?.data?.some(s => s.id === 6 && s.version === 2 && s.next_occurrence_at === nextBefore))).toBe(true);
        await configuredScheduleRow.getByRole('button', { name: 'History' }).click();
        await panel.getByRole('button', { name: 'Next' }).last().click();
        await expect.poll(() => fixture.rows.some(r => r.method === 'GET' && r.path === `${ROOT}/schedules/6/occurrences` && r.query.includes('page=2'))).toBe(true);
        await expect(panel.getByRole('row').filter({ hasText: '#73' })).toBeVisible();
        await panel.getByRole('tab', { name: 'Backfills and history' }).click();
        await panel.getByLabel('CSV item status').selectOption('failed');
        const exportLink = panel.getByRole('link', { name: 'Export CSV' }).first();
        await expect(exportLink).toHaveAttribute('href', new RegExp(`${ROOT}/runs/71/export\\.csv\\?status=failed&failed_only=1$`));
        expect(mutationRows(fixture.rows)).toHaveLength(6);
        await assertReceipts(fixture.rows);
        expect(mutationRows(fixture.rows).some(r => r.path.endsWith('/schedules/6') && r.method === 'PUT')).toBe(false);
        expect(mutationRows(fixture.rows).map(r => r.requestBody)).toEqual([
            { name: 'Eligible universe', configuration: { scope: 'all' } },
            { name: 'Quarterly operations', preset_id: 3, frequency: 'monthly', interval: 1, monthday: 1, timezone: 'Asia/Kolkata', local_time: '09:15', starts_at_local: '2026-11-01 09:15' },
            { name: 'Quarterly operations copy' }, { enabled: true }, {}, {},
        ]);
    });
}
