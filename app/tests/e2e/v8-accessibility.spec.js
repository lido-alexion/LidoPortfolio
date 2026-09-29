import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

async function expectNoAccessibilityViolations(page, include, { allViolations = false } = {}) {
    const builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']);
    if (await page.locator(include).count() > 0) {
        builder.include(include);
    }
    const results = await builder.analyze();

    const violations = allViolations
        ? results.violations
        : results.violations.filter((violation) => violation.impact === 'critical');
    expect(violations).toEqual([]);
}

test.describe('V8 accessibility acceptance', () => {
    test('FEAT-061 guided-tour dialogs have no WCAG 2A/2AA violations', async ({ page }) => {
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
        await expectNoAccessibilityViolations(page, '.lido-guided-tour-modal', { allViolations: true });

        await welcome.getByRole('button', { name: 'Begin tour' }).click();
        const step = page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: 'Navigation' }) });
        await expect(step).toBeVisible();
        await expectNoAccessibilityViolations(page, '.lido-guided-tour-panel', { allViolations: true });
    });

    test('FEAT-054 fundamentals page has no WCAG 2A/2AA violations', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/watchlist/TCS');
        await page.getByRole('button', { name: 'Fundamentals' }).click();
        await expect(page.getByRole('button', { name: 'Fundamentals', exact: true })).toBeVisible();
        await expectNoAccessibilityViolations(page, 'main', { allViolations: true });
    });

    test('FEAT-064 screener editor has no WCAG 2A/2AA violations', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/screeners/new');
        await expect(page.getByRole('heading', { name: 'New screener' })).toBeVisible();
        await expectNoAccessibilityViolations(page, 'main', { allViolations: true });
    });

    test('FEAT-055 request-account form has no WCAG 2A/2AA violations', async ({ page }) => {
        await page.goto('/request-account');
        await expect(page.getByRole('heading', { name: 'Request an account' })).toBeVisible();
        await expectNoAccessibilityViolations(page, 'body', { allViolations: true });
    });

    test('FEAT-062 fundamental insights page has no WCAG 2A/2AA violations', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/fundamentals/insights/TCS');
        await expect(page.getByRole('heading', { name: 'Fundamental insights' }).last()).toBeVisible();
        await expect(page.getByText('StoX deterministic evidence')).toBeVisible();
        await expectNoAccessibilityViolations(page, 'main', { allViolations: true });
    });
});
