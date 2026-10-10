import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

for (const width of [390, 1440]) {
    test(`AI-05 and AI-06 governed approval and history at ${width}px`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AI-05');
        journeyId(testInfo, 'AI-06');
        await seedDeterministicJourney(page, `assistant-agent-ai05-ai06-${width}`);
        await page.setViewportSize({ width, height: 844 });
        await installInvestorWorkflowApiMocks(page);
        let approved = 0;
        const run = { id: 'run-1', profile_id: 1, objective: 'Create a watchlist named Research', status: 'awaiting_approval', plan_hash: 'a'.repeat(64), approval_expires_at: '2099-01-01T00:00:00Z', trace: [{ tool: 'watchlist.list', status: 'available' }], preview: [{ tool: 'watchlist.create', arguments: { name: 'Research' }, side_effect: 'mutation', consequence: 'Create watchlist Research', reason: 'Requested research list', validation: 'passed', changes: [{ field: 'name', before: null, after: 'Research' }] }] };
        await page.route('**/api/ai/assistant/runs**', async route => {
            const request = route.request();
            if (request.url().endsWith('/approve')) {
                expect(request.postDataJSON()).toEqual({ plan_hash: run.plan_hash });
                approved++;
                run.status = 'completed'; run.answer = 'The approved changes were completed and verified.';
                run.steps = [{ tool: 'watchlist.create', status: 'verified', affected_object_id: 7 }];
            }
            await route.fulfill({ json: { success: true, data: request.method() === 'GET' ? [run] : run } });
        });
        await page.goto('/');
        await page.getByRole('button', { name: 'Open StoX assistant' }).click();
        const drawer = page.getByRole('dialog', { name: 'StoX assistant' });
        await drawer.getByLabel('Assistant mode').selectOption('account');
        await drawer.getByLabel('Ask about StoX').fill(run.objective);
        await drawer.getByRole('button', { name: 'Ask', exact: true }).click();
        await expect(drawer.getByText('Approval required', { exact: true })).toBeVisible();
        expect(approved).toBe(0);
        await drawer.getByText('Investigation trace').click();
        await expect(drawer.getByText('watchlist list — available')).toBeVisible();
        await drawer.getByRole('button', { name: 'Approve changes' }).click();
        await expect(drawer.getByText('The approved changes were completed and verified.')).toBeVisible();
        expect(approved).toBe(1);
        await drawer.getByRole('button', { name: 'Clear conversation' }).click();
        await expect(drawer.getByText(run.objective)).toHaveCount(0);
        await drawer.getByRole('button', { name: 'Run history' }).click();
        await expect(drawer.getByText('watchlist create — verified (object 7)')).toBeVisible();
        expect((await drawer.boundingBox()).width).toBeLessThanOrEqual(width);
        await expect(drawer.getByText(/Broker trading is unavailable/)).toBeVisible();
    });
}


test('AI-04 answers account questions with traceable read-only evidence', async ({ page }, testInfo) => {
    journeyId(testInfo, 'AI-04');
    await seedDeterministicJourney(page, 'assistant-agent-ai04-investigation');
    await page.setViewportSize({ width: 1440, height: 900 });
    await installInvestorWorkflowApiMocks(page);
    const run = {
        id: 'account-investigation-1',
        profile_id: 1,
        objective: 'How concentrated are my holdings?',
        status: 'completed',
        answer: 'Your largest holding is 18% of the portfolio. Prices for two holdings are unavailable.',
        trace: [
            { tool: 'portfolio.holdings', status: 'available', result_summary: '8 holdings' },
            { tool: 'portfolio.concentration', status: 'available', result_summary: 'Largest holding: 18%' },
            { tool: 'market.prices', status: 'partial', result_summary: '2 prices unavailable' },
        ],
        preview: [],
    };
    let orderRequests = 0;
    await page.route('**/api/ai/assistant/runs**', async (route) => {
        const request = route.request();
        if (request.method() === 'POST') {
            expect(request.postDataJSON()).toEqual({ objective: run.objective });
        }
        await route.fulfill({ json: { success: true, data: request.method() === 'GET' ? [run] : run } });
    });
    page.on('request', (request) => {
        if (/\/api\/v1\/(execution\/submit-selected|orders?)(\/|$)/.test(new URL(request.url()).pathname)) orderRequests++;
    });

    await page.goto('/');
    await page.getByRole('button', { name: 'Open StoX assistant' }).click();
    const drawer = page.getByRole('dialog', { name: 'StoX assistant' });
    await drawer.getByLabel('Assistant mode').selectOption('account');
    await drawer.getByLabel('Ask about StoX').fill(run.objective);
    await drawer.getByRole('button', { name: 'Ask', exact: true }).click();
    await expect(drawer.getByText(/Your largest holding is 18%/)).toBeVisible();
    await drawer.getByText('Investigation trace').click();
    await expect(drawer.getByText(/portfolio holdings — available/)).toBeVisible();
    await expect(drawer.getByText(/market prices — partial/)).toBeVisible();
    await expect(drawer.getByRole('button', { name: 'Approve changes' })).toHaveCount(0);
    expect(orderRequests).toBe(0);
});
