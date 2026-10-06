import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('../../../resources/js/src/api', () => ({ default: { get, post } }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));

import ForwardDataAdminPage from '../../../resources/js/src/pages/ForwardDataAdminPage';

const workPage = (current, row) => ({
    data: { data: { data: [row], current_page: current, last_page: 2, total: 2 } },
});

describe('ForwardDataAdminPage', () => {
    beforeEach(() => {
        get.mockReset();
        post.mockReset();
        get.mockImplementation((path, options) => path.endsWith('/health')
            ? Promise.resolve({ data: { data: { paused: false, health: { datasets: {} } } } })
            : Promise.resolve(workPage(options.params.page, {
                id: options.params.page,
                dataset_key: 'official_nse_membership',
                session_date: `2026-10-0${options.params.page}`,
                state: 'retry_wait',
                attempts: 1,
                last_error_code: `reason_${options.params.page}`,
            })));
        post.mockResolvedValue({ data: { data: { updated: 1 } } });
    });

    it('navigates paginated work and retries only failures on the visible page', async () => {
        render(<MemoryRouter><ForwardDataAdminPage /></MemoryRouter>);

        expect(await screen.findByText('reason_1')).toBeTruthy();
        expect(get).toHaveBeenCalledWith('/forward-data/work', { params: { per_page: 50, page: 1 } });

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByText('reason_2')).toBeTruthy();
        expect(get).toHaveBeenLastCalledWith('/forward-data/work', { params: { per_page: 50, page: 2 } });

        fireEvent.click(screen.getByRole('button', { name: 'Retry visible forward-data failures' }));
        await waitFor(() => expect(post).toHaveBeenCalledWith('/forward-data/retry', { ids: [2] }));
    });

    it('locks page controls while the selected page is loading', async () => {
        let resolvePageTwo;
        get.mockImplementation((path, options) => path.endsWith('/health')
            ? Promise.resolve({ data: { data: { paused: false, health: { datasets: {} } } } })
            : options.params.page === 2
                ? new Promise((resolve) => { resolvePageTwo = resolve; })
                : Promise.resolve(workPage(1, { id: 1, dataset_key: 'membership', session_date: '2026-10-01', state: 'pending', attempts: 0, last_error_code: 'reason_1' })));
        render(<MemoryRouter><ForwardDataAdminPage /></MemoryRouter>);

        expect(await screen.findByText('reason_1')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        await waitFor(() => expect(get).toHaveBeenLastCalledWith('/forward-data/work', { params: { per_page: 50, page: 2 } }));
        expect(screen.getByRole('button', { name: 'Next' }).disabled).toBe(true);
        expect(screen.getByRole('button', { name: 'Refresh forward-data health' }).disabled).toBe(true);

        resolvePageTwo(workPage(2, { id: 2, dataset_key: 'membership', session_date: '2026-10-02', state: 'pending', attempts: 0, last_error_code: 'reason_2' }));
        expect(await screen.findByText('reason_2')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Previous' }).disabled).toBe(false);
    });

    it('keeps pagination usable after a refresh error and successful retry', async () => {
        get.mockImplementationOnce(() => Promise.reject(new Error('temporary failure')));
        const view = render(<MemoryRouter><ForwardDataAdminPage /></MemoryRouter>);

        expect(await screen.findByRole('alert')).toBeTruthy();
        get.mockImplementation((path, options) => path.endsWith('/health')
            ? Promise.resolve({ data: { data: { paused: false, health: { datasets: {} } } } })
            : Promise.resolve(workPage(options.params.page, { id: 1, dataset_key: 'membership', session_date: '2026-10-01', state: 'pending', attempts: 0 })));
        fireEvent.click(screen.getByRole('button', { name: 'Refresh forward-data health' }));
        await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
        expect(screen.getByRole('button', { name: 'Next' }).disabled).toBe(false);
        view.unmount();
    });
});
