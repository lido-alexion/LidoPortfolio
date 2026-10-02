import { test, expect } from '@playwright/test';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const response = { summary: 'Price evidence is available.', technical_price_context: 'Recent prices are supplied.', fundamental_context: 'Fundamental interpretation is unavailable.', positive_signals: 'No additional signal asserted.', risks_watch_items: 'Review incomplete history.', what_to_check_next: 'Check the next financial report.', data_limitations: 'Historical data incomplete.' };
const result = { status: 'ready', fingerprint: 'f'.repeat(64), response, data_as_of: { ohlcv_through: '2026-10-01', fundamentals_period: '2026-06-30', holding_personalized: true } };
const holding = { id: 1, stock_id: 42, stock: { id: 42, symbol: 'TCS', name: 'Tata Consultancy Services' }, quantity: 2, avg_buy_price: 3000, invested_amount: 6000, is_unmanaged: true, summary: { latest_close: 3500, market_value: 7000, invested_amount: 6000, unrealized_profit: 1000, quantity: 2, first_buy_date: '2026-09-01' } };

for (const width of [390, 1440, 2560]) {
    test(`AI-003 stock recovery and responsive presentation at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await installInvestorWorkflowApiMocks(page);
        let calls = 0;
        await page.route('**/api/stocks/42/prices**', route => route.fulfill({ json: { data: [], stock: holding.stock } }));
        await page.route('**/api/holdings', route => route.fulfill({ json: { data: [holding] } }));
        await page.route('**/api/ai/insights/stocks/42', route => {
            calls++;
            expect(route.request().postDataJSON().refresh).toBe(calls > 1);
            return route.fulfill({ json: { data: { ...result, degraded: calls > 1 } } });
        });
        await page.goto('/holdings');
        const button = page.getByRole('button', { name: 'Open AI Insights' }).first();
        await expect(button).toBeVisible();
        expect(calls).toBe(0);
        await button.click();
        const surface = page.getByRole(width >= 1600 ? 'region' : 'dialog', { name: 'AI Insights — TCS', exact: true });
        await expect(surface).toBeVisible();
        await expect(surface.getByText('Price evidence is available.')).toBeVisible();
        await expect(surface.getByText('Personalized with your active portfolio holding')).toBeVisible();
        await expect(surface.getByRole('button', { name: 'Copy AI Prompt' })).toHaveCount(0);
        await surface.getByRole('button', { name: 'Copy insight', exact: true }).click();
        await surface.getByRole('button', { name: 'Refresh insight' }).click();
        await expect(surface.getByText('Fresh AI refresh unavailable; showing the latest cached insight.')).toBeVisible();
        await expect(surface.getByRole('button', { name: 'Copy AI Prompt' })).toBeVisible();
        await expect(surface.getByText('Price evidence is available.')).toBeVisible();
        const box = await surface.boundingBox();
        expect(box.width).toBeLessThanOrEqual(width);
        if (width >= 1600) expect(box.width).toBeCloseTo(width * 0.35, -1);
        else expect(box.width).toBeGreaterThan(width * 0.9);
        await surface.getByRole('link', { name: 'Open stock details' }).click();
        await expect(page).toHaveURL(/\/holdings\/42\/prices/);
    });
}

test('AI-003 selected watchlist stock opens inline and fails safely', async ({ page }) => {
    await installInvestorWorkflowApiMocks(page);
    await page.route('**/api/ai/insights/stocks/42', route => route.fulfill({ json: { data: { status: 'unavailable', response: null, degraded: true } } }));
    await page.goto('/watchlist/TCS');
    await page.getByRole('button', { name: 'Open AI Insights' }).first().click();
    const inline = page.locator('.stox-insight-inline');
    await expect(inline).toBeVisible();
    await expect(inline.getByRole('button', { name: 'Copy AI Prompt' })).toBeVisible();
    await expect(inline.getByRole('button', { name: 'Copy insight', exact: true })).toHaveCount(0);
    await inline.getByRole('button', { name: 'Close AI Insights' }).click();
    await expect(page.getByRole('button', { name: 'Fundamentals', exact: true })).toBeEnabled();
});

test('AI-003 strategy generation is advisory and draft approval is explicit', async ({ page }) => {
    await installInvestorWorkflowApiMocks(page);
    let generations = 0, previews = 0, approvals = 0;
    let stored = null;
    const run = { id: 'ai003-run', profile_id: 1, objective: 'Create reviewed draft', status: 'awaiting_approval', plan_hash: 'a'.repeat(64), approval_expires_at: '2099-01-01T00:00:00Z', preview: [{ consequence: 'Create a Library draft only.', reason: 'Reviewed design', validation: 'passed', side_effect: 'mutation', changes: [{ field: 'name', before: null, after: 'Research design' }] }] };
    await page.route('**/api/ai/insights/strategy', route => {
        const payload = route.request().postDataJSON();
        if (!payload.lookup_only) {
            generations++;
            stored = { ...result, response: { strategy_summary: 'Advisory research design', entry_logic: 'Review momentum signals.', caveats: 'No promised returns.', draft_envelope: {} } };
        }
        return route.fulfill({ json: { data: payload.inputs.maximumPositions === 5 ? stored || { status: 'missing' } : { status: 'missing' } } });
    });
    await page.route('**/api/ai/insights/strategy/draft', route => { previews++; return route.fulfill({ json: { data: run } }); });
    await page.route('**/api/ai/assistant/runs/ai003-run/approve', route => { approvals++; return route.fulfill({ json: { data: { ...run, status: 'completed', steps: [{ tool: 'strategy.create', status: 'verified', affected_object_id: 42 }] } } }); });
    await page.goto('/strategy');
    await page.getByRole('button', { name: 'AI Strategy Designer', exact: true }).click();
    await page.getByRole('button', { name: 'Generate strategy', exact: true }).click();
    await expect(page.getByText('Advisory research design')).toBeVisible();
    expect(generations).toBe(1); expect(previews).toBe(0); expect(approvals).toBe(0);
    await page.getByRole('button', { name: 'Create draft strategy' }).click();
    await expect(page.getByText('Create a Library draft only.')).toBeVisible();
    expect(previews).toBe(1); expect(approvals).toBe(0);
    await page.getByRole('button', { name: 'Approve changes' }).click();
    await expect(page.getByText('strategy create — verified (object 42)')).toBeVisible();
    expect(approvals).toBe(1);
    await page.getByLabel('Maximum Positions').fill('6');
    await expect(page.getByText('Advisory research design')).toHaveCount(0);
    await page.getByLabel('Maximum Positions').fill('5');
    await expect(page.getByText('Advisory research design')).toBeVisible();
    expect(generations).toBe(1);
});
