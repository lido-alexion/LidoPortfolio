import { test, expect } from '@playwright/test';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

test.use({
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
});

test.describe('FEAT-061 guided tour mobile browser acceptance', () => {
    test('welcome dialog and first step remain usable on a narrow viewport', async ({ page }) => {
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

        const welcome = page.getByRole('dialog', { name: 'Welcome to StoX' });
        await expect(welcome).toBeVisible();
        const welcomeBox = await welcome.boundingBox();
        expect(welcomeBox).not.toBeNull();
        expect(welcomeBox.width).toBeLessThanOrEqual(390);

        await page.getByRole('button', { name: 'Begin tour' }).click();

        const firstStep = page.getByRole('dialog').filter({
            has: page.getByRole('heading', { name: 'Navigation' }),
        });
        await expect(firstStep).toBeVisible();
        await expect(firstStep.getByRole('button', { name: 'Next' })).toBeVisible();
        const stepBox = await firstStep.boundingBox();
        expect(stepBox).not.toBeNull();
        expect(stepBox.width).toBeLessThanOrEqual(390);
    });
});
