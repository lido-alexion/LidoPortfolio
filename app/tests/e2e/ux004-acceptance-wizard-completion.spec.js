import { test, expect } from '@playwright/test';
import { TEST_USER } from '../js/tos/fixtures/tosApi.js';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { createApiFailureDiagnosticCollector, installApiFailureDiagnosticBoundary, handleApiFailureDiagnostic, registerApiFailureDiagnosticAfterEach } from './apiFailureDiagnosticBoundary.js';

registerApiFailureDiagnosticAfterEach(test);

const ORIGIN = 'http://127.0.0.1:4177';
const BASE = '/api/v1/admin/ml/acceptance';
const HORIZONS = ['1m', '3m', '6m'];
const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
const SOURCE_BYTES = Buffer.from('fixture,csv\n');
const SOURCE_SHA = 'b607d34d3395765971aab58acd54e481a41bf14cbc0c0fbd53c88dc699544367';

function stepStatuses(statuses) {
    return ['readiness', 'discovery', 'sources', 'backfill', 'final_preflight', 'training', 'review']
        .map((id, index) => ({ id, status: statuses[index], reason_codes: [] }));
}

function initialReport() {
    return {
        current_step: 2, reported_at: '2026-10-22T10:00:00Z', evidence_current: false,
        steps: stepStatuses(['complete', 'action_required', 'blocked', 'blocked', 'blocked', 'blocked', 'action_required']),
        campaign: null, backfill: null, required_sources: [], runtime: { queue_configuration_ready: true, worker_evidence: {}, python_evidence: {} },
        readiness: { ready: true }, migrations: {},
        identity: { build_id: 'fixture-build', environment: 'production', registry: 'fixture-registry', registry_version: 'r17', configuration_sha256: 'fixture-config', commit_sha: 'a'.repeat(40) },
        horizons: Object.fromEntries(HORIZONS.map(h => [h, { reference_dates: ['2026-09-01'], blocking_reasons: ['snapshot_unproven:2026-09-01'], coverage: null }])),
        lifecycle: { enabled: false, drift_trigger_enabled: false, horizons: Object.fromEntries(HORIZONS.map(h => [h, { enabled: false }])) },
    };
}

function modelDashboard() {
    return {
        horizons: HORIZONS.map(h => ({
            horizon: h, candidate_count: 0, active_model: { id: `model-${h}-old` },
            latest_training_run: {}, latest_drift_check: {}, retained_models: [],
        })),
        lifecycle: {
            enabled: false, timezone: 'UTC', drift_trigger: { enabled: false }, notifications: { enabled: false },
            horizons: HORIZONS.map(h => ({ horizon: h, schedule: '0 0 * * *', schedule_options: ['0 0 * * *'], schedule_enabled: false })),
        },
    };
}

async function installFixture(page, outcome = 'qualified') {
    const report = initialReport();
    const rows = [];
    const source = { id: 'source-401', status: 'uploading', received: 4, manifest: { filename: 'cm01SEP2026bhav.csv', date: '2026-09-01', source: 'nse_cash_bhavcopy', bytes: SOURCE_BYTES.length, sha256: SOURCE_SHA } };
    const dashboard = modelDashboard();
    let campaignNumber = 0;
    let backfill = report.backfill;
    let trainingStarted = false;
    const record = (request, status, body) => rows.push({ request, method: request.method(), path: new URL(request.url()).pathname, payload: structuredClone(request.postDataJSON() ?? null), status, body: structuredClone(body) });
    const respond = async (route, body, status = 200) => {
        record(route.request(), status, body);
        await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
    };

    await page.route('**/*', async route => {
        if (await handleApiFailureDiagnostic(route, { origin: ORIGIN, collector: page.__diagnostics })) return;
        const url = new URL(route.request().url());
        if (url.origin !== ORIGIN) return route.abort();
        const { pathname, searchParams } = url;
        const request = route.request();
        const method = request.method();
        if (pathname === '/sanctum/csrf-cookie' && method === 'GET') return route.fulfill({ status: 204, body: '' });
        if (!pathname.startsWith('/api/')) return route.continue();
        if (pathname === '/api/auth/csrf-token' && method === 'GET') return respond(route, { token: 'e2e-csrf' });
        if (pathname === '/api/auth/me' && method === 'GET') return respond(route, { user: { ...TEST_USER, is_admin: true } });
        if (pathname === '/api/portfolios' && method === 'GET') return respond(route, { data: [] });
        if (pathname === '/api/guided-tour' && method === 'GET') return respond(route, { success: true, data: { eligible: false, show_welcome_prompt: false } });
        if (pathname === `${BASE}/wizard` && method === 'GET') return respond(route, { success: true, data: report });
        if (pathname === `${BASE}/sources` && method === 'GET') return respond(route, { success: true, data: { data: [source], current_page: Number(searchParams.get('page') || 1), last_page: 1 } });
        if (pathname === `${BASE}/sources/${source.id}` && method === 'GET') return respond(route, { success: true, data: source });
        if (pathname === `${BASE}/campaigns` && method === 'POST') {
            const body = request.postDataJSON();
            campaignNumber += 1;
            report.campaign = { id: `campaign-${campaignNumber}`, status: body.final_preflight ? 'ready' : 'preflight', cutoff_date: body.cutoff_date, identity: report.identity };
            if (body.final_preflight === true) {
                report.current_step = 6;
                report.steps = stepStatuses(['complete', 'complete', 'complete', 'complete', 'complete', 'action_required', 'action_required']);
                report.required_sources = [];
                for (const h of HORIZONS) Object.assign(report.horizons[h], { blocking_reasons: [], dataset_sha256: `dataset-${h}`, training: null });
            } else {
                report.current_step = 3;
                report.steps = stepStatuses(['complete', 'complete', 'action_required', 'action_required', 'blocked', 'blocked', 'action_required']);
                report.required_sources = [{ date: '2026-09-01', horizons: HORIZONS, accepted_families: ['nse_cash_bhavcopy'], sources: [{ id: source.id, status: 'uploading', family: 'nse_cash_bhavcopy', date: '2026-09-01', filename: source.manifest.filename, bytes: source.manifest.bytes }] }];
                for (const h of HORIZONS) report.horizons[h].blocking_reasons = ['snapshot_unproven:2026-09-01'];
            }
            return respond(route, { success: true, data: report.campaign }, 201);
        }
        if (pathname === `${BASE}/sources/${source.id}/chunks` && method === 'PUT') {
            source.received += SOURCE_BYTES.length - 4;
            return respond(route, { success: true, data: { received: source.received } });
        }
        if (pathname === `${BASE}/sources/${source.id}/finalize` && method === 'POST') {
            source.status = 'queued';
            report.required_sources[0].sources = [{ id: source.id, status: 'queued', family: 'nse_cash_bhavcopy', date: '2026-09-01', filename: source.manifest.filename, bytes: source.manifest.bytes }];
            return respond(route, { success: true, data: { ...source, status: 'queued' } }, 202);
        }
        if (pathname === `${BASE}/backfills/preview` && method === 'POST') {
            backfill = report.backfill = { id: 410, status: 'completed', mode: 'preview', cursor: 1, requested_dates: ['2026-09-01'], can_apply: true, can_retry_preview: false, resumable: false, results: { '2026-09-01': { status: 'Reviewed', snapshot_sha256: 'fixture-snapshot', diagnostics: { mapping_percentage: 100 } } } };
            report.current_step = 4;
            report.steps = stepStatuses(['complete', 'complete', 'complete', 'action_required', 'blocked', 'blocked', 'action_required']);
            return respond(route, { success: true, data: backfill }, 202);
        }
        if (pathname === `${BASE}/backfills/410/apply` && method === 'POST') {
            backfill = report.backfill = { ...backfill, status: 'completed', mode: 'apply', can_apply: false };
            report.current_step = 5;
            report.steps = stepStatuses(['complete', 'complete', 'complete', 'complete', 'action_required', 'blocked', 'action_required']);
            return respond(route, { success: true, data: backfill }, 202);
        }
        if (pathname === `${BASE}/backfills/410/resume` && method === 'POST' && report.backfill?.resumable === true) {
            backfill = report.backfill = { ...report.backfill, status: 'running', resumable: false };
            return respond(route, { success: true, data: backfill }, 202);
        }
        if (pathname === `${BASE}/campaigns/campaign-2/start` && method === 'POST' && report.campaign?.status === 'ready') {
            trainingStarted = true;
            report.campaign.status = 'training';
            report.current_step = 6;
            report.steps = stepStatuses(['complete', 'complete', 'complete', 'complete', 'complete', 'in_progress', 'action_required']);
            for (const h of HORIZONS) report.horizons[h].training = { run_id: `run-${h}`, status: 'running', stage: 'training', percent: 42, observed_at: '2026-10-22T10:05:00Z', evidence_complete: false };
            return respond(route, { success: true, data: { id: 2, status: 'training', runs: HORIZONS.map(h => ({ horizon: h, run_id: `run-${h}`, status: 'running', percent: 42 })) } });
        }
        if (pathname === '/api/v1/admin/ml' && method === 'GET') return respond(route, { success: true, data: dashboard });
        if (pathname === '/api/v1/admin/ml/runs' && method === 'GET') return respond(route, { success: true, data: { runs: [] } });
        if (pathname === '/api/v1/admin/ml/retention-plan' && method === 'GET') return respond(route, { success: true, data: { artifacts: [], applied: [] } });
        // Every unexpected API request, including an unapproved mutation, is captured as a deterministic 501.
        return respond(route, { error: { code: 'UNEXPECTED_FIXTURE_REQUEST', method, path: pathname } }, 501);
    });

    const diagnostics = createApiFailureDiagnosticCollector(page, ORIGIN);
    page.__diagnostics = diagnostics;
    await installApiFailureDiagnosticBoundary(page, { origin: ORIGIN, collector: diagnostics });

    return {
        rows, dashboard, get trainingStarted() { return trainingStarted; },
        sealSource() {
            source.status = 'sealed';
            report.required_sources[0].sources = [{ id: source.id, status: 'sealed', family: 'nse_cash_bhavcopy', date: '2026-09-01' }];
        },
        completeTraining() {
            if (!trainingStarted || report.campaign.status !== 'training') throw new Error('Fixture completion requires a running campaign.');
            for (const h of HORIZONS) report.horizons[h].training = {
                run_id: `run-${h}`, status: outcome === 'rejected' ? 'completed_rejected' : 'completed', evidence_complete: true,
            };
            report.campaign.status = outcome === 'qualified' ? 'qualified' : 'completed';
            report.current_step = 7;
            report.evidence_current = outcome === 'qualified';
            if (outcome === 'qualified') report.campaign.qualified_at = '2026-10-22T10:10:00Z';
            report.steps = stepStatuses(['complete', 'complete', 'complete', 'complete', 'complete', 'complete', outcome === 'qualified' ? 'complete' : 'action_required']);
        },
        showStaleEvidence() {
            report.backfill = null;
            report.required_sources = [];
            report.current_step = 7;
            report.campaign = { id: 'campaign-stale', status: 'qualified', cutoff_date: '2026-09-01', qualified_at: '2026-08-01T00:00:00Z', identity: { ...report.identity, build_id: 'old-build' } };
            report.evidence_current = false;
            report.steps = stepStatuses(['complete', 'complete', 'not_required', 'not_required', 'complete', 'complete', 'complete']);
        },
        showResumableBackfill() {
            report.current_step = 4;
            report.campaign = { id: 'campaign-40', status: 'blocked', cutoff_date: '2026-10-15', identity: report.identity };
            report.backfill = backfill = { id: 410, status: 'failed', mode: 'apply', cursor: 1, requested_dates: ['2026-09-01'], can_apply: false, can_retry_preview: false, resumable: true, results: {} };
            report.required_sources = [{ date: '2026-09-01', horizons: HORIZONS, accepted_families: ['nse_cash_bhavcopy'], sources: [{ id: 'source-sealed', status: 'sealed', family: 'nse_cash_bhavcopy', date: '2026-09-01' }] }];
            report.steps = stepStatuses(['complete', 'complete', 'complete', 'action_required', 'blocked', 'blocked', 'action_required']);
        },
    };
}

async function openWizard(page, viewport, outcome = 'qualified') {
    await seedDeterministicJourney(page, `ux004-completion-${outcome}-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    const fixture = await installFixture(page, outcome);
    await page.goto('/settings/ml-scoring');
    const panel = page.getByRole('heading', { name: 'Production ML acceptance' }).locator('..');
    return { fixture, panel, active: panel.locator('.acceptance-step-wrapper:not([hidden])') };
}

async function runToTraining(panel, active, fixture) {
    await panel.getByRole('button', { name: 'Run discovery preflight' }).click();
    await expect(active.getByRole('heading', { name: 'Step 3: Add historical sources' })).toBeVisible();
    await panel.locator('#source-date').selectOption('2026-09-01');
    await panel.locator('#source-file').setInputFiles({ name: 'cm01SEP2026bhav.csv', mimeType: 'text/csv', buffer: SOURCE_BYTES });
    await panel.getByRole('button', { name: 'Upload and queue validation' }).click();
    await expect(panel.getByRole('row').filter({ hasText: 'cm01SEP2026bhav.csv' })).toContainText('Validating');
    fixture.sealSource();
    await panel.getByRole('button', { name: 'Refresh' }).click();
    await expect(panel.getByRole('checkbox', { name: /cm01SEP2026bhav.csv/ })).toBeChecked();
    await panel.getByRole('button', { name: 'Continue' }).click();
    await expect(active.getByRole('heading', { name: 'Step 4: Preview & apply backfill' })).toBeVisible();
    await panel.getByRole('button', { name: 'Run safe preview' }).click();
    await panel.getByRole('button', { name: 'Apply reviewed backfill' }).click();
    const applyDialog = panel.getByRole('alertdialog');
    await expect(applyDialog).toContainText('Matching proven data is idempotent');
    await expect(applyDialog.locator('[data-confirmation-title]')).toBeFocused();
    await applyDialog.getByRole('button', { name: 'Confirm apply' }).click();
    await expect(active.getByRole('heading', { name: 'Step 5: Run final preflight' })).toBeVisible();
    await active.getByRole('button', { name: 'Run final preflight', exact: true }).click();
    await expect(active.getByRole('heading', { name: 'Step 6: Train candidates' })).toBeVisible();
    await panel.getByRole('button', { name: 'Start 1m/3m/6m candidate training' }).click();
    const trainingConfirmation = panel.getByRole('alertdialog');
    await expect(trainingConfirmation).toContainText('Three horizon runs (1m, 3m and 6m) will be queued');
    await expect(trainingConfirmation).toContainText('active models, schedules and drift settings will not change');
    await expect(trainingConfirmation.locator('[data-confirmation-title]')).toBeFocused();
    await trainingConfirmation.getByRole('button', { name: 'Confirm and start' }).click();
}

async function assertReceipts(rows) {
    for (const row of rows) {
        const response = await row.request.response();
        const finished = response ? await response.finished() : new Error('missing response');
        expect(response?.status(), `${row.method} ${row.path} response status`).toBe(row.status);
        expect(finished, `${row.method} ${row.path} response completion`).toBe(null);
    }
}

async function assertSeparateModelState(page) {
    const candidateReview = page.locator('#ml-candidate-review');
    for (const h of HORIZONS) await expect(candidateReview).toContainText(`model-${h}-old`);
    await expect(page.getByText('lifecycle off', { exact: true })).toBeVisible();
    const lifecycle = page.locator('.card').filter({ has: page.getByText('Lifecycle automation (FEAT-056)', { exact: true }) });
    await expect(lifecycle).toContainText('Drift trigger: off.');
    await expect(lifecycle.getByRole('button', { name: 'Disabled', exact: true })).toHaveCount(3);
}

function mutationRows(rows) {
    return rows.filter(row => row.path.startsWith('/api/') && !['GET', 'HEAD', 'OPTIONS'].includes(row.method));
}

function assertCompletionMutations(rows) {
    const mutations = mutationRows(rows);
    expect(mutations.map(row => `${row.method} ${row.path}`)).toEqual([
        `POST ${BASE}/campaigns`, `PUT ${BASE}/sources/source-401/chunks`, `POST ${BASE}/sources/source-401/finalize`,
        `POST ${BASE}/backfills/preview`, `POST ${BASE}/backfills/410/apply`, `POST ${BASE}/campaigns`, `POST ${BASE}/campaigns/campaign-2/start`,
    ]);
    expect(mutations[0].payload).toEqual({ cutoff_date: expect.any(String) });
    expect(mutations[1].payload).toEqual({ offset: 4, chunk: SOURCE_BYTES.subarray(4).toString('base64') });
    expect(mutations[2].payload).toBe(null);
    expect(mutations[2].body.data.status).toBe('queued');
    expect(mutations[3].payload).toEqual({ sources: ['source-401'], campaign_id: 'campaign-1' });
    expect(mutations[4].payload).toBe(null);
    expect(mutations[5].payload).toEqual({ cutoff_date: mutations[0].payload.cutoff_date, final_preflight: true, remediation_backfill_id: 410 });
    expect(mutations[6].payload).toBe(null);
    expect(mutations.map(row => row.status)).toEqual([201, 200, 202, 202, 202, 201, 200]);
}

for (const viewport of VIEWPORTS) {
    test(`E2E-08 confirms three-horizon training, recovers running state after reload, then reviews qualification (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'E2E-08');
        const { fixture, panel, active } = await openWizard(page, viewport);
        await assertSeparateModelState(page);
        await runToTraining(panel, active, fixture);
        await expect(active).toContainText('Training is running. Progress and run IDs are saved on the server; reconnecting reloads current status.');
        await expect(panel.getByRole('group', { name: 'Current acceptance campaign' })).toContainText('campaign-2 · training');
        expect(fixture.trainingStarted).toBe(true);
        const beforeReload = fixture.rows.filter(row => row.path === `${BASE}/wizard` && row.method === 'GET').at(-1).body.data;

        await page.reload();
        const reloadedPanel = page.getByRole('heading', { name: 'Production ML acceptance' }).locator('..');
        const reloadedActive = reloadedPanel.locator('.acceptance-step-wrapper:not([hidden])');
        await expect(reloadedActive.getByRole('heading', { name: 'Step 6: Train candidates' })).toBeVisible();
        await expect(reloadedActive).toContainText('Training is running. Progress and run IDs are saved on the server; reconnecting reloads current status.');
        await expect(reloadedPanel.getByRole('group', { name: 'Current acceptance campaign' })).toContainText('campaign-2 · training');
        expect(fixture.rows.filter(row => row.path === `${BASE}/campaigns/campaign-2/start` && row.method === 'POST')).toHaveLength(1);
        const durableTrainingReport = fixture.rows.filter(row => row.path === `${BASE}/wizard` && row.method === 'GET').at(-1).body.data;
        expect(durableTrainingReport.identity).toEqual(beforeReload.identity);
        expect(durableTrainingReport.horizons).toEqual(beforeReload.horizons);
        expect(durableTrainingReport.campaign).toMatchObject({ id: 'campaign-2', status: 'training', identity: { build_id: 'fixture-build', configuration_sha256: 'fixture-config' } });
        expect(Object.fromEntries(HORIZONS.map(h => [h, durableTrainingReport.horizons[h].training]))).toEqual(Object.fromEntries(HORIZONS.map(h => [h, {
            run_id: `run-${h}`, status: 'running', stage: 'training', percent: 42, observed_at: '2026-10-22T10:05:00Z', evidence_complete: false,
        }])));

        await fixture.completeTraining();
        await reloadedPanel.getByRole('button', { name: 'Refresh' }).click();
        await expect(reloadedActive.getByRole('heading', { name: 'Step 7: Review completion' })).toBeVisible();
        await expect(reloadedActive).toContainText('Production acceptance is current for this build and configuration.');
        await expect(reloadedActive).toContainText('1m schedule: disabled');
        await expect(reloadedActive).toContainText('3m schedule: disabled');
        await expect(reloadedActive).toContainText('6m schedule: disabled');
        await expect(reloadedActive).toContainText('Drift trigger: disabled');

        await assertSeparateModelState(page);

        await assertReceipts(fixture.rows);
        assertCompletionMutations(fixture.rows);
        const observedDashboards = fixture.rows.filter(row => row.path === '/api/v1/admin/ml' && row.method === 'GET');
        expect(observedDashboards.length).toBeGreaterThan(0);
        expect(observedDashboards.every(row => JSON.stringify(row.body.data) === JSON.stringify(fixture.dashboard))).toBe(true);
        expect(fixture.dashboard.horizons.map(row => row.active_model.id)).toEqual(HORIZONS.map(h => `model-${h}-old`));
        expect(mutationRows(fixture.rows).some(row => /\/admin\/ml\/(?:models\/.*\/(?:promote|drift-check)|schedules|rollback|retrain-queue)/.test(row.path))).toBe(false);
        await assertReceipts(fixture.rows);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    });

    test(`E2E-08 distinguishes completed quality rejection and server-authorized recovery from failure (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'E2E-08');
        const { fixture, panel, active } = await openWizard(page, viewport, 'rejected');
        await runToTraining(panel, active, fixture);
        await expect(active).toContainText('Training is running. Progress and run IDs are saved on the server; reconnecting reloads current status.');
        await fixture.completeTraining();
        await panel.getByRole('button', { name: 'Refresh' }).click();
        await expect(active.getByRole('heading', { name: 'Step 7: Review completion' })).toBeVisible();
        await expect(active).toContainText('Completed — quality gates not met');
        await expect(active).toContainText('Production qualification is not yet recorded.');
        await expect(active).not.toContainText('The acceptance operation failed.');
        await expect(panel.getByRole('group', { name: 'Current acceptance campaign' })).toContainText('campaign-2 · completed');
        await assertReceipts(fixture.rows);
        assertCompletionMutations(fixture.rows);
        await assertReceipts(fixture.rows);
    });

    test(`E2E-08 resumes only server-authorized work and blocks stale evidence without mutation (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'E2E-08');
        const { fixture, panel } = await openWizard(page, viewport);
        fixture.showResumableBackfill();
        await panel.getByRole('button', { name: 'Refresh' }).click();
        const resumePanel = panel;
        const resumeActive = resumePanel.locator('.acceptance-step-wrapper:not([hidden])');
        await expect(resumeActive.getByRole('heading', { name: 'Step 4: Preview & apply backfill' })).toBeVisible();
        await expect(resumePanel.getByRole('button', { name: 'Resume backfill' })).toBeVisible();
        await resumePanel.getByRole('button', { name: 'Resume backfill' }).click();
        await expect(resumePanel.getByRole('button', { name: 'Resume backfill' })).toHaveCount(0);
        await expect(resumePanel).toContainText('Backfill status: running');
        const mutationsAfterResume = mutationRows(fixture.rows);
        expect(mutationsAfterResume.map(row => `${row.method} ${row.path}`)).toEqual([`POST ${BASE}/backfills/410/resume`]);
        expect(mutationsAfterResume[0]).toMatchObject({ payload: null, status: 202, body: { success: true, data: { status: 'running', resumable: false } } });

        fixture.showStaleEvidence();
        await resumePanel.getByRole('button', { name: 'Refresh' }).click();
        const staleActive = resumePanel.locator('.acceptance-step-wrapper:not([hidden])');
        await expect(staleActive.getByRole('heading', { name: 'Step 7: Review completion' })).toBeVisible();
        await expect(staleActive).toContainText('Saved evidence is stale because the current build or configuration no longer matches.');
        await expect(staleActive).toContainText('old-build / fixture-build');
        await expect(resumePanel.getByRole('button', { name: 'Start 1m/3m/6m candidate training' })).toHaveCount(0);
        expect(mutationRows(fixture.rows).map(row => `${row.method} ${row.path}`)).toEqual([`POST ${BASE}/backfills/410/resume`]);
        await assertReceipts(fixture.rows);
    });
}
