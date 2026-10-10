import { test, expect } from '@playwright/test';
import { HOLD_INSIGHT, OPEN_BUY_RECOMMENDATION, TEST_USER } from '../js/tos/fixtures/tosApi.js';
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
                test.setTimeout(90_000);
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
                    security_id: 42,
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
                let strategyRecord = null;
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
                await page.route('**/api/stocks/search**', (route) => route.fulfill({ json: { data: [] } }));
                await page.route('**/api/stocks/validate', (route) => route.fulfill({ json: { source: 'test fixture', data: { id: 42, symbol: 'INFY', name: 'Infosys Limited', exchange: 'NSE' }, meta: { cached: false } } }));
                await page.route('**/api/v1/strategies', async (route) => {
                    if (route.request().method() !== 'POST') return route.fulfill({ status: 501, json: { error: 'Unexpected strategy factory method' } });
                    const payload = route.request().postDataJSON();
                    strategyRecord = {
                        id: 8, strategy_id: 8, name: payload.name, description: payload.description,
                        status: 'draft', is_enabled: false, setup_required: true,
                        config: {
                            eligibility_sources: [],
                            indicators: ['relative_strength', 'momentum_score', 'trend_score', 'breakout_score', 'volume_score', 'market_regime', 'sector_strength', 'risk_score', 'ml_score']
                                .map((key) => ({ key, enabled: key === 'momentum_score', weight: key === 'momentum_score' ? 100 : 0 })),
                            thresholds: {}, portfolio_rules: { first_entry_pct: 50, max_holdings: 10 },
                            capital_allocation: { strategy: 'proportional', tie_break: 'highest_score', score_bands: [] },
                            exit_strategy: { enabled: true, mode: 'any', rules: [] }, market_gates: { enabled: false },
                        },
                    };
                    strategyRecord.eligibility_sources = [];
                    return route.fulfill({ status: 201, json: { data: strategyRecord } });
                });
                await page.route('**/api/v1/strategy', async (route) => {
                    if (route.request().method() === 'GET') return route.fulfill({ json: { data: strategyRecord || {} } });
                    if (route.request().method() === 'PUT' && strategyRecord) {
                        const payload = route.request().postDataJSON();
                        const config = payload.config || strategyRecord.config;
                        const sources = (config.eligibility_sources || strategyRecord.eligibility_sources).map((source) => ({
                            ...source, screener_version_id: source.screener_version_id || source.version || sourceVersion,
                        }));
                        config.eligibility_sources = sources;
                        const ready = sources.some((source) => source.enabled !== false && Number(source.screener_id) > 0
                            && Number(source.screener_version_id) > 0);
                        strategyRecord = { ...strategyRecord, ...payload, config, eligibility_sources: sources,
                            setup_required: !ready, readiness: { ready, status: ready ? 'ready' : 'setup_required', requirements: ready ? [] : [{ code: 'eligibility_missing', message: 'Add at least one enabled screener for eligibility.' }] } };
                        return route.fulfill({ json: { data: strategyRecord } });
                    }
                    return route.fulfill({ status: 501, json: { error: 'Unexpected strategy editor request' } });
                });
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
                        if (strategyRecord) { strategyRecord.status = 'active'; strategyRecord.is_enabled = true; }
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
                await page.route('**/api/cash/statement*', (route) => route.fulfill({ json: { data: { entries: [] }, meta: { current_page: 1, last_page: 1 } } }));
                await page.route(/\/api\/cash(?:\?.*)?$/, (route) => route.fulfill({ json: { data: { cash_balance: cashBalance, reserved_cash: 0, available_investable_cash: cashBalance, available_physical_cash: cashBalance } } }));

                if (journey === 'E2E-01') {
                    await page.goto('/screeners/new');
                    await page.getByLabel('Name').fill('Price Above MA200');
                    await page.getByLabel('left indicator').selectOption('close');
                    await page.getByLabel('right indicator').selectOption('sma');
                    await page.getByLabel('Period').fill('200');
                    await expect(page.locator('.lido-screener-tree .lido-screener-group').first()).toHaveScreenshot(`e2e-01-screener-rule-${viewport.name}.png`, { animations: 'disabled', caret: 'hide' });
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
                    await page.mouse.move(0, 0);
                    await expect(page.locator('.screeners-page')).toHaveScreenshot(`e2e-02-screener-list-${viewport.name}.png`, { animations: 'disabled', caret: 'hide' });
                }

                await page.goto('/strategy');
                await page.getByRole('button', { name: 'Create Strategy', exact: true }).click();
                await page.locator('#create-strategy-name').fill('Momentum Core');
                await page.getByRole('button', { name: 'Create Strategy', exact: true }).last().click();
                await expect(page).toHaveURL(/\/strategy\?strategy_id=8$/);
                await page.getByRole('button', { name: 'Eligibility Sources' }).click();
                await page.locator('select').filter({ has: page.locator(`option[value="${sourceScreenerId}"]`) }).selectOption(String(sourceScreenerId));
                await page.getByRole('button', { name: 'Add', exact: true }).click();
                await expect(page.locator('#strategy-editor-select')).toHaveScreenshot(`e2e-${journey}-strategy-editor-${viewport.name}.png`, { animations: 'disabled', caret: 'hide' });
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
                await expect(page.getByText(/now enabled for this portfolio\./)).toBeVisible();
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
                await page.addStyleTag({ content: '.lido-toast { visibility: hidden !important; } .lido-tos-review-modal { background-color: #000 !important; }' });
                await expect(dialog).toHaveScreenshot(`e2e-${journey}-recommendation-review-${viewport.name}.png`, { animations: 'disabled', caret: 'hide' });
                const requestsBeforeApproval = observedRequests.length;
                await dialog.getByRole('button', { name: 'Approve' }).click();
                expect((await reviewRequest).postDataJSON()).toMatchObject({ decision: 'approved' });
                await expect(page.getByRole('row').filter({ hasText: 'Momentum Core' })).toContainText('pending_execution');
                expect(observedRequests.slice(requestsBeforeApproval).some(({ method, path }) => method === 'POST' && /\/orders(?:\/|$)|\/execution\/(?:submit|submit-selected)/.test(path))).toBe(false);

                await page.goto('/transactions/pending', { waitUntil: 'domcontentloaded' });
                const pendingRow = page.getByRole('row').filter({ hasText: 'INFY' });
                await pendingRow.getByRole('button', { name: 'Execute manually' }).click();
                await expect(page).toHaveURL('/transactions');
                await expect(page.getByText(`Recording actual broker fill for recommendation #${generated.id}.`)).toBeVisible();
                await page.getByRole('button', { name: 'Validate symbol' }).click();
                await expect(page.getByText(/Validated via test fixture/)).toBeVisible();
                await page.getByLabel('Transaction date').fill('09-Oct-2026');
                await page.getByLabel('Transaction date').blur();
                await page.addStyleTag({ content: '.lido-mobile-utility-actions { visibility: hidden !important; }' });
                await page.evaluate(() => {
                    window.scrollTo(0, 0);
                    document.querySelectorAll('*').forEach((element) => { if (element.scrollTop > 0) element.scrollTop = 0; });
                });
                await page.mouse.move(0, 0);
                await expect(page.locator('form').first()).toHaveScreenshot(`e2e-${journey}-actual-fill-form-${viewport.name}.png`, { animations: 'disabled', caret: 'hide' });
                await page.getByRole('button', { name: 'Save Transaction' }).click();
                await expect.poll(() => savedTransaction).toMatchObject({ recommendation_id: generated.id, strategy_id: 8, strategy_name: 'Momentum Core', type: 'buy', quantity: 10, price: 3500 });
                expect(transactionPayload).toMatchObject({ recommendation_id: generated.id, source: 'recommendation', type: 'buy', quantity: 10, price: 3500 });
                await expect(page.getByRole('row').filter({ hasText: 'INFY' })).toContainText('10');
                expect(savedTransaction.source).toBe('recommendation');

                await page.goto('/holdings');
                await expect(page.getByRole('row').filter({ hasText: 'INFY' })).toContainText('Momentum Core');
                await expect(page.getByRole('row').filter({ hasText: 'INFY' })).toContainText('10');

                await page.goto('/cash');
                await expect(page.getByText(Math.round(cashBalance).toLocaleString('en-IN')).first()).toBeVisible();

                expect(observedRequests.some(({ method, path }) => method === 'POST' && /\/orders(?:\/|$)/.test(path))).toBe(false);
                expect(observedRequests.some(({ method, path }) => method === 'POST' && /\/execution\/(?:submit|submit-selected)/.test(path))).toBe(false);
                expect(observedRequests.some(({ method, path }) => ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && /kite|broker/i.test(path))).toBe(false);
                expect(externalRequests).toEqual([]);
            });
        }
    }

    for (const viewport of VIEWPORTS) {
        for (const journey of ['E2E-03', 'E2E-07']) {
            test(`${journey} records a strategy-owned SELL without changing another strategy's shares (${viewport.name})`, async ({ page }, testInfo) => {
                test.setTimeout(90_000);
                journeyId(testInfo, journey);
                await seedDeterministicJourney(page, `${journey.toLowerCase()}-strategy-exit-${viewport.name}`);
                await page.setViewportSize({ width: viewport.width, height: 1200 });

                const recommendationId = journey === 'E2E-03' ? 803 : 807;
                const recommendation = {
                    ...OPEN_BUY_RECOMMENDATION,
                    id: recommendationId,
                    security_id: 42,
                    status: 'pending_review', lifecycle_status: 'pending_review', can_review: true,
                    order_side: 'sell',
                    strategy_id: 17, strategy_name: 'Momentum Core', strategy_version_id: 171,
                    holding_episode_id: 'momentum-core-infY-episode',
                    suggested_quantity: 10, suggested_investment_amount: 35000, reference_price: 3500,
                    reasoning: 'The exit policy remains actionable while the entry market gate is blocked.',
                    recommendation_type: 'EXIT_POSITION', portfolio_action: 'EXIT_POSITION', ui_label: 'Exit',
                    primary_exit_reason: 'strategy_exit_threshold',
                    exit_attribution: { primary_reason: 'strategy_exit_threshold' },
                    strategy_config: { market_gates: { enabled: true, min_sentiment: 90, allowed_phases: ['Bull'] } },
                };
                const strategyBHold = { ...HOLD_INSIGHT, id: recommendationId + 100, symbol: 'INFY', strategy_id: 18, strategy_name: 'Dividend Core', reasoning: 'Dividend policy retains its independent position.' };
                let approved = false;
                let savedTransaction = null;
                let transactionPayload = null;
                let holdings = [
                    { id: 1, stock_id: 42, stock: { id: 42, symbol: 'INFY', name: 'Infosys' }, quantity: 10, avg_buy_price: 3200, strategy_id: 17, strategy_name: 'Momentum Core', recommendation_id: recommendationId, holding_episode_id: recommendation.holding_episode_id },
                    { id: 2, stock_id: 42, stock: { id: 42, symbol: 'INFY', name: 'Infosys' }, quantity: 15, avg_buy_price: 3000, strategy_id: 18, strategy_name: 'Dividend Core', recommendation_id: 718, holding_episode_id: 'dividend-core-infY-episode' },
                ];
                const observedRequests = [];
                const externalRequests = [];
                const allowedOrigin = 'http://127.0.0.1:4177';
                page.on('request', (request) => observedRequests.push({ method: request.method(), path: new URL(request.url()).pathname }));
                await installTosApiMocks(page, { recommendations: [], pipelineRecommendations: [recommendation, strategyBHold], retainApprovedRecommendations: true, fallbackUnmocked: true });
                await page.route('**/api/stocks/search**', (route) => route.fulfill({ json: { data: [] } }));
                await page.route('**/api/stocks/validate', (route) => route.fulfill({ json: { source: 'test fixture', data: { id: 42, symbol: 'INFY', name: 'Infosys Limited', exchange: 'NSE' }, meta: { cached: false } } }));
                await page.route('**/api/v1/recommendations/pending-execution', (route) => route.fulfill({ json: { success: true, data: approved && !savedTransaction ? [{ ...recommendation, status: 'pending_execution', can_execute_manually: true }] : [], meta: { cash: { cash_balance: 0, reserved_cash: 0, available_investable_cash: 0 } } } }));
                await page.route('**/api/transactions**', async (route) => {
                    if (route.request().method() === 'GET') return route.fulfill({ json: { data: savedTransaction ? [savedTransaction] : [] } });
                    if (route.request().method() === 'POST') {
                        transactionPayload = route.request().postDataJSON();
                        savedTransaction = { ...transactionPayload, id: 9800 + recommendationId, stock: { id: 42, symbol: 'INFY', name: 'Infosys' }, symbol: 'INFY', stock_name: 'Infosys', strategy_id: 17, strategy_name: 'Momentum Core', recommendation_id: recommendationId, holding_episode_id: recommendation.holding_episode_id };
                        holdings = [{ ...holdings[1] }];
                        return route.fulfill({ status: 201, json: { success: true, data: savedTransaction, message: 'Transaction saved' } });
                    }
                    return route.fulfill({ status: 501, json: { error: 'Unexpected transaction method' } });
                });
                await page.route('**/api/holdings**', (route) => route.fulfill({ json: { data: holdings } }));
                await page.route('**/api/settings', (route) => route.fulfill({ json: { data: { fee_components: [] } } }));
                await page.route('**/*', async (route) => {
                    if (new URL(route.request().url()).origin !== allowedOrigin) {
                        externalRequests.push(route.request().url());
                        return route.abort();
                    }
                    return route.fallback();
                });

                await page.goto('/holdings');
                const strategyAHolding = page.getByRole('row').filter({ hasText: 'INFY' }).filter({ hasText: 'Momentum Core' });
                const strategyBHolding = page.getByRole('row').filter({ hasText: 'INFY' }).filter({ hasText: 'Dividend Core' });
                await expect(strategyAHolding).toContainText('10');
                await expect(strategyBHolding).toContainText('15');

                await page.goto('/recommendations');
                const pipelineRun = page.waitForRequest((request) => request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/v1/pipeline/run'));
                await page.getByRole('button', { name: 'Run decision pipeline' }).click();
                await pipelineRun;
                const generatedExit = page.getByRole('row').filter({ hasText: 'Momentum Core' });
                await expect(generatedExit).toContainText('pending_review');
                await expect(generatedExit).toContainText('Exit');
                await page.getByLabel('Show HOLD insights').check();
                await expect(page.getByRole('row').filter({ hasText: 'Dividend Core' })).toContainText('Hold');
                await generatedExit.getByRole('button', { name: 'Review' }).click();
                const detail = page.getByRole('dialog');
                await expect(detail).toContainText('Momentum Core');
                await expect(detail.getByRole('heading', { name: 'Primary exit reason' })).toBeVisible();
                await expect(detail).toContainText('strategy_exit_threshold');
                await expect(detail).toContainText('10');
                await expect(detail).toContainText('entry market gate is blocked');
                await detail.getByRole('button', { name: 'Approve' }).click();
                approved = true;
                await expect(detail).toHaveCount(0);

                await page.goto('/transactions/pending', { waitUntil: 'domcontentloaded' });
                const sellRow = page.getByRole('row').filter({ hasText: 'INFY' }).filter({ hasText: 'Momentum Core' });
                await expect(sellRow).toContainText('Exit');
                await expect(sellRow).toContainText('10');
                await expect(sellRow.getByRole('button', { name: 'Execute manually' })).toBeVisible();
                await sellRow.getByRole('button', { name: 'Execute manually' }).click();
                await expect(page.getByText(`Recording actual broker fill for recommendation #${recommendationId}.`)).toBeVisible();
                await expect(page.locator('#stock-symbol-input')).toHaveValue('INFY');
                await expect(page.getByLabel('Quantity')).toHaveValue('10');
                await page.getByRole('button', { name: 'Validate symbol' }).click();
                await expect(page.getByText(/Validated via test fixture/)).toBeVisible();
                await page.getByLabel('Transaction date').fill('09-Oct-2026');
                await page.getByLabel('Transaction date').blur();
                await page.addStyleTag({ content: '.lido-mobile-utility-actions { visibility: hidden !important; }' });
                await page.evaluate(() => {
                    window.scrollTo(0, 0);
                    document.querySelectorAll('*').forEach((element) => { if (element.scrollTop > 0) element.scrollTop = 0; });
                });
                await page.mouse.move(0, 0);
                await expect(page.locator('form').first()).toHaveScreenshot(`e2e-${journey}-actual-fill-form-${viewport.name}.png`, { animations: 'disabled', caret: 'hide' });
                await page.getByRole('button', { name: 'Save Transaction' }).click();
                await expect.poll(() => savedTransaction).toMatchObject({ recommendation_id: recommendationId, strategy_id: 17, type: 'sell', quantity: 10, price: 3500 });
                expect(transactionPayload).toMatchObject({ recommendation_id: recommendationId, source: 'recommendation', type: 'sell', quantity: 10, price: 3500 });

                await page.goto('/transactions/closed');
                const closedSell = page.getByRole('row').filter({ hasText: 'INFY' });
                await expect(closedSell).toContainText('3,500');
                await expect(closedSell).toContainText('10');

                await page.goto('/holdings');
                const remaining = page.getByRole('row').filter({ hasText: 'INFY' });
                await expect(remaining).toContainText('Dividend Core');
                await expect(remaining).toContainText('15');
                await expect(remaining).not.toContainText('Momentum Core');
                expect(holdings).toHaveLength(1);
                expect(holdings[0]).toMatchObject({ strategy_id: 18, strategy_name: 'Dividend Core', quantity: 15, avg_buy_price: 3000 });
                expect(observedRequests.some(({ method, path }) => method === 'POST' && (/\/orders(?:\/|$)/.test(path) || /\/execution\/(?:submit|submit-selected)/.test(path)))).toBe(false);
                expect(externalRequests).toEqual([]);
            });
        }
    }

    for (const viewport of VIEWPORTS) {
        test(`E2E-04 keeps live intent through strategy save and reconciles it only after pipeline run (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-04');
            await seedDeterministicJourney(page, `e2e04-supersession-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const prior = {
                ...OPEN_BUY_RECOMMENDATION,
                id: 804, strategy_id: 7, strategy_name: 'Momentum Core', strategy_version: 1,
                strategy_version_id: 70, status: 'pending_execution', lifecycle_status: 'pending_execution',
                execution_status: 'pending', can_review: false, can_execute_manually: true,
                suggested_quantity: 5, suggested_investment_amount: 17500,
                reserved_amount: 17500, reservation_status: 'reserved',
            };
            const superseded = {
                ...prior, status: 'superseded', lifecycle_status: 'superseded', review_status: 'superseded',
                can_execute_manually: false, reserved_amount: 0, reservation_status: 'released', superseded_by_id: 814,
            };
            const replacement = {
                ...OPEN_BUY_RECOMMENDATION,
                id: 814, strategy_id: 7, strategy_name: 'Reviewed Momentum Policy', strategy_version: 2,
                strategy_version_id: 71, status: 'pending_review', lifecycle_status: 'pending_review',
                can_review: true, suggested_quantity: 7, suggested_investment_amount: 24500,
                reserved_amount: 0, reservation_status: 'none', reasoning: 'Replacement after the policy change.',
            };
            await installInvestorWorkflowApiMocks(page);
            await installTosApiMocks(page, { recommendations: [prior], pipelineRecommendations: [superseded, replacement], fallbackUnmocked: true });
            const observed = [];
            page.on('request', (request) => observed.push({ method: request.method(), path: new URL(request.url()).pathname }));

            await page.goto('/strategy?strategy_id=7');
            await page.getByRole('button', { name: 'Portfolio Rules' }).click();
            await page.getByLabel('First entry %').fill('35');
            const saveRequest = page.waitForRequest((request) => request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/v1/strategy'));
            await page.getByRole('button', { name: 'Save', exact: true }).click();
            expect((await saveRequest).postDataJSON()).toMatchObject({ strategy_id: 7, config: { portfolio_rules: { first_entry_pct: 35 } } });

            await page.goto('/recommendations');
            await expect(page.getByRole('row').filter({ hasText: 'Momentum Core' }).filter({ hasText: 'pending_execution' })).toBeVisible();
            await expect(page.getByRole('row').filter({ hasText: 'INFY' })).toContainText('pending_execution');
            expect(observed.some(({ method, path }) => method === 'POST' && path.endsWith('/api/v1/pipeline/run'))).toBe(false);
            const pipelineRun = page.waitForRequest((request) => request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/v1/pipeline/run'));
            await page.getByRole('button', { name: 'Run decision pipeline' }).click();
            await pipelineRun;
            await expect(page.getByRole('row').filter({ hasText: 'INFY' }).filter({ hasText: 'pending_review' })).toBeVisible();
            await expect(page.getByRole('row').filter({ hasText: 'INFY' }).filter({ hasText: 'pending_execution' })).toHaveCount(0);

            const historyResponse = page.waitForResponse((response) => response.request().method() === 'GET'
                && new URL(response.url()).pathname.endsWith('/api/v1/recommendations')
                && new URL(response.url()).searchParams.get('all') === '1');
            await page.getByLabel('Include closed history').check();
            const historyPayload = await (await historyResponse).json();
            const history = page.getByRole('row').filter({ hasText: 'superseded' });
            await expect(history).toBeVisible();
            await history.getByRole('button', { name: 'Review' }).click();
            const detail = page.getByRole('dialog');
            await expect(detail).toContainText('no longer a current execution instruction');
            await expect(detail.getByRole('button', { name: 'Approve' })).toHaveCount(0);
            const returnedRecommendations = historyPayload?.data?.data ?? historyPayload?.data ?? [];
            expect(returnedRecommendations.find(({ id }) => id === 804)).toMatchObject({
                status: 'superseded', superseded_by_id: 814, reservation_status: 'released', reserved_amount: 0,
            });
            expect(returnedRecommendations.find(({ id }) => id === 814)).toMatchObject({
                status: 'pending_review', reservation_status: 'none', reserved_amount: 0,
            });
            expect(observed.some(({ method, path }) => method === 'POST' && /\/orders(?:\/|$)|\/execution\/(?:submit|submit-selected)/.test(path))).toBe(false);
        });
    }

    for (const viewport of VIEWPORTS) {
        test(`E2E-06 resolves a capital shortfall before separately approving and recording a BUY (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-06');
            await seedDeterministicJourney(page, `e2e06-capital-to-fill-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });

            const recommendationId = 806;
            const requestId = 6606;
            const allowedOrigin = 'http://127.0.0.1:4177';
            const recommendation = {
                ...OPEN_BUY_RECOMMENDATION,
                id: recommendationId, security_id: 42, stock_id: 42, symbol: 'INFY', name: 'Infosys Limited',
                strategy_id: 8, strategy_name: 'Momentum Core', strategy_version_id: 81,
                recommendation_type: 'OPEN_POSITION', portfolio_action: 'OPEN_POSITION', ui_label: 'Open',
                status: 'pending_review', lifecycle_status: 'pending_review', can_review: true,
                order_side: 'buy', capital_request_id: requestId,
                capital_allocation_status: 'AWAITING_LENDER_SELECTION',
                position_target_amount: 50000, this_cycle_amount: 50000,
                execution_plan: { position_target_amount: 50000, this_cycle_amount: 50000, filled_amount: 0, remaining_amount: 50000, is_first_entry: true },
                suggested_quantity: 14, suggested_investment_amount: 50000, reference_price: 3500,
                reasoning: 'Momentum entry remains valid; capital shortfall does not change the investment opinion.',
            };
            let lenderApproved = false;
            let tradeApproved = false;
            let savedTransaction = null;
            let transactionPayload = null;
            let cashBalance = 30000;
            let holdings = [];
            const observedRequests = [];
            const externalRequests = [];
            page.on('request', (request) => observedRequests.push({ method: request.method(), path: new URL(request.url()).pathname }));
            await installTosApiMocks(page, { recommendations: [recommendation], pipelineRecommendations: [recommendation], retainApprovedRecommendations: true });
            await page.route('**/api/stocks/search**', (route) => route.fulfill({ json: { data: [] } }));
            await page.route('**/api/stocks/validate', (route) => route.fulfill({ json: { source: 'test fixture', data: { id: 42, symbol: 'INFY', name: 'Infosys Limited', exchange: 'NSE' }, meta: { cached: false } } }));
            await page.route('**/api/v1/recommendations/pending-execution', (route) => route.fulfill({
                json: { success: true, data: tradeApproved && !savedTransaction ? [{ ...recommendation, status: 'pending_execution', lifecycle_status: 'pending_execution', can_review: false, can_execute_manually: true, suggested_quantity: 14, suggested_investment_amount: 50000 }] : [], meta: { cash: { cash_balance: cashBalance, reserved_cash: tradeApproved ? 50000 : 0, available_investable_cash: cashBalance } } },
            }));
            await page.route(/\/api\/v1\/recommendations(?:\?.*)?$/, async (route) => {
                if (route.request().method() !== 'GET') return route.fulfill({ status: 501, json: { error: 'Unexpected recommendation collection method' } });
                return route.fulfill({ json: { success: true, data: [{ ...recommendation, capital_allocation_status: lenderApproved ? 'CAPITAL_COMMITTED' : 'AWAITING_LENDER_SELECTION', can_review: !tradeApproved, status: tradeApproved ? 'pending_execution' : 'pending_review' }] } });
            });
            await page.route(`**/api/v1/recommendations/${recommendationId}`, (route) => route.fulfill({
                json: { success: true, data: { ...recommendation, capital_allocation_status: lenderApproved ? 'CAPITAL_COMMITTED' : 'AWAITING_LENDER_SELECTION', can_review: !tradeApproved, can_execute_manually: tradeApproved, status: tradeApproved ? 'pending_execution' : 'pending_review', lifecycle_status: tradeApproved ? 'pending_execution' : 'pending_review' } },
            }));
            await page.route(`**/api/v1/recommendations/${recommendationId}/review`, async (route) => {
                const payload = route.request().postDataJSON();
                if (payload?.decision !== 'approved' || !lenderApproved) return route.fulfill({ status: 409, json: { success: false, error: { code: 'CAPITAL_NOT_COMMITTED' } } });
                tradeApproved = true;
                return route.fulfill({ json: { success: true, data: { status: 'pending_execution' } } });
            });
            await page.route(`**/api/v1/recommendations/${recommendationId}/capital-resolution`, (route) => route.fulfill({
                json: { success: true, data: { recommendation_id: recommendationId, requested_investment_amount: 50000, own_capital_used: 30000, recalled_capital_requested: 0, recalled_capital_received: 0, bridge_capital_used: lenderApproved ? 20000 : 0, total_immediately_available: lenderApproved ? 50000 : 30000, unresolved_amount: lenderApproved ? 0 : 20000, capital_resolution_state: lenderApproved ? 'funded' : 'unfunded' } },
            }));
            await page.route(`**/api/v1/capital/requests/${requestId}/lenders`, (route) => route.fulfill({
                json: { success: true, data: { amount: 20000, lenders: [{ strategy_id: 18, name: 'Dividend Core', available_for_lending: 30000 }] } },
            }));
            await page.route(`**/api/v1/capital/requests/${requestId}/approve`, async (route) => {
                if (route.request().postDataJSON()?.lender_strategy_id !== 18) return route.fulfill({ status: 422, json: { success: false, error: { code: 'INVALID_LENDER' } } });
                lenderApproved = true;
                cashBalance += 20000;
                return route.fulfill({ json: { success: true, data: { status: 'committed', lender_strategy_id: 18, amount: 20000 } } });
            });
            await page.route('**/api/transactions**', async (route) => {
                if (route.request().method() === 'GET') return route.fulfill({ json: { data: savedTransaction ? [savedTransaction] : [] } });
                if (route.request().method() === 'POST') {
                    transactionPayload = route.request().postDataJSON();
                    savedTransaction = { ...transactionPayload, id: 9806, stock_id: 42, stock: { id: 42, symbol: 'INFY', name: 'Infosys Limited' }, symbol: 'INFY', stock_name: 'Infosys Limited', strategy_id: 8, strategy_name: 'Momentum Core', recommendation_id: recommendationId };
                    cashBalance -= Number(transactionPayload.quantity) * Number(transactionPayload.price) + Number(transactionPayload.fees || 0);
                    holdings = [{ id: 9106, stock_id: 42, stock: { id: 42, symbol: 'INFY', name: 'Infosys Limited' }, quantity: Number(transactionPayload.quantity), avg_buy_price: Number(transactionPayload.price), strategy_id: 8, strategy_name: 'Momentum Core', recommendation_id: recommendationId }];
                    return route.fulfill({ status: 201, json: { success: true, data: savedTransaction, message: 'Transaction saved' } });
                }
                return route.fulfill({ status: 501, json: { error: 'Unexpected transaction method' } });
            });
            await page.route('**/api/holdings**', (route) => route.fulfill({ json: { data: holdings } }));
            await page.route('**/api/settings', (route) => route.fulfill({ json: { data: { fee_components: [] } } }));
            await page.route('**/api/cash/statement*', (route) => route.fulfill({ json: { data: { entries: [] }, meta: { current_page: 1, last_page: 1 } } }));
            await page.route(/\/api\/cash(?:\?.*)?$/, (route) => route.fulfill({ json: { data: { cash_balance: cashBalance, reserved_cash: tradeApproved ? 50000 : 0, available_investable_cash: cashBalance, available_physical_cash: cashBalance } } }));
            await page.route('**/*', async (route) => {
                if (new URL(route.request().url()).origin !== allowedOrigin) { externalRequests.push(route.request().url()); return route.abort(); }
                return route.fallback();
            });

            await page.goto('/recommendations');
            const pipelineRun = page.waitForRequest((request) => request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/v1/pipeline/run'));
            await page.getByRole('button', { name: 'Run decision pipeline' }).click();
            await pipelineRun;
            const row = page.getByRole('row').filter({ hasText: 'Momentum Core' });
            await expect(row).toContainText('Open');
            await expect(row).toContainText('Select lender');
            await row.getByRole('button', { name: 'Review' }).click();
            const initialDetail = page.getByRole('dialog');
            await expect(initialDetail.getByText('Position target (OD-12)')).toBeVisible();
            await expect(initialDetail).toContainText('Target ₹50000');
            await expect(initialDetail).toContainText('Actual execution amount');
            await expect(initialDetail).toContainText('30,000');
            await initialDetail.getByText('Close', { exact: true }).click();
            await row.getByRole('button', { name: 'Select lender' }).click();
            const lenderPanel = page.getByText('Approving commits capital; you still Approve the trade separately.');
            await expect(lenderPanel).toBeVisible();
            await expect(lenderPanel).toContainText('20,000');
            const lenderSelect = page.getByLabel('Lender strategy');
            await expect(lenderSelect).toHaveValue('18');
            const lenderApproval = page.waitForRequest((request) => request.method() === 'POST' && new URL(request.url()).pathname.endsWith(`/api/v1/capital/requests/${requestId}/approve`));
            await page.getByRole('button', { name: 'Approve lender' }).click();
            expect((await lenderApproval).postDataJSON()).toEqual({ lender_strategy_id: 18 });
            expect(lenderApproved).toBe(true);
            const detail = page.getByRole('dialog');
            await expect(detail.getByText('Position target (OD-12)')).toBeVisible();
            await expect(detail).toContainText('Target ₹50000');
            await expect(detail).toContainText('Actual execution amount');
            await expect(detail).toContainText('50,000');
            await expect(detail).toContainText('Target ₹50000');
            await expect(detail.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();

            await detail.getByRole('button', { name: 'Approve', exact: true }).click();
            expect(tradeApproved).toBe(true);
            await expect(page.getByRole('row').filter({ hasText: 'Momentum Core' })).toContainText('pending_execution');
            await page.goto('/transactions/pending', { waitUntil: 'domcontentloaded' });
            const pendingRow = page.getByRole('row').filter({ hasText: 'INFY' });
            await expect(pendingRow).toContainText('50,000');
            await pendingRow.getByRole('button', { name: 'Execute manually' }).click();
            await expect(page).toHaveURL('/transactions');
            await expect(page.getByText(`Recording actual broker fill for recommendation #${recommendationId}.`)).toBeVisible();
            await page.getByLabel('Quantity').fill('9');
            await page.getByRole('button', { name: 'Validate symbol' }).click();
            await expect(page.getByText(/Validated via test fixture/)).toBeVisible();
            await page.getByRole('button', { name: 'Save Transaction' }).click();
            await expect.poll(() => savedTransaction).toMatchObject({ recommendation_id: recommendationId, strategy_id: 8, type: 'buy', quantity: 9, price: 3500 });
            expect(transactionPayload).toMatchObject({ recommendation_id: recommendationId, source: 'recommendation', type: 'buy', quantity: 9, price: 3500 });
            await page.goto('/transactions');
            await expect(page.getByRole('row').filter({ hasText: 'INFY' })).toContainText('9');
            await page.goto('/cash');
            await expect(page.getByText(Math.round(cashBalance).toLocaleString('en-IN')).first()).toBeVisible();
            await page.goto('/holdings');
            const holding = page.getByRole('row').filter({ hasText: 'INFY' });
            await expect(holding).toContainText('Momentum Core');
            await expect(holding).toContainText('9');
            expect(holdings).toEqual([expect.objectContaining({ strategy_id: 8, strategy_name: 'Momentum Core', quantity: 9, recommendation_id: recommendationId })]);
            expect(observedRequests.some(({ method, path }) => method === 'POST' && (/\/orders(?:\/|$)/.test(path) || /\/execution\/(?:submit|submit-selected)/.test(path)))).toBe(false);
            expect(externalRequests).toEqual([]);
        });
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
