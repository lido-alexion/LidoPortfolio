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

test.describe('FEAT-062 fundamental insights mobile acceptance', () => {
    test.use({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });

    test('keeps deterministic evidence readable on a narrow viewport', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/fundamentals/insights/TCS');

        const card = page.locator('.card').filter({ hasText: 'StoX deterministic evidence' });
        await expect(card).toBeVisible();
        const box = await card.boundingBox();
        expect(box).not.toBeNull();
        expect(box.width).toBeLessThanOrEqual(390);
        await expect(card.getByText('Cash generation is aligned with reported earnings')).toBeVisible();
        await expect(card.getByText('Review the latest annual report cash-flow notes.')).toBeVisible();
    });
});

test.describe('FEAT-054 historical fundamentals browser acceptance', () => {
    test('renders provenance, history and user-scoped Basic/Advanced preference', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/watchlist/TCS');

        await expect(page.getByText('TCS — Tata Consultancy Services', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Fundamentals' }).click();
        await expect(page.getByRole('heading', { name: 'P/E (TTM) trend' })).toBeVisible();
        await expect(page.getByText('Official NSE', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Basic financial data')).toBeVisible();

        const advancedToggle = page.getByRole('button', { name: /Show advanced financial data/ });
        await expect(advancedToggle).toHaveAttribute('aria-expanded', 'false');
        await advancedToggle.click();
        await expect(page.getByRole('heading', { name: 'Advanced financial data' })).toBeVisible();
        await expect(page.getByRole('img', { name: /P\/E \(TTM\)/ })).toBeVisible();
        await expect(page.evaluate(() => window.localStorage.getItem('lido.fundamentals.advancedExpanded.user.1'))).resolves.toBe('1');

        await page.reload();
        await page.getByRole('button', { name: 'Fundamentals' }).click();
        await expect(page.getByRole('heading', { name: 'Advanced financial data' })).toBeVisible();
        await expect(page.getByRole('button', { name: /Hide advanced financial data/ })).toHaveAttribute('aria-expanded', 'true');
    });
});

test.describe('FEAT-054 historical fundamentals mobile acceptance', () => {
    test.use({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });

    test('keeps the fundamentals summary and Advanced tables usable on a narrow viewport', async ({ page }) => {
        await installInvestorWorkflowApiMocks(page);
        await page.goto('/watchlist/TCS');
        await page.getByRole('button', { name: 'Fundamentals' }).click();

        const panel = page.getByRole('button', { name: 'Fundamentals' }).locator('..').locator('..');
        await expect(page.getByRole('heading', { name: 'P/E (TTM) trend' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Basic financial data' })).toBeVisible();
        const toggle = page.getByRole('button', { name: /Show advanced financial data/ });
        await expect(toggle).toBeVisible();
        await toggle.click();
        await expect(page.getByRole('heading', { name: 'Advanced financial data' })).toBeVisible();
        const box = await panel.boundingBox();
        expect(box).not.toBeNull();
        expect(box.width).toBeLessThanOrEqual(390);
    });
});
