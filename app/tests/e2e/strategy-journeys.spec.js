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
        });
    }
});
