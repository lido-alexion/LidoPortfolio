import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import BrokerConnectionPanel from '../../../resources/js/src/components/BrokerConnectionPanel.jsx';
import { apiMock } from './helpers/mockApi.js';
import { showToast } from '../../../resources/js/src/toast';

vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));

beforeEach(() => showToast.mockClear());

const statusResponse = (data) => ({ data: { data } });
const connectedUsable = { configured: true, connected: true, usable: true };
const sessionExpired = { configured: true, connected: true, usable: false };
const disconnected = { configured: true, connected: false, usable: false };

describe('Kite connection status read recovery', () => {
    it('keeps initial status unknown and controls disabled until the first read is confirmed', async () => {
        let resolveStatus;
        apiMock.get.mockReturnValueOnce(new Promise((resolve) => { resolveStatus = resolve; }));
        render(<BrokerConnectionPanel />);

        expect(screen.getByRole('status')).toHaveTextContent('Checking Kite connection status');
        expect(screen.queryByText('Not connected')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Connect Kite' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Disconnect' })).toBeDisabled();

        await act(async () => resolveStatus(statusResponse(sessionExpired)));

        expect(await screen.findByText('Session expired')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Connect Kite' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Disconnect' })).toBeEnabled();
        expect(apiMock.get).toHaveBeenCalledTimes(1);
    });

    it('shows an unavailable state after a failed initial read and retries through the existing GET', async () => {
        const user = userEvent.setup();
        apiMock.get.mockRejectedValueOnce(new Error('Synthetic private server detail'))
            .mockResolvedValueOnce(statusResponse(disconnected));
        render(<BrokerConnectionPanel />);

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('Kite connection status is unavailable. Retry to check the current connection.');
        expect(alert).not.toHaveTextContent('Synthetic private server detail');
        expect(screen.queryByText('Not connected')).not.toBeInTheDocument();
        expect(screen.queryByText('Kite API keys are not configured on this server.')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Connect Kite' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Disconnect' })).toBeDisabled();

        await user.click(screen.getByRole('button', { name: 'Retry status' }));

        expect(await screen.findByText('Not connected')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Connect Kite' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Disconnect' })).toBeDisabled();
        expect(apiMock.get).toHaveBeenCalledTimes(2);
        expect(apiMock.get).toHaveBeenNthCalledWith(1, '/v1/broker/status', { skipErrorToast: true });
        expect(apiMock.get).toHaveBeenNthCalledWith(2, '/v1/broker/status', { skipErrorToast: true });
        expect(apiMock.post).not.toHaveBeenCalled();
    });

    it.each([
        ['server not configured', { configured: false, connected: false, usable: false }, 'Kite API keys are not configured on this server.', true, true],
        ['not connected', disconnected, 'Not connected', false, true],
        ['session expired', sessionExpired, 'Session expired', false, false],
        ['connected', connectedUsable, 'Connected', false, false],
    ])('retains the confirmed %s presentation', async (_name, data, expected, connectDisabled, disconnectDisabled) => {
        apiMock.get.mockResolvedValueOnce(statusResponse(data));
        render(<BrokerConnectionPanel />);

        expect(await screen.findByText(expected)).toBeVisible();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Connect Kite' })).toHaveProperty('disabled', connectDisabled);
        expect(screen.getByRole('button', { name: 'Disconnect' })).toHaveProperty('disabled', disconnectDisabled);
    });

    it('treats a malformed status payload as unknown and recovers only after explicit retry', async () => {
        const user = userEvent.setup();
        apiMock.get.mockResolvedValueOnce(statusResponse({ configured: true, connected: 'false', usable: false }))
            .mockResolvedValueOnce(statusResponse(disconnected));
        render(<BrokerConnectionPanel />);

        expect(await screen.findByRole('alert')).toHaveTextContent('Kite connection status is unavailable');
        expect(screen.queryByText('Not connected')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Retry status' }));
        expect(await screen.findByText('Not connected')).toBeVisible();
        expect(apiMock.get).toHaveBeenCalledTimes(2);
        expect(apiMock.post).not.toHaveBeenCalled();
    });

    it('invalidates a confirmed connection when disconnect succeeds but its status read fails', async () => {
        const user = userEvent.setup();
        apiMock.get.mockResolvedValueOnce(statusResponse(connectedUsable))
            .mockRejectedValueOnce(new Error('Synthetic private status detail'))
            .mockResolvedValueOnce(statusResponse(disconnected));
        apiMock.post.mockResolvedValueOnce({ data: { data: { disconnected: true } } });
        render(<BrokerConnectionPanel />);

        expect(await screen.findByText('Connected')).toBeVisible();
        await user.click(screen.getByRole('button', { name: 'Disconnect' }));

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('Kite connection status is unavailable. Retry to check the current connection.');
        expect(alert).not.toHaveTextContent('Synthetic private status detail');
        expect(screen.queryByText('Connected')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Connect Kite' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Disconnect' })).toBeDisabled();
        expect(apiMock.post).toHaveBeenCalledTimes(1);
        expect(apiMock.get).toHaveBeenCalledTimes(2);
        expect(apiMock.post).toHaveBeenCalledWith('/v1/broker/kite/disconnect', {}, { skipErrorToast: true });
        expect(apiMock.get.mock.calls).toEqual([
            ['/v1/broker/status', { skipErrorToast: true }],
            ['/v1/broker/status', { skipErrorToast: true }],
        ]);

        await user.click(screen.getByRole('button', { name: 'Retry status' }));

        expect(await screen.findByText('Not connected')).toBeVisible();
        expect(apiMock.post).toHaveBeenCalledTimes(1);
        expect(apiMock.get).toHaveBeenCalledTimes(3);
        expect(apiMock.post).toHaveBeenCalledWith('/v1/broker/kite/disconnect', {}, { skipErrorToast: true });
        expect(apiMock.get.mock.calls).toEqual([
            ['/v1/broker/status', { skipErrorToast: true }],
            ['/v1/broker/status', { skipErrorToast: true }],
            ['/v1/broker/status', { skipErrorToast: true }],
        ]);
        expect(apiMock.put).not.toHaveBeenCalled();
        expect(apiMock.patch).not.toHaveBeenCalled();
        expect(apiMock.delete).not.toHaveBeenCalled();
    });

    it('preserves confirmed status when the disconnect mutation itself fails', async () => {
        const user = userEvent.setup();
        apiMock.get.mockResolvedValueOnce(statusResponse(connectedUsable));
        apiMock.post.mockRejectedValueOnce(new Error('Synthetic disconnect failure'));
        render(<BrokerConnectionPanel />);

        expect(await screen.findByText('Connected')).toBeVisible();
        await user.click(screen.getByRole('button', { name: 'Disconnect' }));

        await waitFor(() => expect(showToast).toHaveBeenCalledWith('Synthetic disconnect failure', 'danger'));
        expect(screen.getByText('Connected')).toBeVisible();
        expect(screen.queryByText('Not connected')).not.toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(apiMock.get).toHaveBeenCalledTimes(1);
        expect(apiMock.post).toHaveBeenCalledTimes(1);
    });
});
