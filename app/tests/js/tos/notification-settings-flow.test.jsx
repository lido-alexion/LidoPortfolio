import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import NotificationSettingsPage from '../../../resources/js/src/pages/NotificationSettingsPage';
import { apiMock } from './helpers/mockApi.js';
import { showToast } from '../../../resources/js/src/toast';

vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));

const initialChannels = () => [
    { channel: 'in_app', enabled: true },
    { channel: 'telegram', configured: false, enabled: false, health_status: 'unverified', bot_token_configured: false, chat_id: null },
    { channel: 'email', configured: false, enabled: false, health_status: 'unverified', account_email: 'person@example.test', account_email_verified: false },
    { channel: 'webhook', configured: false, enabled: false, health_status: 'unverified', url: null, signing_secret_configured: false },
];

const envelope = (data) => ({ data });
const destinations = { data: { data: [{ id: 1, email: 'person@example.test', is_account_email: true, verified_at: null }] } };
const channelCard = async (channel) => (await screen.findByRole('heading', { name: channel })).closest('section');

describe('notification channel setup flow', () => {
    let channels;

    beforeEach(() => {
        channels = initialChannels();
        apiMock.get.mockImplementation(async (url) => {
            if (url === '/notification-settings') return envelope({ data: channels, optional_email_preferences: {} });
            if (url === '/notification-settings/email-destinations') return destinations;
            throw new Error(`Unexpected GET ${url}`);
        });
        apiMock.put.mockImplementation(async (url, payload) => {
            const channel = url.split('/').pop();
            channels = channels.map((item) => item.channel === channel ? {
                ...item,
                ...payload,
                configured: true,
                enabled: !!payload.enabled,
                verified_at: payload.enabled ? item.verified_at : null,
                chat_id: payload.chat_id ?? item.chat_id,
                url: payload.url ?? item.url,
                bot_token_configured: channel === 'telegram' ? true : item.bot_token_configured,
                health_status: 'unverified',
            } : item);
            return envelope({ data: channels.find((item) => item.channel === channel) });
        });
        apiMock.post.mockImplementation(async (url) => {
            const channel = url.split('/')[2];
            channels = channels.map((item) => item.channel === channel ? { ...item, verified_at: '2026-10-16T00:00:00Z', last_test_status: 'succeeded' } : item);
            return envelope({ data: { message: 'Channel verified successfully.' } });
        });
    });

    it('requires save before test, hides test for edited values, then unlocks enable after testing', async () => {
        render(<NotificationSettingsPage />);
        let card = await channelCard('telegram');
        expect(within(card).queryByRole('button', { name: 'Test channel' })).not.toBeInTheDocument();

        fireEvent.change(within(card).getByLabelText('Bot token'), { target: { value: 'secret-token' } });
        fireEvent.change(within(card).getByLabelText('Chat ID'), { target: { value: 'chat-42' } });
        expect(within(card).queryByRole('button', { name: 'Test channel' })).not.toBeInTheDocument();
        fireEvent.click(within(card).getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith('/notification-settings/telegram', {
            bot_token: 'secret-token', chat_id: 'chat-42', enabled: false, url: '',
        }, { skipErrorToast: true }));
        card = await channelCard('telegram');
        await within(card).findByRole('button', { name: 'Test channel' });
        expect(within(card).getByLabelText('Bot token')).toHaveValue('');

        fireEvent.click(within(card).getByRole('button', { name: 'Test channel' }));
        await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith('/notification-settings/telegram/test', null, { skipErrorToast: true }));
        card = await channelCard('telegram');
        await waitFor(() => expect(within(card).getByLabelText('Enabled')).toBeEnabled());
        fireEvent.click(within(card).getByLabelText('Enabled'));
        fireEvent.click(within(card).getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(apiMock.put).toHaveBeenCalledTimes(2));
        const enablePayload = apiMock.put.mock.calls[1][1];
        expect(enablePayload).toMatchObject({ chat_id: 'chat-42', enabled: true, url: '' });
        expect(enablePayload).not.toHaveProperty('bot_token');
    });

    it('explains the disabled enable control to keyboard users and reports test failure', async () => {
        apiMock.post.mockRejectedValueOnce({ response: { data: { message: 'Channel verification failed. Check the destination and service configuration.' } } });
        const { container } = render(<NotificationSettingsPage />);
        let card = await channelCard('telegram');
        const checkbox = within(card).getByLabelText('Enabled');
        expect(checkbox).toBeDisabled();
        const focusTarget = checkbox.parentElement;
        expect(focusTarget).toHaveAttribute('tabindex', '0');
        expect(focusTarget).toHaveAttribute('title', 'Save these settings and test the channel before enabling delivery.');
        focusTarget.focus();
        expect(container.ownerDocument.activeElement).toBe(focusTarget);
        expect(within(card).getByText('Save these settings and test the channel before enabling delivery.')).toBeVisible();

        fireEvent.change(within(card).getByLabelText('Bot token'), { target: { value: 'secret-token' } });
        fireEvent.change(within(card).getByLabelText('Chat ID'), { target: { value: 'chat-42' } });
        fireEvent.click(within(card).getByRole('button', { name: 'Save' }));
        card = await channelCard('telegram');
        const testButton = await within(card).findByRole('button', { name: 'Test channel' });
        fireEvent.click(testButton);
        await waitFor(() => expect(showToast).toHaveBeenCalledWith('Channel verification failed. Check the destination and service configuration.', 'danger'));
        expect(within(card).getByLabelText('Enabled')).toBeDisabled();
        expect(within(card).getByText('Test failed · check the saved settings and try again')).toBeVisible();
        expect(apiMock.post).toHaveBeenCalledTimes(1);
    });

    it('disables an enabled verified channel as soon as its destination is edited', async () => {
        channels = initialChannels().map((item) => item.channel === 'telegram' ? {
            ...item, configured: true, bot_token_configured: true, chat_id: 'old-chat', verified_at: '2026-10-15T00:00:00Z', enabled: true,
        } : item);
        render(<NotificationSettingsPage />);
        let card = await channelCard('telegram');
        expect(within(card).getByRole('button', { name: 'Test channel' })).toBeVisible();
        fireEvent.change(within(card).getByLabelText('Chat ID'), { target: { value: 'new-chat' } });
        expect(within(card).queryByRole('button', { name: 'Test channel' })).not.toBeInTheDocument();
        expect(within(card).getByLabelText('Enabled')).not.toBeChecked();
        expect(within(card).getByLabelText('Enabled')).toBeDisabled();
        expect(within(card).getByText('These edits are not tested yet. Save them, then test the saved settings before enabling delivery.')).toBeVisible();
        expect(within(card).getByText('Unsaved changes · save before testing')).toBeVisible();
        expect(apiMock.post).not.toHaveBeenCalled();

        fireEvent.click(within(card).getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(apiMock.put).toHaveBeenCalledWith('/notification-settings/telegram', {
            chat_id: 'new-chat', enabled: false, url: '',
        }, { skipErrorToast: true }));
        card = await channelCard('telegram');
        await within(card).findByRole('button', { name: 'Test channel' });
        fireEvent.click(within(card).getByRole('button', { name: 'Test channel' }));
        card = await channelCard('telegram');
        await waitFor(() => expect(within(card).getByLabelText('Enabled')).toBeEnabled());
        expect(within(card).getByText('Test passed · ready to enable')).toBeVisible();
    });

    it('shows when a local enablement choice differs from the saved channel state', async () => {
        channels = initialChannels().map((item) => item.channel === 'telegram' ? {
            ...item, configured: true, bot_token_configured: true, chat_id: 'saved-chat', verified_at: '2026-10-15T00:00:00Z', enabled: true,
        } : item);
        render(<NotificationSettingsPage />);
        const card = await channelCard('telegram');
        fireEvent.change(within(card).getByLabelText('Chat ID'), { target: { value: 'temporary-chat' } });
        fireEvent.change(within(card).getByLabelText('Chat ID'), { target: { value: 'saved-chat' } });
        expect(within(card).queryByRole('button', { name: 'Test channel' })).toBeVisible();
        expect(within(card).getByText('Enablement change · save to apply')).toBeVisible();
        expect(within(card).getByLabelText('Enabled')).not.toBeChecked();
        expect(within(card).getByLabelText('Enabled')).toBeEnabled();
    });

    it('invalidates a saved Telegram token replacement and a webhook URL edit before Test', async () => {
        channels = initialChannels().map((item) => {
            if (item.channel === 'telegram') return {
                ...item, configured: true, bot_token_configured: true, chat_id: 'saved-chat', verified_at: '2026-10-15T00:00:00Z', enabled: true,
            };
            if (item.channel === 'webhook') return {
                ...item, configured: true, url: 'https://old.example.test/hook', signing_secret_configured: true, verified_at: '2026-10-15T00:00:00Z', enabled: true,
            };
            return item;
        });
        render(<NotificationSettingsPage />);

        let telegram = await channelCard('telegram');
        expect(within(telegram).getByRole('button', { name: 'Test channel' })).toBeVisible();
        fireEvent.change(within(telegram).getByLabelText('Bot token'), { target: { value: 'replacement-token' } });
        expect(within(telegram).getByLabelText('Enabled')).not.toBeChecked();
        expect(within(telegram).getByLabelText('Enabled')).toBeDisabled();
        expect(within(telegram).queryByRole('button', { name: 'Test channel' })).not.toBeInTheDocument();
        fireEvent.click(within(telegram).getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(apiMock.put).toHaveBeenCalledTimes(1));
        expect(apiMock.put.mock.calls[0][1]).toMatchObject({ bot_token: 'replacement-token', chat_id: 'saved-chat', enabled: false, url: '' });

        let webhook = await channelCard('webhook');
        expect(within(webhook).getByRole('button', { name: 'Test channel' })).toBeVisible();
        fireEvent.change(within(webhook).getByLabelText('HTTPS endpoint'), { target: { value: 'https://new.example.test/hook' } });
        expect(within(webhook).getByLabelText('Enabled')).not.toBeChecked();
        expect(within(webhook).getByLabelText('Enabled')).toBeDisabled();
        expect(within(webhook).queryByRole('button', { name: 'Test channel' })).not.toBeInTheDocument();
        fireEvent.click(within(webhook).getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(apiMock.put).toHaveBeenCalledTimes(2));
        expect(apiMock.put.mock.calls[1][0]).toBe('/notification-settings/webhook');
        expect(apiMock.put.mock.calls[1][1]).toMatchObject({ url: 'https://new.example.test/hook', enabled: false });
        webhook = await channelCard('webhook');
        expect(within(webhook).getByRole('button', { name: 'Test channel' })).toBeVisible();
    });

    it('preserves independent unsaved channel edits when another channel is edited', async () => {
        render(<NotificationSettingsPage />);
        const telegram = await channelCard('telegram');
        const webhook = await channelCard('webhook');
        fireEvent.change(within(telegram).getByLabelText('Bot token'), { target: { value: 'draft-telegram-token' } });
        fireEvent.change(within(telegram).getByLabelText('Chat ID'), { target: { value: 'draft-chat' } });
        fireEvent.change(within(webhook).getByLabelText('HTTPS endpoint'), { target: { value: 'https://draft.example.test/hook' } });
        expect(within(telegram).getByLabelText('Bot token')).toHaveValue('draft-telegram-token');
        expect(within(telegram).getByLabelText('Chat ID')).toHaveValue('draft-chat');
        expect(within(webhook).getByLabelText('HTTPS endpoint')).toHaveValue('https://draft.example.test/hook');
        expect(within(telegram).queryByRole('button', { name: 'Test channel' })).not.toBeInTheDocument();
        expect(within(webhook).queryByRole('button', { name: 'Test channel' })).not.toBeInTheDocument();
    });

    it('preserves another channel draft after saving a channel', async () => {
        render(<NotificationSettingsPage />);
        let telegram = await channelCard('telegram');
        let webhook = await channelCard('webhook');
        fireEvent.change(within(telegram).getByLabelText('Bot token'), { target: { value: 'draft-token' } });
        fireEvent.change(within(telegram).getByLabelText('Chat ID'), { target: { value: 'draft-chat' } });
        fireEvent.change(within(webhook).getByLabelText('HTTPS endpoint'), { target: { value: 'https://saved.example.test/hook' } });
        fireEvent.click(within(webhook).getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(apiMock.put).toHaveBeenCalledTimes(1));
        telegram = await channelCard('telegram');
        webhook = await channelCard('webhook');
        expect(within(telegram).getByLabelText('Bot token')).toHaveValue('draft-token');
        expect(within(telegram).getByLabelText('Chat ID')).toHaveValue('draft-chat');
        expect(within(telegram).queryByRole('button', { name: 'Test channel' })).not.toBeInTheDocument();
        expect(within(webhook).getByRole('button', { name: 'Test channel' })).toBeVisible();
    });

    it('lets an existing enabled channel be turned off without another test', async () => {
        channels = initialChannels().map((item) => item.channel === 'telegram' ? {
            ...item, configured: true, bot_token_configured: true, chat_id: 'old-chat', verified_at: null, enabled: true,
        } : item);
        render(<NotificationSettingsPage />);
        let card = await channelCard('telegram');
        fireEvent.click(within(card).getByLabelText('Enabled'));
        expect(within(card).getByLabelText('Enabled')).not.toBeChecked();
        fireEvent.click(within(card).getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(apiMock.put).toHaveBeenCalledTimes(1));
        const disablePayload = apiMock.put.mock.calls[0][1];
        expect(disablePayload).toMatchObject({ chat_id: 'old-chat', enabled: false, url: '' });
        expect(disablePayload).not.toHaveProperty('bot_token');
        expect(apiMock.post).not.toHaveBeenCalled();
    });

    it('keeps unsaved values visible and test hidden after Save fails', async () => {
        apiMock.put.mockRejectedValueOnce({ response: { data: { message: 'Telegram settings could not be saved.' } } });
        render(<NotificationSettingsPage />);
        let card = await channelCard('telegram');
        fireEvent.change(within(card).getByLabelText('Bot token'), { target: { value: 'new-token' } });
        fireEvent.change(within(card).getByLabelText('Chat ID'), { target: { value: 'new-chat' } });
        fireEvent.click(within(card).getByRole('button', { name: 'Save' }));
        card = await channelCard('telegram');
        await waitFor(() => expect(showToast).toHaveBeenCalledWith('Telegram settings could not be saved.', 'danger'));
        expect(within(card).getByLabelText('Bot token')).toHaveValue('new-token');
        expect(within(card).getByLabelText('Chat ID')).toHaveValue('new-chat');
        expect(within(card).queryByRole('button', { name: 'Test channel' })).not.toBeInTheDocument();
        expect(within(card).getByRole('alert')).toHaveTextContent('Telegram settings could not be saved.');
        expect(within(card).getByLabelText('Enabled')).toBeDisabled();
        expect(apiMock.post).not.toHaveBeenCalled();
    });

    it('fails closed when settings cannot be loaded and exposes retry instead of channel controls', async () => {
        apiMock.get.mockRejectedValueOnce(new Error('settings offline'));
        render(<NotificationSettingsPage />);
        expect(await screen.findByRole('heading', { name: 'Notification settings unavailable' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Retry' })).toBeVisible();
        expect(screen.queryByLabelText('Enabled')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Test channel' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
    });
});
