import { test, expect } from '@playwright/test';
import { installTosApiMocks } from './tosApiMocks.js';

test.describe('TOS recommendations (browser smoke)', () => {
    test('authenticated shell shows INFY and the approve path works', async ({ page }) => {
        await installTosApiMocks(page);
        await page.goto('/recommendations');

        await expect(page.getByRole('heading', { name: 'Recommendations' }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'StoX by Lido Alexion' })).toBeVisible();
        await expect(page.getByText('INFY')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Run decision pipeline' })).toBeVisible();

        await page.getByRole('button', { name: 'Review', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Approve' })).toBeVisible();

        await dialog.getByRole('button', { name: 'Approve' }).click();
        await expect(dialog).toHaveCount(0);
        await expect(page.getByText(/No trade recommendations/i)).toBeVisible();
    });
});
