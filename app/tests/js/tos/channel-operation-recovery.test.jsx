import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import api from '../../../resources/js/src/api';
import NotificationSettingsPage from '../../../resources/js/src/pages/NotificationSettingsPage';
vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
beforeEach(() => { cleanup(); vi.clearAllMocks(); api.get.mockImplementation(async path => ({ data: { data: path.endsWith('/email-destinations') ? [] : [{ channel: 'telegram', configured: true, enabled: false, chat_id: 'synthetic-id', bot_token_configured: true, health_status: 'unverified' }, { channel: 'email', configured: true, enabled: false, account_email: 'fixture@example.invalid', account_email_verified: true }, { channel: 'webhook', configured: true, enabled: false, url: 'https://example.invalid/fixture', health_status: 'unverified' }] } })); });
it('prevents overlapping channel mutations while preserving the reviewed pending draft', async () => {
    let finish; api.put.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; })); render(<NotificationSettingsPage />);
    const telegram = within((await screen.findByRole('heading', { name: 'telegram', exact: true })).closest('section'));
    const email = within(screen.getByRole('heading', { name: 'email', exact: true }).closest('section'));
    fireEvent.change(telegram.getByLabelText('Chat ID'), { target: { value: 'reviewed-synthetic-id' } });
    fireEvent.click(telegram.getByRole('button', { name: 'Save', exact: true }));
    expect(email.getByRole('button', { name: 'Save', exact: true })).toBeDisabled();
    expect(telegram.getByLabelText('Chat ID')).toBeDisabled(); expect(telegram.getByLabelText('Chat ID')).toHaveValue('reviewed-synthetic-id');
    await act(async () => finish({ data: { data: {} } }));
});
