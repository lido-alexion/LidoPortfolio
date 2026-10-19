import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import AssistantDrawer from '../../../resources/js/src/components/AssistantDrawer';
import api from '../../../resources/js/src/api';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

const expiredRun = {
    id: '11111111-1111-4111-8111-111111111111',
    profile_id: 1,
    objective: 'Create the Momentum watchlist',
    status: 'expired',
    plan_hash: '1'.repeat(64),
    approval_expires_at: '2000-01-01T00:00:00Z',
    plan: [{ tool: 'watchlist.create', arguments: { name: 'Momentum' }, reason: 'Create the requested list' }],
    preview: [{ tool: 'watchlist.create', arguments: { name: 'Momentum' }, side_effect: 'mutation', consequence: 'Create watchlist Momentum', reason: 'Prepare the requested list', validation: 'passed', changes: [{ field: 'name', before: null, after: 'Momentum' }] }],
    trace: [], steps: [], answer: null,
};

const staleRun = {
    id: '22222222-2222-4222-8222-222222222222',
    profile_id: 1,
    objective: 'Rename the Core watchlist',
    status: 'stale',
    plan_hash: '2'.repeat(64),
    approval_expires_at: '2099-01-01T00:00:00Z',
    plan: [{ tool: 'watchlist.rename', arguments: { id: 61, name: 'Core Plus' }, reason: 'Rename the selected list' }],
    preview: [{ tool: 'watchlist.rename', arguments: { id: 61, name: 'Core Plus' }, side_effect: 'mutation', consequence: 'Rename watchlist Core to Core Plus', reason: 'Update the selected list name', validation: 'passed', affected_object_id: 61, changes: [{ field: 'name', before: 'Core', after: 'Core Plus' }] }],
    trace: [], steps: [], answer: null,
};

const freshExpiredPlan = {
    ...expiredRun,
    id: '33333333-3333-4333-8333-333333333333',
    status: 'awaiting_approval',
    plan_hash: '3'.repeat(64),
    approval_expires_at: '2099-01-01T00:00:00Z',
    plan: [{ tool: 'watchlist.create', arguments: { name: 'Momentum Research' }, reason: 'Use the current reviewed name' }],
    preview: [{ tool: 'watchlist.create', arguments: { name: 'Momentum Research' }, side_effect: 'mutation', consequence: 'Create revised Momentum Research list', reason: 'Use the current reviewed name', validation: 'passed', changes: [{ field: 'name', before: null, after: 'Momentum Research' }] }],
};

const freshStalePlan = {
    ...staleRun,
    id: '44444444-4444-4444-8444-444444444444',
    status: 'awaiting_approval',
    plan_hash: '4'.repeat(64),
    approval_expires_at: '2099-01-01T00:00:00Z',
    plan: [{ tool: 'watchlist.rename', arguments: { id: 62, name: 'Core Plus' }, reason: 'Use the current selected list' }],
    preview: [{ tool: 'watchlist.rename', arguments: { id: 62, name: 'Core Plus' }, side_effect: 'mutation', consequence: 'Apply Core Plus name to current list', reason: 'Rebuild against the current selected list', validation: 'passed', affected_object_id: 62, changes: [{ field: 'name', before: 'Core', after: 'Core Plus' }] }],
};

let dialogDescriptors;

beforeEach(() => {
    dialogDescriptors = {
        showModal: Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'showModal'),
        close: Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'close'),
    };
    Object.defineProperty(HTMLDialogElement.prototype, 'showModal', { configurable: true, value() { this.open = true; } });
    Object.defineProperty(HTMLDialogElement.prototype, 'close', { configurable: true, value() { this.open = false; } });
    api.get.mockResolvedValue({ data: { data: [expiredRun, staleRun] } });
    api.post.mockImplementation(async (url, body) => {
        if (url === '/ai/assistant/runs') {
            return { data: { data: body.retry_run_id === expiredRun.id ? freshExpiredPlan : freshStalePlan } };
        }
        const fresh = url.includes(freshExpiredPlan.id) ? freshExpiredPlan : freshStalePlan;
        return { data: { data: { ...fresh, status: 'completed', answer: 'The reviewed changes were completed and verified.', steps: [{ tool: fresh.plan[0].tool, status: 'verified', affected_object_id: fresh.preview[0].affected_object_id ?? 77 }] } } };
    });
});

afterEach(() => {
    cleanup();
    for (const [name, descriptor] of Object.entries(dialogDescriptors)) {
        if (descriptor) Object.defineProperty(HTMLDialogElement.prototype, name, descriptor);
        else delete HTMLDialogElement.prototype[name];
    }
    vi.clearAllMocks();
});

function runCardContaining(text) {
    const cards = screen.getAllByRole('article', { name: 'Assistant run' });
    const matches = cards.filter(card => card.textContent.includes(text));
    expect(matches).toHaveLength(1);
    return within(matches[0]);
}

it('rebuilds expired and stale history as distinct fresh previews before explicit approval', async () => {
    render(<MemoryRouter><AssistantDrawer /></MemoryRouter>);
    fireEvent.click(screen.getByRole('button', { name: 'Open StoX assistant' }));
    const drawer = screen.getByRole('dialog', { name: 'StoX assistant' });
    fireEvent.click(within(drawer).getByRole('button', { name: 'Run history' }));

    expect(await within(drawer).findByText('Approval expired', { exact: true })).toBeInTheDocument();
    expect(within(drawer).getByText('State changed — a fresh plan is required', { exact: true })).toBeInTheDocument();
    expect(api.get).toHaveBeenCalledWith('/ai/assistant/runs');

    for (const phase of [
        { old: expiredRun, fresh: freshExpiredPlan },
        { old: staleRun, fresh: freshStalePlan },
    ]) {
        const oldCard = runCardContaining(phase.old.preview[0].consequence);
        expect(oldCard.queryByRole('button', { name: 'Approve changes' })).not.toBeInTheDocument();
        expect(oldCard.queryByRole('button', { name: 'Reject plan' })).not.toBeInTheDocument();
        fireEvent.click(oldCard.getByText(/Review field changes/));
        expect(oldCard.getByText(`${phase.old.preview[0].changes[0].before ?? 'Unset'} → ${phase.old.preview[0].changes[0].after}`, { exact: true })).toBeInTheDocument();
        fireEvent.click(oldCard.getByRole('button', { name: 'Build a fresh plan' }));

        const freshCard = await screen.findByText(phase.fresh.preview[0].consequence, { exact: true });
        expect(freshCard).toBeInTheDocument();
        const freshRunCard = runCardContaining(phase.fresh.preview[0].consequence);
        expect(freshRunCard.getByText('Approval required', { exact: true })).toBeInTheDocument();
        fireEvent.click(freshRunCard.getByText(/Review field changes/));
        expect(freshRunCard.getByText(`${phase.fresh.preview[0].changes[0].before ?? 'Unset'} → ${phase.fresh.preview[0].changes[0].after}`, { exact: true })).toBeInTheDocument();
        expect(oldCard.getByText(phase.old.preview[0].consequence, { exact: true })).toBeInTheDocument();
        expect(oldCard.queryByRole('button', { name: 'Approve changes' })).not.toBeInTheDocument();

        fireEvent.click(freshRunCard.getByRole('button', { name: 'Approve changes' }));
        expect(await within(drawer).findByText('The reviewed changes were completed and verified.')).toBeInTheDocument();
        expect(freshRunCard.getByText(/— verified/)).toBeInTheDocument();
    }

    expect(api.post.mock.calls).toEqual([
        ['/ai/assistant/runs', { objective: expiredRun.objective, retry_run_id: expiredRun.id }, { signal: expect.any(AbortSignal) }],
        [`/ai/assistant/runs/${freshExpiredPlan.id}/approve`, { plan_hash: freshExpiredPlan.plan_hash }],
        ['/ai/assistant/runs', { objective: staleRun.objective, retry_run_id: staleRun.id }, { signal: expect.any(AbortSignal) }],
        [`/ai/assistant/runs/${freshStalePlan.id}/approve`, { plan_hash: freshStalePlan.plan_hash }],
    ]);
});
