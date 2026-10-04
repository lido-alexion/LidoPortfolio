import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import MicrostructureKiteStatusCard from '../../../resources/js/src/components/MicrostructureKiteStatusCard.jsx';
import { apiMock } from './helpers/mockApi.js';
import { apiEnvelope, axiosOk } from './fixtures/tosApi.js';

describe('Microstructure Kite status card', () => {
    it('separates a usable token from confirmed live packet receipt', async () => {
        apiMock.get.mockResolvedValue(axiosOk(apiEnvelope({
            display_state: 'attention',
            kite: { usable: true },
            collector: { websocket_connected: true, packet_recent: false },
        })));
        render(<MicrostructureKiteStatusCard />);
        expect(await screen.findByText('Attention')).toBeInTheDocument();
        expect(screen.getByText(/no recent packets/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Connect Kite' })).toHaveAttribute('href', '/kite-connect');
    });
});
