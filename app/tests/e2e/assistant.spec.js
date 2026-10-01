import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import AxeBuilder from '@axe-core/playwright';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

for (const width of [390, 1440]) {
    test(`AI-001 documentation assistant at ${width}px`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AI-01');
        journeyId(testInfo, 'AI-02');
        await seedDeterministicJourney(page, 'ai-001-assistant');
        await page.setViewportSize({ width, height: 844 });
        await installInvestorWorkflowApiMocks(page);
        await page.route('**/api/ai/assistant/stream', route => route.fulfill({ contentType: 'text/event-stream', body: 'event: message.delta\ndata: {"text":"Open Screeners to create a screener."}\n\nevent: message.completed\ndata: {"request_id":"123","grounding":"grounded","provenance":[{"source_id":"scr","title":"Screeners","section":"Create","snippet":"Open Screeners to create a screener.","url":"/docs/screeners.html"}]}\n\n' }));
        await page.goto('/');
        await page.getByRole('button', { name: 'Open StoX assistant' }).click();
        const drawer = page.getByRole('dialog', { name: 'StoX assistant' });
        await expect(drawer).toBeVisible();
        expect((await drawer.boundingBox()).width).toBeLessThanOrEqual(width);
        await drawer.getByLabel('Ask about StoX').fill('How do I create a screener?');
        await drawer.getByRole('button', { name: 'Ask', exact: true }).click();
        await expect(drawer.getByText('Grounded', { exact: true })).toBeVisible();
        await drawer.getByText('Sources used (1)').click();
        await expect(drawer.getByRole('link', { name: 'Screeners — Create' })).toHaveAttribute('href', /docs\/screeners.html/);
        await expect(drawer.getByRole('button', { name: 'Copy response' })).toBeVisible();
        const results = await new AxeBuilder({ page }).include('dialog').analyze();
        expect(results.violations).toEqual([]);
        await drawer.getByRole('button', { name: 'Clear conversation' }).click();
        await expect(drawer.getByText('Grounded', { exact: true })).toHaveCount(0);
        await page.keyboard.press('Escape');
        await expect(drawer).not.toBeVisible();
        await expect(page.getByRole('button', { name: 'Open StoX assistant' })).toBeFocused();
    });
}

test('AI-03 unavailable AI preserves deterministic help', async ({ page }, testInfo) => {
    journeyId(testInfo, 'AI-03');
    await seedDeterministicJourney(page, 'ai-001-unavailable');
    await installInvestorWorkflowApiMocks(page);
    await page.route('**/api/ai/assistant/stream', route => route.fulfill({ status: 503, body: '' }));
    await page.goto('/');
    await page.getByRole('button', { name: 'Open StoX assistant' }).click();
    const drawer = page.getByRole('dialog', { name: 'StoX assistant' });
    await drawer.getByLabel('Ask about StoX').fill('Create screener');
    await drawer.getByRole('button', { name: 'Ask', exact: true }).click();
    await expect(drawer.getByText('Insufficient documentation')).toBeVisible();
    await expect(drawer.getByRole('navigation', { name: 'How do I? deterministic help' })).toBeVisible();
    await drawer.getByRole('link', { name: 'Browse documentation' }).click();
    await expect(drawer).not.toBeVisible();
});
