import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { expect, it, vi, beforeEach } from 'vitest';
import AssistantRun from '../../../resources/js/src/components/AssistantRun';
import api from '../../../resources/js/src/api';
vi.mock('../../../resources/js/src/api', () => ({ default: { post: vi.fn() } }));
const run = { id: 'r', profile_id: 1, objective: 'Delete a watchlist', status: 'awaiting_approval', plan_hash: 'a'.repeat(64), approval_expires_at: '2099-01-01', preview: [{ tool: 'watchlist.delete', side_effect: 'destructive', consequence: 'Delete selected watchlist', reason: 'Requested removal', affected_object_id: 42, validation: 'passed' }] };
beforeEach(() => vi.clearAllMocks());
it('requires explicit destructive confirmation and submits the exact hash', async () => {
    const onChange = vi.fn();
    api.post.mockResolvedValue({ data: { data: { ...run, status: 'completed' } } });
    render(<AssistantRun run={run} onChange={onChange} onRetry={vi.fn()} />);
    expect(screen.getByText('Approve changes')).toBeDisabled();
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(screen.getByText('Approve changes'));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/ai/assistant/runs/r/approve', { plan_hash: run.plan_hash, destructive_confirmation: true }));
    expect(onChange).toHaveBeenCalled();
});
it('expired and stale approvals require a fresh plan', () => {
    const onRetry = vi.fn();
    render(<AssistantRun run={{ ...run, approval_expires_at: '2000-01-01' }} onChange={vi.fn()} onRetry={onRetry} />);
    expect(screen.queryByText('Approve changes')).not.toBeInTheDocument();
    fireEvent.click(screen.getByText('Build a fresh plan'));
    expect(onRetry).toHaveBeenCalled();
});
it('shows exact partial failure and unattempted steps', () => {
    render(<AssistantRun run={{ ...run, status: 'partial_failure', steps: [{ tool: 'watchlist.create', status: 'verified' }, { tool: 'watchlist.rename', status: 'failed' }, { tool: 'watchlist.delete', status: 'not_attempted' }] }} onChange={vi.fn()} onRetry={vi.fn()} />);
    expect(screen.getByText('watchlist create — verified')).toBeInTheDocument();
    expect(screen.getByText('watchlist delete — not attempted')).toBeInTheDocument();
    expect(screen.queryByText('Approve changes')).not.toBeInTheDocument();
});
