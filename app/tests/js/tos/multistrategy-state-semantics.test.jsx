import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import CapitalRecallPanel from '../../../resources/js/src/components/CapitalRecallPanel.jsx';
import RecommendationLenderActions from '../../../resources/js/src/components/RecommendationLenderActions.jsx';
import { apiMock, axiosError } from './helpers/mockApi.js';

describe('multi-strategy loading and failure states', () => {
    it('does not turn failed capital activity requests into successful empty states', async () => {
        apiMock.get.mockRejectedValue(axiosError('Capital activity unavailable'));
        render(<CapitalRecallPanel profileId={7} />);

        expect(await screen.findByRole('alert')).toHaveTextContent('Capital activity unavailable');
        expect(screen.getByText('Recall data unavailable.')).toBeInTheDocument();
        expect(screen.getByText('Bridge loan data unavailable.')).toBeInTheDocument();
        expect(screen.getByText('Sale proceeds data unavailable.')).toBeInTheDocument();
        expect(screen.queryByText('No recalls.')).not.toBeInTheDocument();
        expect(screen.queryByText('No pending proceeds from stock sale.')).not.toBeInTheDocument();
    });

    it('offers an explicit retry path after capital activity failure', async () => {
        apiMock.get.mockRejectedValue(axiosError('Capital activity unavailable'));
        render(<CapitalRecallPanel profileId={7} />);

        const retry = await screen.findByRole('button', { name: 'Retry' });
        expect(retry).toBeInTheDocument();
        fireEvent.click(retry);
        await waitFor(() => expect(apiMock.get).toHaveBeenCalledTimes(6));
    });

    it('shows no eligible lender explicitly instead of a blank lender panel', async () => {
        apiMock.get.mockResolvedValue({ data: { data: { lenders: [], amount: 5_000 } } });
        render(
            <RecommendationLenderActions
                recommendation={{
                    capital_request_id: 12,
                    capital_allocation_status: 'awaiting_lender_selection',
                }}
            />,
        );

        expect(await screen.findByText('No eligible lenders right now.')).toBeInTheDocument();
        expect(screen.getByText(/Approving commits capital/)).toBeInTheDocument();
    });
});
