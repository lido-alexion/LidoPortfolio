import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

async function expectNoCriticalViolations(page, include) {
    const builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']);
    if (await page.locator(include).count() > 0) {
        builder.include(include);
    }
    const results = await builder.analyze();

    expect(results.violations.filter((violation) => violation.impact === 'critical'))
        .toEqual([]);
}

test.describe('V8 accessibility acceptance', () => {
    test('FEAT-061 guided-tour dialog has no critical accessibility violations', async ({ page }) => {
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
        await expectNoCriticalViolations(page, '.lido-guided-tour-modal');

        await welcome.getByRole('button', { name: 'Begin tour' }).click();
        const step = page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: 'Navigation' }) });
        await expect(step).toBeVisible();
        await expectNoCriticalViolations(page, '.lido-guided-tour-panel');
    });

    test('FEAT-054 fundamentals page has no critical accessibility violations', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/watchlist/TCS');
        await page.getByRole('button', { name: 'Fundamentals' }).click();
        await expect(page.getByText('Fundamentals')).toBeVisible();
        await expectNoCriticalViolations(page, 'main');
    });

    test('FEAT-064 screener editor has no critical accessibility violations', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/screeners/new');
        await expect(page.getByRole('heading', { name: 'New screener' })).toBeVisible();
        await expectNoCriticalViolations(page, 'main');
    });

    test('FEAT-055 request-account form has no critical accessibility violations', async ({ page }) => {
        await page.goto('/request-account');
        await expect(page.getByRole('heading', { name: 'Request an account' })).toBeVisible();
        await expectNoCriticalViolations(page, 'body');
    });
});
