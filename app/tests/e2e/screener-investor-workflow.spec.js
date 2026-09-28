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

test.describe('FEAT-061 investor guided tour (browser smoke)', () => {
    test('welcome modal begins the tour and renders the first step', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page, {
            guidedTourState: {
                eligible: true,
                show_welcome_prompt: true,
                can_manual_relaunch: true,
                welcome_shown_at: null,
                completed_at: null,
                tour_in_progress: false,
            },
        });
        await page.goto('/');

        await expect(page.getByRole('dialog', { name: 'Welcome to StoX' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Begin tour' })).toBeVisible();

        await page.getByRole('button', { name: 'Begin tour' }).click();

        await expect(page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: 'Navigation' }) })).toBeVisible();
        await expect(page.getByRole('dialog').getByText('Step 1 of 8', { exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Navigation' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Next' })).toBeVisible();
    });
});
