import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

test.describe('V9-UX-001 strategy journeys', () => {
    for (const viewport of VIEWPORTS) {
        test('STR-01 creates a strategy and binds an existing screener (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'STR-01');
            await seedDeterministicJourney(page, 'strategy-str01-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page, {
                initialScreeners: [{ id: 41, name: 'Momentum Entry — MA200 + RSI', scope: 'holdings', is_enabled: true,
                    definition_json: { root: { type: 'group', op: 'AND', children: [] } }, description: 'Validated entry discovery rule.' }],
            });
            await page.goto('/strategy');
            await page.getByRole('button', { name: 'Create Strategy', exact: true }).click();
            await page.locator('#create-strategy-name').fill('Momentum Core');
            const createResponse = page.waitForResponse((response) => (
                response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/api/v1/strategies')
            ));
            await page.getByRole('button', { name: 'Create Strategy', exact: true }).last().click();
            const created = await createResponse;
            expect(created.status()).toBe(201);
            expect((await created.json()).data).toMatchObject({ id: 8, name: 'Momentum Core', version: 1, version_label: '1.0', version_status: 'draft' });
            await expect(page).toHaveURL(/\/strategy\?strategy_id=8$/);
            await expect(page.getByText('Momentum Core', { exact: true }).first()).toBeVisible();

            await page.getByRole('button', { name: 'Eligibility Sources' }).click();
            await page.locator('select').filter({ has: page.locator('option[value="41"]') }).selectOption('41');
            await page.getByRole('button', { name: 'Add', exact: true }).click();
            await expect(page.getByRole('row', { name: /Momentum Entry — MA200 \+ RSI/ })).toBeVisible();

            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/v1/strategy')
            ));
            await page.getByRole('button', { name: 'Save', exact: true }).click();
            const payload = (await saveRequest).postDataJSON();
            expect(payload).toMatchObject({ strategy_id: 8, name: 'Momentum Core' });
            expect(payload.config.eligibility_sources).toEqual(expect.arrayContaining([
                expect.objectContaining({ screener_id: 41, enabled: true }),
            ]));
            expect(payload.config.indicators).toEqual(expect.arrayContaining([
                expect.objectContaining({ key: 'momentum_score', enabled: true, weight: 100 }),
            ]));
            expect(payload.config).toHaveProperty('thresholds');
            expect(payload.config).toHaveProperty('portfolio_rules');
            expect(payload.config).toHaveProperty('capital_allocation');
            expect(payload.config).toHaveProperty('exit_strategy');
            expect(payload.config).toHaveProperty('market_gates');
            await expect(page.getByRole('button', { name: 'Enable', exact: true })).toBeDisabled();
        test('STR-02 builds a screener first, then uses it in a new strategy (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'STR-02');
            await seedDeterministicJourney(page, 'strategy-str02-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            await page.goto('/screeners/new');
            await page.getByLabel('Name').fill('Momentum Entry — MA200 + RSI');
            const leaves = page.locator('.lido-screener-leaf');
            await leaves.nth(0).getByLabel('left indicator').selectOption('close');
            await leaves.nth(0).getByLabel('right indicator').selectOption('sma');
            await leaves.nth(0).getByLabel('Period').fill('200');
            await page.getByRole('button', { name: '+ Condition' }).click();
            await leaves.nth(1).getByLabel('left indicator').selectOption('rsi');
            await leaves.nth(1).getByLabel('Period').fill('14');
            await leaves.nth(1).getByLabel('Comparator').selectOption('lt');
            await leaves.nth(1).getByLabel('right constant').fill('70');
            const screenerSave = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/screeners')
            ));
            await page.getByRole('button', { name: 'Save' }).click();
            expect((await screenerSave).postDataJSON().definition_json.root.children).toHaveLength(2);
            await expect(page).toHaveURL(/\/screeners\/99$/);

            await page.goto('/strategy');
            await page.getByRole('button', { name: 'Create Strategy', exact: true }).click();
            await page.locator('#create-strategy-name').fill('Momentum Core');
            const createResponse = page.waitForResponse((response) => (
                response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/api/v1/strategies')
            ));
            await page.getByRole('button', { name: 'Create Strategy', exact: true }).last().click();
            const created = await createResponse;
            expect(created.status()).toBe(201);
            expect((await created.json()).data).toMatchObject({ id: 8, name: 'Momentum Core', version: 1, version_label: '1.0', version_status: 'draft' });
            await expect(page).toHaveURL(/\/strategy\?strategy_id=8$/);
            await page.getByRole('button', { name: 'Eligibility Sources' }).click();
            await page.locator('select').filter({ has: page.locator('option[value="99"]') }).selectOption('99');
            await page.getByRole('button', { name: 'Add', exact: true }).click();
            await expect(page.getByRole('row', { name: /Momentum Entry — MA200 \+ RSI/ })).toBeVisible();
            const strategySave = page.waitForRequest((request) => (
                request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/v1/strategy')
            ));
            await page.getByRole('button', { name: 'Save', exact: true }).click();
            const payload = (await strategySave).postDataJSON();
            expect(payload.config.eligibility_sources).toEqual(expect.arrayContaining([
                expect.objectContaining({ screener_id: 99, screener_name: 'Momentum Entry — MA200 + RSI', enabled: true }),
            ]));
        });
        test('STR-03 saves entry criteria, market gates, scoring thresholds, and portfolio limits (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'STR-03');
            await seedDeterministicJourney(page, 'strategy-str03-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page, {
                initialScreeners: [{ id: 41, name: 'Momentum Entry', scope: 'holdings', is_enabled: true,
                    definition_json: { root: { type: 'group', op: 'AND', children: [] } }, description: 'Entry eligibility source.' }],
            });
            await page.goto('/strategy?strategy_id=7');
            await page.getByRole('button', { name: 'Eligibility Sources' }).click();
            await page.locator('select').filter({ has: page.locator('option[value="41"]') }).selectOption('41');
            await page.getByRole('button', { name: 'Add', exact: true }).click();
            await page.getByRole('button', { name: 'Recommendation Thresholds' }).click();
            const thresholdInputs = page.getByRole('spinbutton');
            for (const [index, value] of [35, 70, 80, 40, 25, 55].entries()) {
                await thresholdInputs.nth(index).fill(String(value));
            }
            await page.getByRole('button', { name: 'Market Gates' }).click();
            await page.getByLabel('Enable market gates').check();
            await page.getByLabel('Min sentiment').fill('55');
            await page.getByLabel('Max risk (raw)').fill('65');
            await page.getByRole('button', { name: 'Portfolio Rules' }).click();
            await page.getByLabel('Hard maximum holdings').fill('8');
            await page.getByLabel('First entry %').fill('40');
            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/v1/strategy')
            ));
            await page.getByRole('button', { name: 'Save', exact: true }).click();
            const config = (await saveRequest).postDataJSON().config;
            expect(config.eligibility_sources).toEqual(expect.arrayContaining([
                expect.objectContaining({ screener_id: 41, enabled: true }),
            ]));
            expect(config.thresholds).toMatchObject({ minimum_overall_score: 35, open_position: 70, increase_position: 80, reduce_position: 40, exit_position: 25, watch: 55 });
            expect(config.market_gates).toMatchObject({ enabled: true, min_sentiment: 55, max_risk_raw: 65 });
            expect(config.portfolio_rules).toMatchObject({ max_holdings: 8, first_entry_pct: 40 });
            await expect(page.getByRole('button', { name: 'Enable', exact: true })).toBeDisabled();
        });
        test('STR-04 configures a screener-backed exit and horizon for existing holdings (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'STR-04');
            await seedDeterministicJourney(page, 'strategy-str04-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page, {
                initialScreeners: [{ id: 41, name: 'Protective Exit Screen', scope: 'holdings', is_enabled: true,
                    definition_json: { root: { type: 'group', op: 'AND', children: [] } }, description: 'Exit evidence source.' }],
            });
            await page.goto('/strategy?strategy_id=7');
            await page.getByRole('button', { name: 'Exit Strategy' }).click();
            await page.getByLabel('Enable Screener Exit').check();
            await page.getByLabel('Exit screener').selectOption('41');
            await page.getByLabel('Horizon (calendar days)').fill('180');
            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/v1/strategy')
            ));
            await page.getByRole('button', { name: 'Save', exact: true }).click();
            const config = (await saveRequest).postDataJSON().config;
            expect(config.exit_strategy).toMatchObject({ enabled: true, mode: 'any' });
            expect(config.exit_strategy.rules).toEqual(expect.arrayContaining([
                expect.objectContaining({ key: 'screener_exit', enabled: true, screener_id: 41, screener_name: 'Protective Exit Screen' }),
            ]));
            expect(config.portfolio_rules.horizon_calendar_days).toBe(180);
            await expect(page.getByRole('button', { name: 'Enable', exact: true })).toBeDisabled();
        });
        test('STR-05 keeps entry and exit screener definitions separate (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'STR-05');
            await seedDeterministicJourney(page, 'strategy-str05-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page, {
                initialScreeners: [
                    { id: 41, name: 'Momentum Entry Screen', scope: 'holdings', is_enabled: true, definition_json: { root: { type: 'group', op: 'AND', children: [] } }, description: 'Entry rule.' },
                    { id: 42, name: 'Protective Exit Screen', scope: 'holdings', is_enabled: true, definition_json: { root: { type: 'group', op: 'AND', children: [] } }, description: 'Exit rule.' },
                ],
            });
            await page.goto('/strategy?strategy_id=7');
            await page.getByRole('button', { name: 'Eligibility Sources' }).click();
            await page.locator('select').filter({ has: page.locator('option[value="41"]') }).selectOption('41');
            await page.getByRole('button', { name: 'Add', exact: true }).click();
            await page.getByRole('button', { name: 'Exit Strategy' }).click();
            await page.getByLabel('Enable Screener Exit').check();
            await page.getByLabel('Exit screener').selectOption('42');
            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/v1/strategy')
            ));
            await page.getByRole('button', { name: 'Save', exact: true }).click();
            const config = (await saveRequest).postDataJSON().config;
            expect(config.eligibility_sources).toEqual(expect.arrayContaining([
                expect.objectContaining({ screener_id: 41, screener_name: 'Momentum Entry Screen', enabled: true }),
            ]));
            const exitRule = config.exit_strategy.rules.find((rule) => rule.key === 'screener_exit');
            expect(exitRule).toMatchObject({ enabled: true, screener_id: 42, screener_name: 'Protective Exit Screen' });
            expect(exitRule.screener_id).not.toBe(config.eligibility_sources[0].screener_id);
        });
        test('STR-06 configures factor weights and ordered score thresholds (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'STR-06');
            await seedDeterministicJourney(page, 'strategy-str06-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            await page.goto('/strategy?strategy_id=7');
            await page.getByRole('button', { name: 'Scoring Model' }).click();
            await expect(page.getByRole('row', { name: /Momentum/ })).toBeVisible();
            await page.getByLabel('Enable RSI').check();
            await page.getByLabel('Weight for Momentum').fill('80');
            await page.getByLabel('Weight for RSI').fill('20');
            await expect(page.getByText(/Enabled weight total: 100/)).toBeVisible();
            await page.getByRole('button', { name: 'Recommendation Thresholds' }).click();
            const thresholdInputs = page.getByRole('spinbutton');
            for (const [index, value] of [35, 70, 80, 40, 25, 55].entries()) {
                await thresholdInputs.nth(index).fill(String(value));
            }
            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/v1/strategy')
            ));
            await page.getByRole('button', { name: 'Save', exact: true }).click();
            const config = (await saveRequest).postDataJSON().config;
            expect(config.indicators).toEqual(expect.arrayContaining([
                expect.objectContaining({ key: 'momentum_score', enabled: true, weight: 80 }),
                expect.objectContaining({ key: 'rsi_score', enabled: true, weight: 20 }),
            ]));
            expect(config.thresholds).toMatchObject({ minimum_overall_score: 35, open_position: 70, increase_position: 80, reduce_position: 40, exit_position: 25, watch: 55 });
        });
        test('STR-07 configures portfolio sizing, concentration limits, and allocation (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'STR-07');
            await seedDeterministicJourney(page, 'strategy-str07-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            await page.goto('/strategy?strategy_id=7');
            await page.getByRole('button', { name: 'Portfolio Rules' }).click();
            const sizingInputs = page.getByRole('spinbutton');
            for (const [index, value] of [10, 2, 3, 12, 5, 8, 90, 40].entries()) {
                await sizingInputs.nth(index).fill(String(value));
            }
            await page.getByRole('button', { name: 'Capital Allocation' }).click();
            await page.locator('select').filter({ has: page.locator('option[value="equal_weight"]') }).selectOption('equal_weight');
            await page.locator('select').filter({ has: page.locator('option[value="highest_relative_strength"]') }).selectOption('highest_relative_strength');
            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/v1/strategy')
            ));
            await page.getByRole('button', { name: 'Save', exact: true }).click();
            const config = (await saveRequest).postDataJSON().config;
            expect(config.portfolio_rules).toMatchObject({
                max_position_size_pct: 10, min_position_size_pct: 2, max_new_positions_per_cycle: 3,
                max_exposure_per_stock_pct: 12, recommended_minimum_holdings: 5, max_holdings: 8, first_entry_pct: 40,
            });
            expect(config.weakest_position_window_days).toBe(90);
            expect(config.capital_allocation).toMatchObject({ strategy: 'equal_weight', tie_break: 'highest_relative_strength' });
            expect(config.portfolio_rules.max_position_size_pct).toBeGreaterThan(0);
        });
        test('STR-08 configures an explicit ATR based exit safeguard (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'STR-08');
            await seedDeterministicJourney(page, 'strategy-str08-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            await page.goto('/strategy?strategy_id=7');
            await page.getByRole('button', { name: 'Exit Strategy' }).click();
            await page.getByLabel('Enable ATR Stop').check();
            await page.getByLabel('Value for ATR Stop').fill('3');
            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/v1/strategy')
            ));
            await page.getByRole('button', { name: 'Save', exact: true }).click();
            const config = (await saveRequest).postDataJSON().config;
            expect(config.exit_strategy.enabled).toBe(true);
            expect(config.exit_strategy.rules).toEqual(expect.arrayContaining([
                expect.objectContaining({ key: 'atr_stop', enabled: true, atr_multiple: 3 }),
            ]));
        });
        test('STR-09 saves restrictive market entry gates without running recommendations (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'STR-09');
            await seedDeterministicJourney(page, 'strategy-str09-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            const requests = [];
            page.on('request', (request) => requests.push(new URL(request.url()).pathname));
            await page.goto('/strategy?strategy_id=7');
            await page.getByRole('button', { name: 'Market Gates' }).click();
            await page.getByLabel('Enable market gates').check();
            await page.getByLabel('Min sentiment').fill('60');
            await page.getByLabel('Max risk (raw)').fill('55');
            await page.getByLabel('Bear').check();
            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/v1/strategy')
            ));
            await page.getByRole('button', { name: 'Save', exact: true }).click();
            const config = (await saveRequest).postDataJSON().config;
            expect(config.market_gates).toMatchObject({ enabled: true, min_sentiment: 60, max_risk_raw: 55, allowed_phases: ['Bear'] });
            expect(requests.some((path) => path.endsWith('/api/v1/recommendations/run'))).toBe(false);
        });
        test('STR-10 edits an existing strategy in place without running the pipeline (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'STR-10');
            await seedDeterministicJourney(page, 'strategy-str10-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            const requests = [];
            page.on('request', (request) => requests.push(new URL(request.url()).pathname));
            await page.goto('/strategy?strategy_id=7');
            await page.locator('#strat-name').fill('Reviewed Momentum Policy');
            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/v1/strategy')
            ));
            await page.getByRole('button', { name: 'Save', exact: true }).click();
            const payload = (await saveRequest).postDataJSON();
            expect(payload).toMatchObject({ strategy_id: 7, name: 'Reviewed Momentum Policy' });
            expect(requests.some((path) => path.endsWith('/api/v1/recommendations/run'))).toBe(false);
        });
        test('STR-11 replaces an entry screener while retaining the strategy identity and other policy (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'STR-11');
            await seedDeterministicJourney(page, 'strategy-str11-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page, {
                initialScreeners: [
                    { id: 41, name: 'Original Entry Screen', scope: 'holdings', is_enabled: true, definition_json: { root: { type: 'group', op: 'AND', children: [] } }, description: 'Existing entry rule.' },
                    { id: 42, name: 'Revised Entry Screen', scope: 'holdings', is_enabled: true, definition_json: { root: { type: 'group', op: 'AND', children: [] } }, description: 'Replacement entry rule.' },
                ],
                initialStrategyEligibility: [{ screener_id: 41, screener_name: 'Original Entry Screen', description: 'Existing entry rule.', enabled: true, priority: 1, display_order: 0, condition_count: 1 }],
            });
            await page.goto('/strategy?strategy_id=7');
            await page.getByRole('button', { name: 'Eligibility Sources' }).click();
            await page.locator('select').filter({ has: page.locator('option[value="42"]') }).selectOption('42');
            await page.getByRole('button', { name: 'Add', exact: true }).click();
            const oldRow = page.getByRole('row', { name: /Original Entry Screen/ });
            await oldRow.getByRole('button', { name: 'Remove' }).click();
            await expect(page.getByRole('row', { name: /Revised Entry Screen/ })).toBeVisible();
            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/v1/strategy')
            ));
            await page.getByRole('button', { name: 'Save', exact: true }).click();
            const payload = (await saveRequest).postDataJSON();
            expect(payload.strategy_id).toBe(7);
            expect(payload.config.eligibility_sources).toEqual([
                expect.objectContaining({ screener_id: 42, screener_name: 'Revised Entry Screen', enabled: true }),
            ]);
            expect(payload.config.exit_strategy.rules).toHaveLength(4);
            expect(payload.config.indicators).toEqual(expect.arrayContaining([expect.objectContaining({ key: 'momentum_score', enabled: true, weight: 100 })]));
        });
        });
    }
});
