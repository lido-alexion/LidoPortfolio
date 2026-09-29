import { test, expect } from '@playwright/test';

test('production smoke is available and non-destructive', async ({ page }) => {
    test.skip(!process.env.STOX_E2E_BASE_URL, 'Set STOX_E2E_BASE_URL for controlled production smoke.');
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page).toHaveTitle(/StoX|Lido/i);
    await expect(page.locator('body')).not.toContainText(/Internal Server Error/i);
});
