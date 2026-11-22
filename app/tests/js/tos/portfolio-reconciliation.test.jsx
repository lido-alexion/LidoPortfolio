import React from 'react';
import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import PortfolioReconciliationCard from '../../../resources/js/src/components/PortfolioReconciliationCard.jsx';
import { apiMock } from './helpers/mockApi.js';
import { renderWithKiteConnection } from './helpers/renderWithKiteConnection.jsx';

const usableBroker = { configured: true, connected: true, usable: true, expires_at: '2099-01-01T00:00:00Z' };
let brokerStatus;

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
            runs: [
                { id: 8, trigger: 'manual', status: 'completed', overall_status: 'reconciled', completed_at: '2026-09-11T12:00:00Z' },
                { id: 7, trigger: 'post_trade', status: 'completed', overall_status: 'attention_required', completed_at: '2026-09-11T12:00:00Z' },
            ],
        },
    },
};

describe('Portfolio reconciliation card', () => {
    beforeEach(() => {
        brokerStatus = usableBroker;
        apiMock.get.mockImplementation((url) => Promise.resolve(url === '/v1/broker/status'
            ? { data: { data: brokerStatus } } : snapshot));
    });

    it('is limited to live automated execution modes', () => {
        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="manual" />);
        expect(screen.queryByText('Broker portfolio reconciliation')).not.toBeInTheDocument();
        expect(apiMock.get.mock.calls.some(([url]) => url === '/reconciliation')).toBe(false);
    });

    it('shows independent statuses, execution block, history and immutable evidence', async () => {
        apiMock.get.mockImplementation((url) => Promise.resolve(url === '/v1/broker/status'
            ? { data: { data: brokerStatus } } : url === '/reconciliation/7' ? {
            data: { data: {
                id: 7,
                funds_status: 'mismatch',
                tolerances: { holding_cost: 5 },
                discrepancies: {
                    holdings: [{ symbol: 'AAA', stoxSymbol: 'AAA', brokerSymbol: 'AAA-BE', isin: 'INE000000001', stoxQty: 1, brokerQty: 2, stoxCost: 100, brokerCost: 210, costDifference: 110 }],
                    funds: { stox_cash: 1000, broker_cash: 900, difference: -100 },
                },
                unsupported_instruments: [{ symbol: 'GOLD' }],
            } },
        } : snapshot));

        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="automatic" />);

        expect(await screen.findAllByText('Attention required')).toHaveLength(2);
        expect(screen.getAllByText('Reconciled')).toHaveLength(2);
        expect(screen.getAllByRole('button', { name: 'Evidence', exact: true })).toHaveLength(1);
        expect(screen.getAllByText(/\d{1,2}\/\d{1,2}\/\d{4}, \d{1,2}:\d{2} (?:AM|PM)/)).toHaveLength(2);
        expect(screen.getByText(/New broker execution is blocked/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Evidence' }));
        expect(await screen.findByText('AAA')).toBeInTheDocument();
        expect(screen.getByText('StoX qty')).toBeInTheDocument();
        expect(screen.getByText('Kite qty')).toBeInTheDocument();
        expect(screen.getByText(/ISIN INE000000001; Kite: AAA-BE/)).toBeInTheDocument();
        expect(screen.getByText('₹110.00')).toBeInTheDocument();
        expect(screen.getByText('₹5.00')).toBeInTheDocument();
        expect(screen.getByText('Cash balance')).toBeInTheDocument();
        expect(screen.getByText('₹-100.00')).toBeInTheDocument();
        expect(screen.getByText(/GOLD/)).toBeInTheDocument();
    });

    it('keeps the date choice while settlement behavior is being validated', async () => {
        apiMock.post.mockResolvedValue({ data: { data: {} } });
        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="semi_automatic" />);
        await screen.findAllByText('Attention required');
        expect(screen.getByText(/Including today syncs available CNC fills and applies execution-proven T\+1 adjustments/)).toBeInTheDocument();
        await waitFor(() => expect(screen.getByRole('button', { name: 'Reconcile excluding today' })).toBeEnabled());
        const mainButton = screen.getByRole('button', { name: 'Reconcile excluding today' });
        expect(mainButton).toBeEnabled();

        fireEvent.click(mainButton);

        await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith('/reconciliation', { ignore_today_transactions: true }, { skipErrorToast: true }));
        expect(apiMock.get.mock.calls.filter(([url]) => url === '/reconciliation')).toHaveLength(2);
    });

    it('offers a one-click CNC trade sync', async () => {
        apiMock.post.mockResolvedValue({ data: { data: { imported: 2, matched: 1, review: 0 } } });
        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="automatic" />);
        await waitFor(() => expect(screen.getByRole('button', { name: 'Sync CNC trades' })).toBeEnabled());
        fireEvent.click(screen.getByRole('button', { name: 'Sync CNC trades' }));
        await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith('/reconciliation/trades/sync', {}, { skipErrorToast: true }));
    });

    it.each([
        ['broker missing', { configured: false, connected: false, usable: false, expires_at: null }],
        ['session expired', { configured: true, connected: true, usable: false, expires_at: '2020-01-01T00:00:00Z' }],
    ])('keeps reconciliation gated when %s', async (_state, status) => {
        brokerStatus = status;
        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="automatic" />);
        expect(await screen.findByRole('button', { name: 'Reconcile excluding today' })).toBeDisabled();
        expect(apiMock.post).not.toHaveBeenCalled();
    });

    it('fails closed when broker status is unavailable', async () => {
        apiMock.get.mockImplementation((url) => url === '/v1/broker/status'
            ? Promise.reject(new Error('status unavailable')) : Promise.resolve(snapshot));
        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="automatic" />);
        expect(await screen.findByRole('button', { name: 'Reconcile excluding today' })).toBeDisabled();
        expect(await screen.findByText(/Kite status is unavailable/)).toBeInTheDocument();
        expect(apiMock.post).not.toHaveBeenCalled();
    });

    it('checks fresh broker status immediately before posting reconciliation', async () => {
        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="automatic" />);
        const button = await screen.findByRole('button', { name: 'Reconcile excluding today' });
        await waitFor(() => expect(button).toBeEnabled());
        const statusReadsBeforeClick = apiMock.get.mock.calls.filter(([url]) => url === '/v1/broker/status').length;
        brokerStatus = { configured: true, connected: true, usable: false, expires_at: null };
        fireEvent.click(button);
        await waitFor(() => expect(apiMock.get.mock.calls.filter(([url]) => url === '/v1/broker/status')).toHaveLength(statusReadsBeforeClick + 1));
        await waitFor(() => expect(button).toBeDisabled());
        expect(apiMock.post).not.toHaveBeenCalled();
    });

    it('keeps the include-today action available until settlement cases are proven', async () => {
        apiMock.post.mockResolvedValue({ data: { data: {} } });
        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="automatic" />);
        await screen.findAllByText('Attention required');
        expect(await screen.findByRole('button', { name: 'Reconcile excluding today' })).toBeEnabled();
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Reconcile excluding today menu' }));
        const include = await screen.findByRole('menuitem', { name: 'Reconcile including today' });
        fireEvent.click(include);
        await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith('/reconciliation', { ignore_today_transactions: false }, { skipErrorToast: true }));
    });

    it('disables both actions while reconciliation is busy', async () => {
        let resolvePost;
        apiMock.post.mockReturnValue(new Promise((resolve) => { resolvePost = resolve; }));
        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="automatic" />);
        await screen.findAllByText('Attention required');
        await waitFor(() => expect(screen.getByRole('button', { name: 'Reconcile excluding today' })).toBeEnabled());
        fireEvent.click(screen.getByRole('button', { name: 'Reconcile excluding today' }));
        expect(await screen.findByRole('button', { name: 'Reconciling… excluding today' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Sync CNC trades' })).toBeDisabled();
        await act(async () => resolvePost({ data: { data: {} } }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Reconcile excluding today' })).toBeEnabled());
    });
});
