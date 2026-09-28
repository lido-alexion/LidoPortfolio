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
        await expect(welcome).toHaveAttribute('aria-describedby', 'guided-tour-welcome-description');
        const welcomeBox = await welcome.boundingBox();
        expect(welcomeBox).not.toBeNull();
        expect(welcomeBox.width).toBeLessThanOrEqual(390);

        for (let index = 0; index < 5; index += 1) {
            await page.keyboard.press('Tab');
            await expect(welcome.locator(':focus')).toBeVisible();
        }
        await page.getByRole('button', { name: 'Begin tour' }).click();

        const firstStep = page.getByRole('dialog').filter({
            has: page.getByRole('heading', { name: 'Navigation' }),
        });
        await expect(firstStep).toBeVisible();
        await expect(firstStep).toHaveAttribute('aria-describedby', 'lido-guided-tour-description');
        await expect(page.getByRole('status')).toContainText('1');
        await expect(firstStep.getByRole('button', { name: 'Next' })).toBeVisible();
        const stepBox = await firstStep.boundingBox();
        expect(stepBox).not.toBeNull();
        expect(stepBox.width).toBeLessThanOrEqual(390);

        for (let index = 0; index < 6; index += 1) {
            await page.keyboard.press('Tab');
            await expect(firstStep.locator(':focus')).toBeVisible();
        }
    });

    test('closing a manually launched tour restores focus to its trigger', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/');

        const trigger = page.locator('[data-tour="header-help"]');
        await expect(trigger).toBeVisible();
        await page.waitForTimeout(250);
        await page.evaluate(() => {
            document.querySelector('[data-tour="header-help"]')?.focus();
            window.dispatchEvent(new CustomEvent('lido-guided-tour-launch', { detail: { restart: true } }));
        });

        const firstStep = page.getByRole('dialog').filter({
            has: page.getByRole('heading', { name: 'Navigation' }),
        });
        await expect(firstStep).toBeVisible();
        await firstStep.getByRole('button', { name: 'Close' }).click();
        await expect(trigger).toBeFocused();
    });

    test('scrim captures background clicks without activating the highlighted navigation', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/');
        await page.waitForTimeout(250);
        await page.evaluate(() => {
            window.dispatchEvent(new CustomEvent('lido-guided-tour-launch', { detail: { restart: true } }));
        });

        const firstStep = page.getByRole('dialog').filter({
            has: page.getByRole('heading', { name: 'Navigation' }),
        });
        await expect(firstStep).toBeVisible();
        const holdingsLink = page.getByRole('link', { name: 'Holdings', exact: true });
        await expect(holdingsLink).toBeVisible();
        const linkBox = await holdingsLink.boundingBox();
        expect(linkBox).not.toBeNull();
        await page.mouse.click(linkBox.x + linkBox.width / 2, linkBox.y + linkBox.height / 2);

        await expect(firstStep).toBeVisible();
        await expect(page).toHaveURL(/\/$/);
    });

    test('resumes a persisted step and navigates to its configured route', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page, {
            guidedTourState: {
                eligible: true,
                show_welcome_prompt: true,
                can_manual_relaunch: true,
                welcome_shown_at: '2026-01-01T00:00:00Z',
                completed_at: null,
                current_step_id: 'holdings',
                tour_in_progress: true,
            },
        });
        await page.goto('/');

        await expect(page.getByRole('dialog', { name: 'Welcome to StoX' })).toBeVisible();
        await page.getByRole('button', { name: 'Begin tour' }).click();
        const resume = page.getByRole('dialog', { name: 'Resume tour?' });
        await expect(resume).toBeVisible();
        await expect(resume).toHaveAttribute('aria-describedby', 'guided-tour-resume-description');
        for (let index = 0; index < 3; index += 1) {
            await page.keyboard.press('Tab');
            await expect(resume.locator(':focus')).toBeVisible();
        }
        await page.getByRole('button', { name: 'Resume' }).click();

        await expect(page).toHaveURL(/\/holdings$/);
        const step = page.getByRole('dialog').filter({
            has: page.getByRole('heading', { name: 'Holdings' }),
        });
        await expect(step).toBeVisible({ timeout: 10_000 });
        await expect(step.getByText('3 of 8')).toBeVisible();
    });

    test('recovers an in-progress tour after a browser refresh', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page, {
            guidedTourState: {
                eligible: true,
                show_welcome_prompt: true,
                can_manual_relaunch: true,
                welcome_shown_at: null,
                completed_at: null,
                current_step_id: null,
                tour_in_progress: false,
            },
        });
        await page.goto('/');

        await page.getByRole('dialog', { name: 'Welcome to StoX' })
            .getByRole('button', { name: 'Begin tour' })
            .click();
        const firstStep = page.getByRole('dialog').filter({
            has: page.getByRole('heading', { name: 'Navigation' }),
        });
        await expect(firstStep).toBeVisible();

        await firstStep.getByRole('button', { name: 'Next' }).click();
        await expect(page.getByText('Step 2 of 8', { exact: true })).toBeVisible();

        await page.reload();

        await expect(page.getByRole('dialog', { name: 'Welcome to StoX' })).toBeVisible();
        await page.getByRole('dialog', { name: 'Welcome to StoX' })
            .getByRole('button', { name: 'Begin tour' })
            .click();
        const resume = page.getByRole('dialog', { name: 'Resume tour?' });
        await expect(resume).toBeVisible();
        await resume.getByRole('button', { name: 'Resume' }).click();

        const resumedStep = page.getByRole('dialog').filter({
            has: page.getByRole('heading', { name: 'Dashboard' }),
        });
        await expect(resumedStep).toBeVisible({ timeout: 10_000 });
        await expect(resumedStep.getByText('2 of 8')).toBeVisible();
    });

    test('traverses every configured investor-tour step', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/');

        await expect(page.locator('[data-tour="header-help"]')).toBeVisible();
        await page.waitForTimeout(250);
        await page.evaluate(() => {
            window.dispatchEvent(new CustomEvent('lido-guided-tour-launch', { detail: { restart: true } }));
        });

        const overlay = page.getByRole('dialog');
        await expect(page.getByText('Step 1 of 8', { exact: true })).toBeVisible();
        for (let current = 2; current <= 8; current += 1) {
            await overlay.getByRole('button', { name: 'Next' }).click();
            await expect(page.getByText(`Step ${current} of 8`, { exact: true })).toBeVisible({ timeout: 10_000 });
        }
        await expect(overlay.getByRole('button', { name: 'Finish' })).toBeVisible();
    });
});

test.describe('FEAT-061 guided tour tablet browser acceptance', () => {
    test.use({ viewport: { width: 1024, height: 768 }, isMobile: false, hasTouch: false });

    test('keeps the welcome dialog and first step inside a tablet viewport', async ({ page }) => {
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
        expect(welcomeBox.width).toBeLessThanOrEqual(1024);
        await page.getByRole('button', { name: 'Begin tour' }).click();

        const step = page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: 'Navigation' }) });
        await expect(step).toBeVisible();
        const stepBox = await step.boundingBox();
        expect(stepBox).not.toBeNull();
        expect(stepBox.width).toBeLessThanOrEqual(1024);
        await expect(step).toHaveAttribute('aria-describedby', 'lido-guided-tour-description');
    });
});
