import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import TransactionsPage from '../../../resources/js/src/pages/TransactionsPage.jsx';
import { apiMock } from './helpers/mockApi.js';
import AuthContext from '../../../resources/js/src/context/AuthContext.jsx';
import { PortfolioProvider } from '../../../resources/js/src/context/PortfolioContext.jsx';

it('retains an unconfirmed correction draft and retries without creating a new transaction', async () => {
    Element.prototype.scrollIntoView = vi.fn();
    const original = { id: 91, stock_id: 42, stock: { id: 42, symbol: 'INFY', name: 'Infosys', exchange: 'NSE' }, type: 'buy', quantity: 2, price: 100, fees: 0, transaction_date: '2026-10-01', notes: 'Original evidence' };
    apiMock.get.mockImplementation(async path => ({ data: path === '/transactions' ? { data: [original] } : path === '/settings' ? { data: { fee_components: [] } } : path === '/portfolios' ? { data: [{ id: 1, name: 'Main', is_default: true }] } : { data: [] } }));
    apiMock.put.mockRejectedValueOnce(new Error('Synthetic transport failure')).mockResolvedValueOnce({ data: {} });
    render(<MemoryRouter initialEntries={[{ pathname: '/transactions', state: { editTransaction: original } }]}><AuthContext.Provider value={{ loading: false, isAuthenticated: true, user: { id: 1, default_portfolio_id: 1 } }}><PortfolioProvider><TransactionsPage /></PortfolioProvider></AuthContext.Provider></MemoryRouter>);
    await screen.findByRole('button', { name: 'Update Transaction' }); fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Corrected evidence' } }); fireEvent.click(screen.getByRole('button', { name: 'Update Transaction' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Transaction save could not be confirmed'); expect(screen.getByLabelText('Notes')).toHaveValue('Corrected evidence'); expect(apiMock.post).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Update Transaction' })); await vi.waitFor(() => expect(apiMock.put).toHaveBeenCalledTimes(2)); expect(apiMock.put.mock.calls[0][1]).toEqual(apiMock.put.mock.calls[1][1]); expect(apiMock.put.mock.calls[0][0]).toBe('/transactions/91'); expect(apiMock.post).not.toHaveBeenCalled();
});
