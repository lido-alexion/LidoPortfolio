import { test, expect } from '@playwright/test';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

test.describe('FEAT-064 screener investor workflow (browser smoke)', () => {
    test('list → new editor → save opens runtime screener detail', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/screeners');

        await expect(page.getByRole('heading', { name: 'Screener' }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'New screener' })).toBeVisible();

        await page.getByRole('link', { name: 'New screener' }).click();
        await expect(page.getByRole('heading', { name: 'New screener' })).toBeVisible();

        await page.getByPlaceholder('e.g. Golden cross holdings').fill('E2E ROC Gate');
        await page.getByRole('button', { name: 'Save' }).click();

        await expect(page).toHaveURL(/\/screeners\/\d+$/);
        await expect(page.getByRole('heading', { name: 'Edit screener' })).toBeVisible();
    });

    test('incomplete Strategy remains Setup Required and cannot be enabled', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/strategy?strategy_id=7');

        await expect(page.getByRole('heading', { name: 'Strategy' })).toBeVisible();
        await expect(page.getByText('Setup required')).toBeVisible();

        const enable = page.getByRole('button', { name: 'Enable' });
        await expect(enable).toBeVisible();
        await expect(enable).toBeDisabled();
        await expect(page.getByText('Add at least one eligibility Screener.')).toBeVisible();
    });
});
