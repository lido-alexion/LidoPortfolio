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

    it('uploads from zero when a new source response omits the offset', async () => {
        const contents = new Uint8Array([1, 2, 3]);
        const file = new File([contents], 'cm30SEP2026bhav.csv');
        Object.defineProperty(file, 'arrayBuffer', { value: async () => contents.buffer });
        vi.stubGlobal('crypto', { subtle: { digest: vi.fn(async () => new Uint8Array(32).buffer) } });
        api.post.mockImplementation(async (url) => url.endsWith('/sources')
            ? { data: { data: { id: 'new-source', manifest: { bytes: 3 } } } }
            : { data: { data: { status: 'queued' } } });
        render(<MlAcceptancePanel />);
        await screen.findByText(/Campaign: ready/);
        fireEvent.change(screen.getByLabelText('Official CSV or ZIP'), { target: { files: [file] } });
        fireEvent.click(screen.getByRole('button', { name: 'Upload and queue validation' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/admin/ml/acceptance/sources/new-source/finalize'));
        expect(api.put).toHaveBeenCalledWith('/v1/admin/ml/acceptance/sources/new-source/chunks', { offset: 0, chunk: btoa('\u0001\u0002\u0003') });
        vi.unstubAllGlobals();
    });

    it('resumes an existing partial upload from its validated server offset', async () => {
        const contents = new Uint8Array(1024 * 1024 + 3);
        const file = new File([contents], 'cm30SEP2026bhav.csv');
        Object.defineProperty(file, 'arrayBuffer', { value: async () => contents.buffer });
        vi.stubGlobal('crypto', { subtle: { digest: vi.fn(async () => new Uint8Array(32).buffer) } });
        const partial = { id: 'partial-source', status: 'uploading', received: 1024 * 1024,
            manifest: { filename: file.name, bytes: file.size, sha256: '0'.repeat(64) } };
        api.get.mockImplementation(async (url) => ({ data: { data: url.endsWith('/sources')
            ? { data: [partial] }
            : url.endsWith('/sources/partial-source') ? partial : report } }));
        api.post.mockResolvedValue({ data: { data: { status: 'queued' } } });
        render(<MlAcceptancePanel />);
        await screen.findByText(/Campaign: ready/);
        fireEvent.change(screen.getByLabelText('Official CSV or ZIP'), { target: { files: [file] } });
        fireEvent.change(screen.getByLabelText('Resume upload'), { target: { value: 'partial-source' } });
        fireEvent.click(screen.getByRole('button', { name: 'Upload and queue validation' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/admin/ml/acceptance/sources/partial-source/finalize'));
        expect(api.put).toHaveBeenCalledWith('/v1/admin/ml/acceptance/sources/partial-source/chunks', expect.objectContaining({ offset: 1024 * 1024 }));
        vi.unstubAllGlobals();
    });

    it('refuses an invalid existing offset without uploading or finalizing', async () => {
        const contents = new Uint8Array([1, 2, 3]);
        const file = new File([contents], 'cm30SEP2026bhav.csv');
        Object.defineProperty(file, 'arrayBuffer', { value: async () => contents.buffer });
        vi.stubGlobal('crypto', { subtle: { digest: vi.fn(async () => new Uint8Array(32).buffer) } });
        const invalid = { id: 'bad-source', status: 'uploading', received: -1,
            manifest: { filename: file.name, bytes: file.size, sha256: '0'.repeat(64) } };
        api.get.mockImplementation(async (url) => ({ data: { data: url.endsWith('/sources')
            ? { data: [invalid] }
            : url.endsWith('/sources/bad-source') ? invalid : report } }));
        render(<MlAcceptancePanel />);
        await screen.findByText(/Campaign: ready/);
        fireEvent.change(screen.getByLabelText('Official CSV or ZIP'), { target: { files: [file] } });
        fireEvent.change(screen.getByLabelText('Resume upload'), { target: { value: 'bad-source' } });
        fireEvent.click(screen.getByRole('button', { name: 'Upload and queue validation' }));
        expect(await screen.findByText(/Could not read a valid current upload offset/)).toBeInTheDocument();
        expect(api.put).not.toHaveBeenCalled();
        expect(api.post).not.toHaveBeenCalled();
        vi.unstubAllGlobals();
    });

});
