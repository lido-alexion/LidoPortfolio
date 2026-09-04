import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import KiteReadinessCard from '../../../resources/js/src/components/KiteReadinessCard.jsx';
import { apiMock } from './helpers/mockApi.js';
import { apiEnvelope, axiosOk } from './fixtures/tosApi.js';

describe('Dashboard Kite readiness', () => {
    it('stays absent outside Automatic mode', () => {
        render(<KiteReadinessCard executionMode="manual" />);
        expect(screen.queryByText('Automatic execution is not ready')).not.toBeInTheDocument();
        expect(apiMock.get).not.toHaveBeenCalled();
    });

    it('prominently offers Connect Kite when Automatic is not usable', async () => {
        apiMock.get.mockResolvedValue(axiosOk(apiEnvelope({ configured: true, connected: false, usable: false })));
        render(<KiteReadinessCard executionMode="automatic" />);

        expect(await screen.findByRole('alert')).toHaveTextContent('daily Kite session is missing or expired');
        expect(screen.getByRole('button', { name: 'Connect Kite' })).toBeEnabled();
    });

    it('stays absent when Automatic mode is ready', async () => {
        apiMock.get.mockResolvedValue(axiosOk(apiEnvelope({ configured: true, connected: true, usable: true })));
        render(<KiteReadinessCard executionMode="automatic" />);

        await waitFor(() => expect(apiMock.get).toHaveBeenCalledWith('/v1/broker/status', { skipErrorToast: true }));
        expect(screen.queryByText('Automatic execution is not ready')).not.toBeInTheDocument();
    });
});
