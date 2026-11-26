import { test, expect } from '@playwright/test';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { TEST_PORTFOLIO, TEST_USER } from '../js/tos/fixtures/tosApi.js';

test('two investor tabs keep independent portfolio selection and context headers', async ({ context }) => {
    const portfolios = [
        { ...TEST_PORTFOLIO, id: 81001, name: 'Disposable Alpha' },
        { ...TEST_PORTFOLIO, id: 81002, name: 'Disposable Beta', is_default: false },
    ];
    const observed = [[], []];
    const pages = [await context.newPage(), await context.newPage()];

    for (const [index, page] of pages.entries()) {
        await page.addInitScript(({ portfolioId }) => {
            sessionStorage.setItem('portfolio_active_id', String(portfolioId));
            sessionStorage.setItem('stox_auth_session_context', 'investor');
        }, { portfolioId: portfolios[index].id });
        await installInvestorWorkflowApiMocks(page, { user: { ...TEST_USER, id: 81000 }, portfolios });
        page.on('request', (request) => {
            if (request.url().includes('/api/holdings')) {
                observed[index].push({
                    context: request.headers()['x-stox-auth-context'],
                    user: request.headers()['x-stox-auth-user'],
                    profile: request.headers()['x-profile-id'],
                });
            }
        });
        await page.goto('/holdings');
        await expect(page.getByRole('heading', { name: 'Portfolio' })).toBeVisible();
    }

    await expect.poll(() => observed[0].length).toBeGreaterThan(0);
    await expect.poll(() => observed[1].length).toBeGreaterThan(0);
    expect(observed[0].every((request) => request.context === 'investor'
        && request.user === '81000' && request.profile === '81001')).toBe(true);
    expect(observed[1].every((request) => request.context === 'investor'
        && request.user === '81000' && request.profile === '81002')).toBe(true);
});
