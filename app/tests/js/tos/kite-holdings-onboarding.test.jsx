import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import KiteHoldingsOnboardingPage from '../../../resources/js/src/pages/KiteHoldingsOnboardingPage.jsx';
import { apiMock } from './helpers/mockApi.js';

const draft = { data: { data: { snapshot_at: '2026-11-22T00:00:00Z', stocks: [{ id: 8, symbol: 'ABC', exchange: 'NSE', status: 'needs_review', kite_quantity: 5, kite_average_price: 100, entries: [{ type: 'buy', quantity: 5, price: 100, date: '2026-11-22', date_placeholder: true, provenance: 'kite_opening_position' }] }] } } };

describe('Kite holdings onboarding page', () => {
    it('accepts supported Console formats and clears placeholder status when a date is edited', async () => {
        apiMock.get.mockResolvedValue(draft);
        apiMock.put.mockResolvedValue({ data: { data: {} } });
        apiMock.post.mockResolvedValue({ data: { data: {} } });
        render(<KiteHoldingsOnboardingPage />);

        const upload = await screen.findByLabelText(/Optional Zerodha Console tradebook/);
        expect(upload.getAttribute('accept')).toContain('.xlsx');
        expect(upload.getAttribute('accept')).toContain('.xls');
        expect(screen.getByText(/Legacy binary \.xls workbooks are unsupported/)).toBeTruthy();
        expect(screen.getByText(/Placeholder date, not acquisition evidence/)).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Date'), { target: { value: '2026-11-21' } });
        fireEvent.click(screen.getByRole('button', { name: 'Verify stock' }));

        await waitFor(() => expect(apiMock.put).toHaveBeenCalled());
        const savedEntries = apiMock.put.mock.calls[0][1].entries;
        expect(savedEntries[0].date).toBe('2026-11-21');
        expect(savedEntries[0].date_placeholder).toBe(false);
        expect(apiMock.post).toHaveBeenCalledWith('/kite/holdings-onboarding/stocks/8/verify');
    });
});
