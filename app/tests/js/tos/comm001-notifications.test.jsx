import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const apiFns = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock('../../../resources/js/src/api', () => ({ default: apiFns }));
vi.mock('../../../resources/js/src/context/NotificationContext', () => ({
    useNotifications: () => ({ refresh: vi.fn() }),
}));

import NotificationHistoryPage from '../../../resources/js/src/pages/NotificationHistoryPage.jsx';
import NotificationSettingsPage from '../../../resources/js/src/pages/NotificationSettingsPage.jsx';

describe('V9-COMM-001 notification controls', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        apiFns.post.mockResolvedValue({ data: { data: {} } });
        apiFns.put.mockResolvedValue({ data: { data: {} } });
    });

    it('provides labelled, responsive history filters and a mark-unread action', async () => {
        apiFns.get.mockResolvedValue({ data: { data: [{
            id: 7, attention_state: 'read', condition_state: 'na', type: 'recommendation.changed',
            severity: 'info', title: 'Recommendation changed', message: 'Review in StoX.',
            latest_activity_at: '2026-10-04T10:00:00Z',
        }], meta: { unread_count: 0 } } });
        render(<MemoryRouter><NotificationHistoryPage /></MemoryRouter>);

        const search = await screen.findByRole('textbox', { name: 'Search notifications' });
        expect(search.closest('.col-12')).toBeTruthy();
        expect(screen.getByRole('search', { name: 'Filter notification history' })).toBeInTheDocument();
        expect(screen.getByLabelText('From date')).toBeInTheDocument();
        expect(screen.getByLabelText('To date')).toBeInTheDocument();
        expect(screen.getByLabelText('Channel')).toBeInTheDocument();
        expect(screen.getByLabelText('Delivery status')).toBeInTheDocument();

        fireEvent.change(search, { target: { value: 'budget' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
        await waitFor(() => expect(apiFns.get).toHaveBeenCalledWith(expect.stringContaining('q=budget'), expect.anything()));
        fireEvent.click(screen.getByRole('button', { name: 'Mark unread' }));
        await waitFor(() => expect(apiFns.post).toHaveBeenCalledWith('/notification-center/7/unread', null, expect.anything()));
    });

    it('exposes the optional-email master, categories, timezone, quiet-hours and digest controls', async () => {
        apiFns.get.mockImplementation((path) => path === '/notification-settings'
            ? Promise.resolve({ data: { data: [], optional_email_preferences: {
                enabled: false, categories: { recommendation: false }, modes: { recommendation: 'immediate' },
                catalogue: { recommendation: 'Recommendation updates' }, quiet_start: '', quiet_end: '', digest_time: '09:00', timezone: 'UTC',
            } } })
            : Promise.resolve({ data: { data: [] } }));
        render(<NotificationSettingsPage />);

        expect(await screen.findByText('Optional product emails')).toBeInTheDocument();
        expect(screen.getByLabelText('Enable optional emails')).toBeInTheDocument();
        expect(screen.getByLabelText('Recommendation updates')).toBeInTheDocument();
        expect(screen.getByLabelText('Recommendation updates delivery')).toBeInTheDocument();
        expect(screen.getByLabelText('Quiet hours start')).toBeInTheDocument();
        expect(screen.getByLabelText('Quiet hours end')).toBeInTheDocument();
        expect(screen.getByLabelText('Daily digest time')).toBeInTheDocument();
        expect(screen.getByLabelText('Timezone')).toBeInTheDocument();
    });
});
