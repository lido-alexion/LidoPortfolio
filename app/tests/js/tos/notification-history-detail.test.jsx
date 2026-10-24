import React from 'react';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { Link, MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), refresh: vi.fn(), toast: vi.fn() }));
vi.mock('../../../resources/js/src/api', () => ({ default: { get: mocks.get, post: mocks.post } }));
vi.mock('../../../resources/js/src/context/NotificationContext', () => ({ useNotifications: () => ({ refresh: mocks.refresh }) }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: mocks.toast }));

import NotificationHistoryPage from '../../../resources/js/src/pages/NotificationHistoryPage.jsx';

const notification = (id) => ({
    id, title: `Notification ${id}`, message: `Details for ${id}`, severity: 'info',
    attention_state: 'read', condition_state: 'na', occurrence_count: 1, timeline: [], deliveries: [],
});
const response = (id) => ({ data: { data: notification(id) } });
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};
const detail = () => screen.getByRole('region', { name: 'Notification detail' });
const renderPage = () => render(
    <MemoryRouter initialEntries={['/notification-history?notification=7']}>
        <Link to="?notification=7">Choose first</Link>
        <Link to="?notification=8">Choose second</Link>
        <NotificationHistoryPage />
    </MemoryRouter>,
);

describe('notification detail request recovery and ownership (#39)', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        mocks.get.mockImplementation(async (path) => path.includes('?')
            ? { data: { data: [notification(7), notification(8)], meta: { unread_count: 0 } } }
            : response(Number(path.split('/').at(-1))));
        mocks.post.mockResolvedValue({ data: { data: {} } });
        mocks.refresh.mockResolvedValue(undefined);
    });

    it('keeps successfully loaded detail visible when marking it read fails', async () => {
        mocks.post.mockRejectedValue(new Error('synthetic read failure'));
        renderPage();
        expect(await screen.findByText('Notification loaded, but its read status could not be updated.')).toBeVisible();
        expect(within(detail()).getByText('Details for 7')).toBeVisible();
        expect(mocks.get).toHaveBeenCalledWith('/notification-center/7', expect.anything());
    });

    it('keeps detail visible when the header refresh fails', async () => {
        mocks.refresh.mockRejectedValue(new Error('synthetic refresh failure'));
        renderPage();
        expect(await screen.findByText('Notification loaded, but the notification list could not be refreshed.')).toBeVisible();
        expect(within(detail()).getByText('Details for 7')).toBeVisible();
    });

    it('shows a detail-specific failure and retries the selected notification', async () => {
        let failed = false;
        const normalGet = mocks.get.getMockImplementation();
        mocks.get.mockImplementation((path) => {
            if (path === '/notification-center/7' && !failed) {
                failed = true;
                return Promise.reject(new Error('synthetic detail failure'));
            }
            return normalGet(path);
        });
        renderPage();
        expect(await screen.findByText('Notification details unavailable')).toBeVisible();
        expect(mocks.post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Retry notification' }));
        await waitFor(() => expect(within(detail()).getByText('Details for 7')).toBeVisible());
        expect(screen.queryByText('Notification details unavailable')).not.toBeInTheDocument();
        await waitFor(() => expect(mocks.post).toHaveBeenCalledTimes(1));
    });

    it.each(['success', 'failure'])('ignores a late %s from a previously selected notification', async (outcome) => {
        const first = deferred();
        const normalGet = mocks.get.getMockImplementation();
        mocks.get.mockImplementation((path) => path === '/notification-center/7' ? first.promise : normalGet(path));
        renderPage();
        expect(await screen.findByText('Loading notification details.')).toBeVisible();
        fireEvent.click(screen.getByRole('link', { name: 'Choose second' }));
        await waitFor(() => expect(within(detail()).getByText('Details for 8')).toBeVisible());
        await act(async () => {
            if (outcome === 'success') first.resolve(response(7));
            else first.reject(new Error('late synthetic failure'));
        });
        expect(within(detail()).getByText('Details for 8')).toBeVisible();
        expect(within(detail()).queryByText('Details for 7')).not.toBeInTheDocument();
        expect(mocks.post).not.toHaveBeenCalledWith('/notification-center/7/read', null, expect.anything());
        expect(screen.queryByText('Notification details unavailable')).not.toBeInTheDocument();
    });

    it('clears the displayed detail immediately when another selection starts loading', async () => {
        const second = deferred();
        const normalGet = mocks.get.getMockImplementation();
        mocks.get.mockImplementation((path) => path === '/notification-center/8' ? second.promise : normalGet(path));
        renderPage();
        await waitFor(() => expect(within(detail()).getByText('Details for 7')).toBeVisible());
        fireEvent.click(screen.getByRole('link', { name: 'Choose second' }));
        expect(screen.queryByRole('region', { name: 'Notification detail' })).not.toBeInTheDocument();
        expect(screen.getByText('Loading notification details.')).toBeVisible();
        await act(async () => second.resolve(response(8)));
        expect(within(detail()).getByText('Details for 8')).toBeVisible();
    });

    it('rejects the original request after selecting A, then B, then A again', async () => {
        const original = deferred();
        let firstCalls = 0;
        const normalGet = mocks.get.getMockImplementation();
        mocks.get.mockImplementation((path) => {
            if (path === '/notification-center/7' && ++firstCalls === 1) return original.promise;
            if (path === '/notification-center/7') return Promise.resolve({ data: { data: { ...notification(7), message: 'Current first notification' } } });
            return normalGet(path);
        });
        renderPage();
        fireEvent.click(screen.getByRole('link', { name: 'Choose second' }));
        await waitFor(() => expect(within(detail()).getByText('Details for 8')).toBeVisible());
        fireEvent.click(screen.getByRole('link', { name: 'Choose first' }));
        await waitFor(() => expect(within(detail()).getByText('Current first notification')).toBeVisible());
        await act(async () => original.resolve(response(7)));
        expect(within(detail()).getByText('Current first notification')).toBeVisible();
        expect(mocks.post.mock.calls.filter(([path]) => path === '/notification-center/7/read')).toHaveLength(1);
    });

    it('does not restore a closed detail after its read update fails', async () => {
        const read = deferred();
        mocks.post.mockReturnValue(read.promise);
        renderPage();
        await waitFor(() => expect(within(detail()).getByText('Details for 7')).toBeVisible());
        fireEvent.click(screen.getByRole('button', { name: 'Close detail' }));
        await act(async () => read.reject(new Error('late read failure')));
        expect(screen.queryByRole('region', { name: 'Notification detail' })).not.toBeInTheDocument();
        expect(screen.queryByText(/read status could not/)).not.toBeInTheDocument();
        expect(mocks.refresh).not.toHaveBeenCalled();
    });

    it('ignores stale read failures after selecting another notification', async () => {
        const read = deferred();
        mocks.post.mockImplementation((path) => path === '/notification-center/7/read' ? read.promise : Promise.resolve({}));
        renderPage();
        await waitFor(() => expect(within(detail()).getByText('Details for 7')).toBeVisible());
        fireEvent.click(screen.getByRole('link', { name: 'Choose second' }));
        await waitFor(() => expect(within(detail()).getByText('Details for 8')).toBeVisible());
        await act(async () => read.reject(new Error('late read failure')));
        expect(within(detail()).getByText('Details for 8')).toBeVisible();
        expect(screen.queryByText(/read status could not/)).not.toBeInTheDocument();
    });

    it('does not apply an old delivery retry to the newly selected notification', async () => {
        const retry = deferred();
        const normalGet = mocks.get.getMockImplementation();
        mocks.get.mockImplementation((path) => path === '/notification-center/7'
            ? Promise.resolve({ data: { data: { ...notification(7), deliveries: [{ id: 4, channel: 'email', status: 'failed', retryable: true, attempt_count: 1 }] } } })
            : normalGet(path));
        mocks.post.mockImplementation((path) => path.endsWith('/retry') ? retry.promise : Promise.resolve({}));
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: 'Retry email delivery' }));
        fireEvent.click(screen.getByRole('link', { name: 'Choose second' }));
        await waitFor(() => expect(within(detail()).getByText('Details for 8')).toBeVisible());
        await act(async () => retry.resolve({}));
        expect(within(detail()).getByText('Details for 8')).toBeVisible();
        expect(mocks.get.mock.calls.filter(([path]) => path === '/notification-center/7')).toHaveLength(1);
        expect(mocks.toast).not.toHaveBeenCalled();
    });
});
