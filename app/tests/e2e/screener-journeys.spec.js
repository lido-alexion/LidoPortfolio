import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

test.describe('V9-UX-001 screener journeys', () => {
    for (const viewport of VIEWPORTS) {
        test(`SCR-01 creates and saves Close > SMA(200) (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'SCR-01');
            await seedDeterministicJourney(page, `screener-scr01-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            await page.goto('/screeners/new');

            await expect(page.getByRole('heading', { name: 'New screener' })).toBeVisible();
            await page.getByLabel('Name').fill('Price Above MA200');
            await page.getByLabel('left indicator').selectOption('close');
            await page.getByLabel('right indicator').selectOption('sma');
            await page.getByLabel('Period').fill('200');

            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/screeners')
            ));
            await page.getByRole('button', { name: 'Save' }).click();
            const request = await saveRequest;
            const payload = request.postDataJSON();

            expect(payload.name).toBe('Price Above MA200');
            expect(payload.definition_json.root.op).toBe('AND');
            expect(payload.definition_json.root.children).toHaveLength(1);
            expect(payload.definition_json.root.children[0]).toMatchObject({
                type: 'condition',
                left: { indicator: 'close' },
                operator: 'gt',
                right: { indicator: 'sma', params: { period: 200 } },
            });
            await expect(page).toHaveURL(/\/screeners\/\d+$/);
            await expect(page.getByRole('heading', { name: 'Edit screener' })).toBeVisible();
        });

        test(`SCR-02 saves Price > SMA(200) AND RSI(14) < 70 (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'SCR-02');
            await seedDeterministicJourney(page, `screener-scr02-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            await page.goto('/screeners/new');
            await expect(page.getByRole('heading', { name: 'New screener' })).toBeVisible();

            await page.getByLabel('Name').fill('Momentum Entry — MA200 + RSI');
            const leaves = page.locator('.lido-screener-leaf');
            const first = leaves.nth(0);
            await first.getByLabel('left indicator').selectOption('close');
            await first.getByLabel('right indicator').selectOption('sma');
            await first.getByLabel('Period').fill('200');

            await page.getByRole('button', { name: '+ Condition' }).click();
            const second = leaves.nth(1);
            await second.getByLabel('left indicator').selectOption('rsi');
            await second.getByLabel('Period').fill('14');
            await second.getByLabel('Comparator').selectOption('lt');
            await second.getByLabel('right constant').fill('70');

            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/screeners')
            ));
            await page.getByRole('button', { name: 'Save' }).click();
            const payload = (await saveRequest).postDataJSON();
            expect(payload.name).toBe('Momentum Entry — MA200 + RSI');
            expect(payload.definition_json.root).toMatchObject({ type: 'group', op: 'AND' });
            expect(payload.definition_json.root.children).toHaveLength(2);
            expect(payload.definition_json.root.children[0]).toMatchObject({
                type: 'condition', left: { indicator: 'close' }, operator: 'gt',
                right: { indicator: 'sma', params: { period: 200 } },
            });
            expect(payload.definition_json.root.children[1]).toMatchObject({
                type: 'condition', left: { indicator: 'rsi', params: { period: 14 } }, operator: 'lt',
                right: { type: 'constant', value: 70 },
            });
            await expect(page).toHaveURL(/\/screeners\/\d+$/);
        });

        test(`SCR-03 saves an AND group with nested momentum alternatives (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'SCR-03');
            await seedDeterministicJourney(page, `screener-scr03-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            await page.goto('/screeners/new');
            await page.getByLabel('Name').fill('Trend with Momentum Alternatives');

            const groups = page.locator('.lido-screener-group');
            const leaves = page.locator('.lido-screener-leaf');
            const trend = leaves.nth(0);
            await trend.getByLabel('left indicator').selectOption('close');
            await trend.getByLabel('right indicator').selectOption('sma');
            await trend.getByLabel('Period').fill('200');
            await groups.nth(0).getByRole('button', { name: '+ Group' }).click();

            const alternatives = groups.nth(1);
            const rsi = leaves.nth(1);
            await rsi.getByLabel('left indicator').selectOption('rsi');
            await rsi.getByLabel('Comparator').selectOption('lt');
            await rsi.getByLabel('right constant').fill('70');
            await alternatives.getByRole('button', { name: '+ Condition' }).click();

            const roc = leaves.nth(2);
            await roc.getByLabel('left indicator').selectOption('roc');
            await roc.getByLabel('Comparator').selectOption('gt');
            await roc.getByLabel('right constant').fill('0');

            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/screeners')
            ));
            await page.getByRole('button', { name: 'Save' }).click();
            const payload = (await saveRequest).postDataJSON();
            const root = payload.definition_json.root;
            expect(root).toMatchObject({ type: 'group', op: 'AND' });
            expect(root.children).toHaveLength(2);
            expect(root.children[0]).toMatchObject({
                type: 'condition', left: { indicator: 'close' }, operator: 'gt',
                right: { indicator: 'sma', params: { period: 200 } },
            });
            expect(root.children[1]).toMatchObject({ type: 'group', op: 'OR' });
            expect(root.children[1].children).toHaveLength(2);
            expect(root.children[1].children[0]).toMatchObject({
                type: 'condition', left: { indicator: 'rsi', params: { period: 14 } }, operator: 'lt',
                right: { type: 'constant', value: 70 },
            });
            expect(root.children[1].children[1]).toMatchObject({
                type: 'condition', left: { indicator: 'roc', params: { period: 12 } }, operator: 'gt',
                right: { type: 'constant', value: 0 },
            });
        });
    }
});
