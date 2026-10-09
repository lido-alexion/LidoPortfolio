import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

test.describe('V9-UX-001 account entry', () => {
    for (const viewport of VIEWPORTS) {
        test(`AUTH-01 signs in and returns to the requested protected page (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'AUTH-01');
            await seedDeterministicJourney(page, `auth-entry-success-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page, { initiallyUnauthenticated: true });

            await page.goto('/screeners?source=auth-entry');
            await expect(page.getByRole('heading', { name: 'Login' })).toBeVisible();
            const accessibility = await new AxeBuilder({ page }).include('.login-card').withTags(['wcag2a', 'wcag2aa']).analyze();
            expect(accessibility.violations).toEqual([]);
            await page.getByLabel('Email').fill('investor@example.test');
            await page.getByLabel('Password').fill('correct-test-password');
            await page.getByRole('button', { name: 'Login' }).click();

            await expect(page).toHaveURL(/\/screeners\?source=auth-entry$/);
            await expect(page.getByRole('heading', { name: 'Screener' }).first()).toBeVisible();
        });

        test(`AUTH-02 rejected credentials are announced and can be retried (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'AUTH-02');
            await seedDeterministicJourney(page, `auth-entry-retry-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page, {
                initiallyUnauthenticated: true,
                failedLoginAttempts: 1,
            });

            await page.goto('/screeners?source=auth-retry');
            await page.getByLabel('Email').fill('investor@example.test');
            await page.getByLabel('Password').fill('first-incorrect-password');
            await page.getByRole('button', { name: 'Login' }).click();

            await expect(page.getByRole('alert')).toHaveText('Email or password is incorrect.');
            await expect(page).toHaveURL(/\/screeners\?source=auth-retry$/);
            await expect(page.getByRole('button', { name: 'Login' })).toBeEnabled();

            await page.getByLabel('Password').fill('correct-test-password');
            await page.getByRole('button', { name: 'Login' }).click();
            await expect(page).toHaveURL(/\/screeners\?source=auth-retry$/);
            await expect(page.getByRole('heading', { name: 'Screener' }).first()).toBeVisible();
        });
    }
});
