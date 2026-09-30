import React from 'react';
import { beforeEach, afterEach, describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react';
import MlAcceptancePanel from '../../../resources/js/src/pages/MlAcceptancePanel';
import api from '../../../resources/js/src/api';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));
const report = { campaign_id: 'campaign-1', campaign_status: 'ready', readiness: { ready: false, reason: 'acceptance_required' }, runtime: { queue_configuration_ready: true } };
beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockImplementation(async (url) => ({ data: { data: url.endsWith('/sources') ? { data: [] } : report } }));
    api.post.mockResolvedValue({ data: { data: {} } });
});
afterEach(cleanup);
describe('ML acceptance controls', () => {
    it('opening and refreshing the panel only reads evidence', async () => {
        render(<MlAcceptancePanel />);
        await screen.findByText(/Campaign: ready/);
        expect(api.post).not.toHaveBeenCalled();
        expect(api.put).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh acceptance report' }));
        await waitFor(() => expect(api.get).toHaveBeenCalledTimes(4));
        expect(api.post).not.toHaveBeenCalled();
    });
    it('training requires an explicit click after successful preflight', async () => {
        render(<MlAcceptancePanel />);
        const start = await screen.findByRole('button', { name: 'Start 1m/3m/6m training' });
        await waitFor(() => expect(start).not.toBeDisabled());
        expect(api.post).not.toHaveBeenCalled();
        fireEvent.click(start);
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/admin/ml/acceptance/campaigns/campaign-1/start'));
        expect(api.post).toHaveBeenCalledTimes(1);
    });
    it('loads older sources and resumes queued validation explicitly', async () => {
        api.get.mockImplementation(async (url) => ({ data: { data: url.includes('/sources') ? {
            current_page: url.includes('?page=2') ? 2 : 1, last_page: 2,
            data: url.includes('?page=2') ? [{ id: 'older-source', status: 'queued', manifest: { filename: 'older.csv', date: '2026-09-01' } }] : [],
        } : report } }));
        render(<MlAcceptancePanel />);
        const next = await screen.findByRole('button', { name: 'Next sources' });
        await waitFor(() => expect(next).not.toBeDisabled());
        fireEvent.click(next);
        await screen.findByText('older.csv');
        expect(api.get).toHaveBeenCalledWith('/v1/admin/ml/acceptance/sources?page=2');
        expect(api.post).not.toHaveBeenCalled();
        const resume = screen.getByRole('button', { name: 'Resume validation' });
        await waitFor(() => expect(resume).not.toBeDisabled());
        fireEvent.click(resume);
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/admin/ml/acceptance/sources/older-source/resume'));
        expect(await screen.findByText('Source page 2 of 2')).toBeInTheDocument();
    });

});
