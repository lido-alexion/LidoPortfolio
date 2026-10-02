import { test, expect } from '@playwright/test';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

for (const width of [390, 1440]) {
    test(`AI-002 governed approval and history at ${width}px`, async ({ page }) => {
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
