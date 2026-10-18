import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

test.describe('V9-UX-001 strategy configuration visual regression', () => {
    for (const viewport of VIEWPORTS) {
        test('STR-06 shows the configured recommendation-threshold panel (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'STR-06');
            await seedDeterministicJourney(page, `strategy-str06-threshold-panel-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);

            const externalRequests = [];
            const mutationRequests = [];
            page.on('request', (request) => {
                const url = new URL(request.url());
                if (url.origin !== 'http://127.0.0.1:4177') externalRequests.push(`${request.method()} ${url.origin}`);
                if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(request.method())) {
                    mutationRequests.push(`${request.method()} ${url.pathname}`);
                }
            });

            await page.goto('/strategy?strategy_id=7');
            await expect(page.getByRole('heading', { name: 'Strategy', exact: true })).toBeVisible();
            await expect(page.getByRole('combobox', { name: 'Select strategy to edit' })).toHaveValue('7');

            const thresholdsTab = page.getByRole('button', { name: 'Recommendation Thresholds', exact: true });
            await thresholdsTab.click();
            await expect(thresholdsTab).toHaveClass(/btn-primary/);

            const thresholdPanel = page.getByText(/These cutoffs are compared against the strategy's overall score/).locator('..');
            await expect(thresholdPanel.getByText('Minimum Overall Score', { exact: true })).toBeVisible();
            await expect(thresholdPanel.getByText('Open Position', { exact: true })).toBeVisible();
            await expect(thresholdPanel.getByText('Increase Position', { exact: true })).toBeVisible();
            await expect(thresholdPanel.getByText('Reduce Position', { exact: true })).toBeVisible();
            await expect(thresholdPanel.getByText('Exit Position', { exact: true })).toBeVisible();
            await expect(thresholdPanel.getByText('Watch', { exact: true })).toBeVisible();

            const thresholds = [35, 70, 80, 40, 25, 55];
            const inputs = thresholdPanel.locator('.lido-number-input-field');
            await expect(inputs).toHaveCount(thresholds.length);
            for (const [index, value] of thresholds.entries()) {
                await inputs.nth(index).fill(String(value));
                await expect(inputs.nth(index)).toHaveValue(String(value));
            }

            expect(externalRequests).toEqual([]);
            expect(mutationRequests).toEqual([]);
            await expect(thresholdPanel).toHaveScreenshot(
                `str-06-recommendation-threshold-configuration-${viewport.name}.png`,
                { animations: 'disabled', caret: 'hide' },
            );
        });
    }
});
