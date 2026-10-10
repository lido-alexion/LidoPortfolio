import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { PENDING_BUY_RECOMMENDATION } from '../js/tos/fixtures/tosApi.js';
import { seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { installTosApiMocks } from './tosApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

async function expectJourneyPageAccessible(page) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .include('#app')
        .analyze();
    expect(results.violations).toEqual([]);
}

test.describe('V9-UX-001 key journey accessibility', () => {
    for (const viewport of VIEWPORTS) {
        test(`strategy configuration has no WCAG 2A/2AA violations (${viewport.name})`, async ({ page }) => {
            await seedDeterministicJourney(page, `journey-a11y-strategy-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            await page.goto('/strategy');
            await expect(page.getByRole('heading', { name: 'Strategy' }).first()).toBeVisible();
            await expectJourneyPageAccessible(page);
        });

        test(`recommendation review has no WCAG 2A/2AA violations (${viewport.name})`, async ({ page }) => {
            await seedDeterministicJourney(page, `journey-a11y-recommendation-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page);
            await page.goto('/recommendations');
            await expect(page.getByRole('heading', { name: 'Recommendations' }).first()).toBeVisible();
            await expectJourneyPageAccessible(page);
        });

        test(`pending execution review has no WCAG 2A/2AA violations (${viewport.name})`, async ({ page }) => {
            await seedDeterministicJourney(page, `journey-a11y-pending-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page, { recommendations: [PENDING_BUY_RECOMMENDATION] });
            await page.goto('/transactions/pending');
            await expect(page.getByRole('heading', { name: 'Transactions' })).toBeVisible();
            await expect(page.getByRole('row').filter({ hasText: 'INFY' })).toBeVisible();
            await expectJourneyPageAccessible(page);
        });
    }
});
