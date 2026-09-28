import { test, expect } from '@playwright/test';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

test.describe('FEAT-062 fundamental insights browser acceptance', () => {
    test('shows deterministic evidence when AI interpretation is unavailable', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/fundamentals/insights/TCS');

        await expect(page.getByRole('heading', { name: 'Fundamental insights' }).last()).toBeVisible();
        await expect(page.getByRole('heading', { name: 'TCS' })).toBeVisible();
        await expect(page.getByText('StoX deterministic evidence')).toBeVisible();
        await expect(page.getByText('Cash generation is aligned with reported earnings')).toBeVisible();
        await expect(page.getByText('Data sufficiency: sufficient')).toBeVisible();
        await expect(page.getByText('AI insights: AI insights are currently unavailable.')).toBeVisible();
        await expect(page.getByText('Review the latest annual report cash-flow notes.')).toBeVisible();
        await expect(page.getByText('Not investment advice.')).toBeVisible();
    });

    test('keeps the investor page usable when the insights provider fails', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page, {
            fundamentalInsightsError: 'Fundamental insights are temporarily unavailable',
        });
        await page.goto('/fundamentals/insights/TCS');

        await expect(page.getByRole('heading', { name: 'Fundamental insights' }).last()).toBeVisible();
        await expect(page.getByText('Fundamental insights are temporarily unavailable')).toBeVisible();
        await expect(page.getByText('Loading insights…')).not.toBeVisible();
    });
});
