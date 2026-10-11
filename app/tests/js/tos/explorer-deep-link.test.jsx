import React, { StrictMode } from 'react';
import { MemoryRouter } from 'react-router-dom';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it } from 'vitest';
import StockExplorerPage from '../../../resources/js/src/pages/StockExplorerPage.jsx';
import { apiMock } from './helpers/mockApi.js';

beforeEach(() => {
    apiMock.get.mockImplementation(async path => ({ data: { data: path === '/indexes'
        ? { primary_symbol: 'NIFTY50', indexes: [{ symbol: 'NIFTY50', name: 'Nifty 50', exchange: 'NSE' }] }
        : [{ id: 42, symbol: 'TCS', exchange: 'NSE', name: 'Tata Consultancy Services' }] } }));
    apiMock.post.mockRejectedValue({ response: { status: 422, data: { message: 'History unavailable.' } } });
});

it('auto-runs a deep link after StrictMode cancels the first stock resolution and allows retry', async () => {
    render(<StrictMode><MemoryRouter initialEntries={['/explorer?symbol=TCS&benchmark=NIFTY50']}><StockExplorerPage /></MemoryRouter></StrictMode>);
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith('/analytics/explore', {
        symbol: 'TCS', exchange: 'NSE', benchmark_symbol: 'NIFTY50', periods: [1, 3, 6, 12],
    }, { skipErrorToast: true }));
    expect(apiMock.post).toHaveBeenCalledTimes(1);
    expect(await screen.findByText('Manual Relative Strength Input')).toBeVisible();
    await userEvent.click(screen.getByRole('button', { name: 'Calculate relative strength', exact: true }));
    await waitFor(() => expect(apiMock.post).toHaveBeenCalledTimes(2));
});
