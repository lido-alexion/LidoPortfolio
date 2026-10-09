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
    }
});
