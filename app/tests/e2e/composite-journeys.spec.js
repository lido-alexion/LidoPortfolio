import { test, expect } from '@playwright/test';
import { OPEN_BUY_RECOMMENDATION, TEST_USER } from '../js/tos/fixtures/tosApi.js';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { installTosApiMocks } from './tosApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

function acceptanceReport(status, blockers) {
    return {
        campaign_id: 88,
        campaign_status: status,
        readiness: { ready: status === 'ready', reason: blockers.length ? 'Required production evidence is missing.' : 'All production gates passed.' },
        runtime: { queue_configuration_ready: true },
        horizons: ['1m', '3m', '6m'].map((horizon) => ({
            horizon,
            ready: blockers.length === 0,
            blocking_reasons: blockers,
            required_source_dates: ['2026-09-01', '2026-09-02'],
        })),
        linked_runs: [],
        feature_coverage: { status: 'unknown' },
        calibration: { status: 'unknown' },
        folds: [],
        baseline: { status: 'unknown' },
        candidate: { status: 'unknown' },
    };
}

async function installAdminMlMocks(page) {
    let campaignStatus = null;
    let report = acceptanceReport('blocked', ['Production source-date evidence is unknown.']);
    const actions = [];
    await page.route(/\/(sanctum\/csrf-cookie|api\/)/, async (route) => {
        const { pathname, searchParams } = new URL(route.request().url());
        const method = route.request().method();
        const json = (data, status = 200) => route.fulfill({
            status,
            contentType: 'application/json',
            body: JSON.stringify(data),
        });
        if (pathname.endsWith('/sanctum/csrf-cookie')) return route.fulfill({ status: 204, body: '' });
        if (pathname.endsWith('/api/auth/csrf-token')) return json({ token: 'e2e-csrf' });
        if (pathname.endsWith('/api/auth/me')) return json({ user: { ...TEST_USER, is_admin: true } });
        if (pathname.endsWith('/api/portfolios')) return json({ data: [] });
        if (pathname.endsWith('/api/guided-tour')) return json({ success: true, data: { eligible: false, show_welcome_prompt: false } });
        if (pathname.endsWith('/api/v1/admin/ml/acceptance') && method === 'GET') return json({ success: true, data: report });
        if (pathname.endsWith('/api/v1/admin/ml/acceptance/sources') && method === 'GET') {
            return json({ success: true, data: { data: [], current_page: Number(searchParams.get('page') || 1), last_page: 1 } });
        }
        if (pathname.endsWith('/api/v1/admin/ml/acceptance/campaigns') && method === 'POST') {
            campaignStatus = 'preflight';
            report = acceptanceReport(campaignStatus, ['Required dated source mapping is unresolved.']);
            return json({ success: true, data: { id: 88, status: campaignStatus } }, 201);
        }
        const campaignAction = pathname.match(/\/api\/v1\/admin\/ml\/acceptance\/campaigns\/88\/(start|resume|cancel)$/);
        if (campaignAction && method === 'POST') {
            actions.push(campaignAction[1]);
            if (campaignAction[1] === 'start') {
                campaignStatus = 'training';
                report = acceptanceReport(campaignStatus, []);
            }
            return json({ success: true, data: { id: 88, status: campaignStatus } });
        }
        if (pathname.endsWith('/api/v1/admin/ml')) return json({ success: true, data: {} });
        if (pathname.endsWith('/api/v1/admin/ml/runs')) return json({ success: true, data: { runs: [] } });
        if (pathname.endsWith('/api/v1/admin/ml/retention-plan')) return json({ success: true, data: { artifacts: [], applied: [] } });
        return json({ success: false, error: { code: 'UNMOCKED', message: `${method} ${pathname}` } }, 501);
    });
    return actions;
}

test.describe('V9-UX-001 composite journey coverage', () => {
    for (const viewport of VIEWPORTS) {
        for (const journey of ['E2E-01', 'E2E-02']) {
            test(`${journey} carries screener provenance into strategy-owned recommendation; approval stays non-executing (${viewport.name})`, async ({ page }, testInfo) => {
                journeyId(testInfo, journey);
                await seedDeterministicJourney(page, `${journey.toLowerCase()}-source-chain-${viewport.name}`);
                await page.setViewportSize({ width: viewport.width, height: viewport.height });
                const sourceScreenerId = journey === 'E2E-01' ? 99 : 41;
                const sourceVersion = journey === 'E2E-01' ? 1 : 3;
                const allowedOrigin = 'http://127.0.0.1:4177';
                const sourceDefinition = { root: { type: 'group', op: 'AND', children: [{
                    type: 'condition', left: { indicator: 'close' }, operator: 'gt',
                    right: { indicator: 'sma', params: { period: 200 } },
                }] } };
                await installInvestorWorkflowApiMocks(page, { initialScreeners: [{
                    id: 41, name: 'Price Above MA200', version: sourceVersion, scope: 'holdings', is_enabled: true,
                    definition_json: sourceDefinition, description: 'Validated source screener.',
                }] });
                const generated = {
                    ...OPEN_BUY_RECOMMENDATION,
                    id: journey === 'E2E-01' ? 801 : 802,
                    stock_id: 42,
                    strategy_id: 8,
                    strategy_name: 'Momentum Core',
                    strategy_version_id: 81,
                    strategy_version: 1,
                    source_screener_id: sourceScreenerId,
                    source_screener_version: sourceVersion,
                    status: 'pending_review',
                    capital_allocation_status: 'funded',
                    can_review: true,
                    suggested_quantity: 10,
                    suggested_investment_amount: 50000,
                    reference_price: 3500,
                    evidence: { ...OPEN_BUY_RECOMMENDATION.evidence, screener_id: sourceScreenerId, screener_version: sourceVersion },
                };
                const observedRequests = [];
                const externalRequests = [];
                const recommendationPayloads = [];
                const screenerPayloads = [];
                let strategyEnabled = false;
                let savedTransaction = null;
                let transactionPayload = null;
                let cashBalance = 100000;
                page.on('request', (request) => observedRequests.push({ method: request.method(), path: new URL(request.url()).pathname }));
                page.on('response', async (response) => {
                    if (new URL(response.url()).pathname.endsWith('/api/screeners')) {
                        try { screenerPayloads.push({ method: response.request().method(), body: await response.json() }); } catch { /* non-JSON response */ }
                    }
                    if (new URL(response.url()).pathname.endsWith('/api/v1/recommendations')) {
                        try { recommendationPayloads.push(await response.json()); } catch { /* non-JSON response */ }
                    }
                });
                await installTosApiMocks(page, { recommendations: [], pipelineRecommendations: [generated], retainApprovedRecommendations: true, fallbackUnmocked: true });
                await page.route('**/*', async (route) => {
                    const requestUrl = route.request().url();
                    if (new URL(requestUrl).origin !== allowedOrigin) {
                        externalRequests.push(requestUrl);
                        return route.abort();
                    }
                    return route.fallback();
                });

                // These route guards are deliberately scoped to the mock app origin. Every API
                // request in this scenario is intercepted; unexpected paths fail closed.
                await page.route('**/api/v1/strategy-registry**', async (route) => {
                    const { pathname } = new URL(route.request().url());
                    if (pathname.endsWith('/api/v1/strategy-registry') && route.request().method() === 'GET') {
                        return route.fulfill({ json: { data: [{ artifact_id: '8', id: 8, name: 'Momentum Core', metadata: { status: strategyEnabled ? 'active' : 'draft', is_enabled: strategyEnabled } }] } });
                    }
                    if (pathname.endsWith('/api/v1/strategy-registry/meta')) {
                        return route.fulfill({ json: { data: { counts: { total: 1, active: strategyEnabled ? 1 : 0, draft: strategyEnabled ? 0 : 1, archived: 0 } } } });
                    }
                    if (pathname.endsWith('/api/v1/strategy-registry/8/activate') && route.request().method() === 'POST') {
                        strategyEnabled = true;
                        return route.fulfill({ json: { data: { artifact_id: '8', metadata: { status: 'active', is_enabled: true } } } });
                    }
                    return route.fulfill({ status: 501, json: { error: 'Unmocked strategy registry request' } });
                });
                await page.route('**/api/transactions**', async (route) => {
                    const request = route.request();
                    if (request.method() === 'GET') return route.fulfill({ json: { data: savedTransaction ? [savedTransaction] : [] } });
                    if (request.method() === 'POST') {
                        const payload = request.postDataJSON();
                        transactionPayload = payload;
                        savedTransaction = { ...payload, id: 9001, stock: { id: payload.stock_id || 42, symbol: 'INFY', name: 'Infosys' }, symbol: 'INFY', stock_name: 'Infosys', strategy_id: 8, strategy_name: 'Momentum Core', recommendation_id: generated.id };
                        cashBalance -= Number(payload.quantity) * Number(payload.price) + Number(payload.fees || 0);
                        return route.fulfill({ json: { success: true, data: savedTransaction, message: 'Transaction saved' }, status: 201 });
                    }
                    return route.fulfill({ status: 501, json: { error: 'Unexpected transaction method' } });
                });
                await page.route('**/api/holdings**', (route) => route.fulfill({ json: { data: savedTransaction ? [{ id: 9101, stock_id: savedTransaction.stock_id || 42, stock: { id: savedTransaction.stock_id || 42, symbol: 'INFY', name: 'Infosys' }, quantity: 10, avg_buy_price: 3500, summary: { first_buy_date: '2026-10-09', latest_close: 3500 }, strategy_id: 8, strategy_name: 'Momentum Core', recommendation_id: generated.id }] : [] } }));
                await page.route('**/api/settings', (route) => route.fulfill({ json: { data: { fee_components: [] } } }));
                await page.route('**/api/cash*', (route) => {
                    const { pathname } = new URL(route.request().url());
                    if (pathname.endsWith('/cash/statement')) return route.fulfill({ json: { data: { entries: [] }, meta: { current_page: 1, last_page: 1 } } });
                    return route.fulfill({ json: { data: { cash_balance: cashBalance, reserved_cash: 0, available_investable_cash: cashBalance, available_physical_cash: cashBalance } } });
                });

                if (journey === 'E2E-01') {
                    await page.goto('/screeners/new');
                    await page.getByLabel('Name').fill('Price Above MA200');
                    await page.getByLabel('left indicator').selectOption('close');
                    await page.getByLabel('right indicator').selectOption('sma');
                    await page.getByLabel('Period').fill('200');
                    const createResponse = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/api/screeners'));
                    await page.getByRole('button', { name: 'Save' }).click();
                    expect(await (await createResponse).json()).toMatchObject({ data: { id: sourceScreenerId, version: sourceVersion } });
                    await expect(page).toHaveURL(/\/screeners\/99$/);
                    const create = observedRequests.find(({ method, path }) => method === 'POST' && path.endsWith('/api/screeners'));
                    expect(create).toBeTruthy();
                } else {
                    await page.goto('/screeners');
                    await expect(page.getByRole('link', { name: 'Price Above MA200' })).toBeVisible();
                    await expect.poll(() => screenerPayloads.find(({ method }) => method === 'GET')?.body?.data?.find(({ id }) => id === sourceScreenerId)?.version).toBe(sourceVersion);
                }

                await page.goto('/strategy');
                await page.getByRole('button', { name: 'Create Strategy', exact: true }).click();
                await page.locator('#create-strategy-name').fill('Momentum Core');
                await page.getByRole('button', { name: 'Create Strategy', exact: true }).last().click();
                await expect(page).toHaveURL(/\/strategy\?strategy_id=8$/);
                await page.getByRole('button', { name: 'Eligibility Sources' }).click();
                await page.locator('select').filter({ has: page.locator(`option[value="${sourceScreenerId}"]`) }).selectOption(String(sourceScreenerId));
                await page.getByRole('button', { name: 'Add', exact: true }).click();
                const strategySave = page.waitForRequest((request) => request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/v1/strategy'));
                await page.getByRole('button', { name: 'Save', exact: true }).click();
                const strategyPayload = (await strategySave).postDataJSON();
                expect(strategyPayload.config.eligibility_sources).toEqual(expect.arrayContaining([
                    expect.objectContaining({ screener_id: sourceScreenerId, enabled: true }),
                ]));

                page.on('dialog', (dialog) => dialog.accept());
                const enableStrategy = page.getByRole('button', { name: 'Enable', exact: true });
                await expect(enableStrategy).toBeEnabled();
                await enableStrategy.click();
                await expect(page.getByText('enabled', { exact: true }).first()).toBeVisible();
                expect(strategyEnabled).toBe(true);

                await page.goto('/recommendations');
                const pipelineRequest = page.waitForRequest((request) => request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/v1/pipeline/run'));
                await page.getByRole('button', { name: 'Run decision pipeline' }).click();
                await pipelineRequest;
                const row = page.getByRole('row').filter({ hasText: 'Momentum Core' });
                await expect(row).toContainText('INFY');
                await expect(row).toContainText('pending_review');
                await expect.poll(() => recommendationPayloads.flatMap((payload) => payload?.data?.data ?? payload?.data ?? [])
                    .find((recommendation) => recommendation.id === generated.id)).toMatchObject({
                    strategy_id: 8, strategy_version_id: 81, source_screener_id: sourceScreenerId,
                    source_screener_version: sourceVersion,
                });
                const reviewRequest = page.waitForRequest((request) => request.method() === 'POST' && new URL(request.url()).pathname.endsWith(`/api/v1/recommendations/${generated.id}/review`));
                await row.getByRole('button', { name: 'Review' }).click();
                const dialog = page.getByRole('dialog');
                await expect(dialog).toContainText('Momentum Core');
                await expect(dialog.getByTestId('recommendation-provenance')).toContainText(`Source screener #${sourceScreenerId} · Version ${sourceVersion}`);
                await expect(dialog.getByTestId('recommendation-provenance')).toContainText('Strategy #8 · Strategy version #81');
                const requestsBeforeApproval = observedRequests.length;
                await dialog.getByRole('button', { name: 'Approve' }).click();
                expect((await reviewRequest).postDataJSON()).toMatchObject({ decision: 'approved' });
                await expect(page.getByRole('row').filter({ hasText: 'Momentum Core' })).toContainText('pending_execution');
                expect(observedRequests.slice(requestsBeforeApproval).some(({ method, path }) => method === 'POST' && /\/orders(?:\/|$)|\/execution\/(?:submit|submit-selected)/.test(path))).toBe(false);

                await page.goto('/transactions/pending');
                const pendingRow = page.getByRole('row').filter({ hasText: 'INFY' });
                await pendingRow.getByRole('button', { name: 'Execute manually' }).click();
                await expect(page).toHaveURL('/transactions');
                await expect(page.getByText(`Recording actual broker fill for recommendation #${generated.id}.`)).toBeVisible();
                await page.getByRole('button', { name: /Save Transaction|Save/ }).click();
                await expect.poll(() => savedTransaction).toMatchObject({ recommendation_id: generated.id, strategy_id: 8, strategy_name: 'Momentum Core', type: 'buy', quantity: 10, price: 3500 });
                expect(transactionPayload).toMatchObject({ recommendation_id: generated.id, source: 'recommendation', type: 'buy', quantity: 10, price: 3500 });
                await expect(page.getByRole('row').filter({ hasText: 'INFY' })).toContainText('10');
                expect(savedTransaction.source).toBe('recommendation');

                await page.goto('/holdings');
                await expect(page.getByRole('row').filter({ hasText: 'INFY' })).toContainText('Momentum Core');
                await expect(page.getByRole('row').filter({ hasText: 'INFY' })).toContainText('10');

                await page.goto('/cash');
                await expect(page.getByText('65,000')).toBeVisible();

                expect(observedRequests.some(({ method, path }) => method === 'POST' && /\/orders(?:\/|$)/.test(path))).toBe(false);
                expect(observedRequests.some(({ method, path }) => method === 'POST' && /\/execution\/(?:submit|submit-selected)/.test(path))).toBe(false);
                expect(observedRequests.some(({ path }) => /kite|broker/i.test(path))).toBe(false);
                expect(externalRequests).toEqual([]);
            });
        }
    }

    for (const viewport of VIEWPORTS) {
        test(`E2E-08 uses production ML acceptance preflight and explicit training controls (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-08');
            await seedDeterministicJourney(page, `e2e08-production-ml-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const actions = await installAdminMlMocks(page);
            await page.goto('/settings/ml-scoring');

            const acceptance = page.getByRole('heading', { name: 'Production ML acceptance' }).locator('..');
            await expect(acceptance).toContainText('Campaign: blocked');
            await expect(acceptance).toContainText('Production source-date evidence is unknown.');
            await expect(acceptance.getByRole('button', { name: 'Start 1m/3m/6m training' })).toHaveCount(0);

            await acceptance.getByRole('button', { name: 'Queue campaign preflight' }).click();
            await expect(acceptance).toContainText('Campaign: preflight');
            await expect(acceptance).toContainText('Required dated source mapping is unresolved.');
            await expect(acceptance.getByRole('button', { name: 'Start 1m/3m/6m training' })).toHaveCount(0);
            expect(actions).toEqual([]);

            // The guided panel exposes Start only after a fresh passing preflight.
            // Keep the mocked campaign blocked here: actual source validation and
            // production qualification require the protected admin acceptance service.
        });
    }
});
