import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import PortfolioReconciliationCard from '../../../resources/js/src/components/PortfolioReconciliationCard.jsx';
import { apiMock } from './helpers/mockApi.js';

const snapshot = {
    data: {
        data: {
            status: {
                overall: 'attention_required',
                holdings: 'mismatch',
                funds: 'reconciled',
                execution_blocked: true,
                last_successful_at: '2026-09-11T12:00:00Z',
                last_failure: null,
            },
            runs: [{ id: 7, trigger: 'post_trade', status: 'completed', overall_status: 'attention_required', completed_at: '2026-09-11T12:00:00Z' }],
        },
    },
};

describe('Portfolio reconciliation card', () => {
    beforeEach(() => apiMock.get.mockResolvedValue(snapshot));

    it('is limited to live automated execution modes', () => {
        render(<PortfolioReconciliationCard executionMode="manual" />);
        expect(screen.queryByText('Broker portfolio reconciliation')).not.toBeInTheDocument();
        expect(apiMock.get).not.toHaveBeenCalled();
    });

    it('shows independent statuses, execution block, history and immutable evidence', async () => {
        apiMock.get.mockImplementation((url) => Promise.resolve(url === '/reconciliation/7' ? {
            data: { data: {
                id: 7,
                funds_status: 'reconciled',
                discrepancies: { holdings: [{ symbol: 'AAA', stoxQty: 1, brokerQty: 2 }] },
                unsupported_instruments: [{ symbol: 'GOLD' }],
            } },
        } : snapshot));

        render(<PortfolioReconciliationCard executionMode="automatic" />);

        expect(await screen.findAllByText('attention required')).toHaveLength(2);
        expect(screen.getByText(/New broker execution is blocked/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Evidence' }));
        expect(await screen.findByText(/AAA: StoX 1 shares; Kite 2/)).toBeInTheDocument();
        expect(screen.getByText(/GOLD/)).toBeInTheDocument();
    });

    it('runs a manual reconciliation and refreshes status', async () => {
        apiMock.post.mockResolvedValue({ data: { data: {} } });
        render(<PortfolioReconciliationCard executionMode="semi_automatic" />);
        await screen.findAllByText('attention required');

        fireEvent.click(screen.getByRole('button', { name: 'Run now' }));

        await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith('/reconciliation', {}, { skipErrorToast: true }));
        expect(apiMock.get).toHaveBeenCalledTimes(2);
    });
});
