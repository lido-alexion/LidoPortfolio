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
            trade_sync_runs: [
                { id: 21, profile_id: 4, trading_date: '2026-11-20', trigger: 'scheduled', status: 'completed', imported_count: 3, matched_count: 1, review_count: 0 },
                { id: 20, profile_id: 4, trading_date: '2026-11-19', trigger: 'gap_detection', status: 'gap_unavailable', imported_count: 0, matched_count: 0, review_count: 0, failure_summary: 'Kite does not provide arbitrary past trading days.' },
                { id: 19, profile_id: 4, trading_date: '2026-11-18', trigger: 'reconnect', status: 'sync_failed', imported_count: 0, matched_count: 0, review_count: 0, failure_summary: 'Sync did not complete.' },
                { id: 18, profile_id: 4, trading_date: '2026-11-17', trigger: 'scheduled', status: 'needs_review', imported_count: 0, matched_count: 0, review_count: 2 },
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

    it('shows per-portfolio daily trade sync counts and exceptions', async () => {
        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="automatic" />);
        expect(await screen.findByRole('heading', { name: 'Kite CNC trade sync activity' })).toBeInTheDocument();
        expect(screen.getAllByText('Scheduled')).toHaveLength(2);
        expect(screen.getByText('Reconnect')).toBeInTheDocument();
        expect(screen.getByText('Historical trades unavailable')).toBeInTheDocument();
        expect(screen.getByText('Sync failed')).toBeInTheDocument();
        expect(screen.getByText('Sync did not complete.')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
        expect(screen.getByText('2 fills require review.')).toBeInTheDocument();
        expect(screen.getAllByText('#4')).toHaveLength(4);
    });

    it('runs the main action excluding today and refreshes status', async () => {
        apiMock.post.mockResolvedValue({ data: { data: {} } });
        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="semi_automatic" />);
        await screen.findAllByText('Attention required');
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(screen.getByText(/Holdings can use transactions through the previous day/)).toBeInTheDocument();
        await waitFor(() => expect(screen.getByRole('button', { name: 'Reconcile excluding today' })).toBeEnabled());
        const mainButton = screen.getByRole('button', { name: 'Reconcile excluding today' });
        expect(mainButton).toBeEnabled();

        fireEvent.click(mainButton);

        await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith('/reconciliation', { ignore_today_transactions: true }, { skipErrorToast: true }));
        expect(apiMock.get.mock.calls.filter(([url]) => url === '/reconciliation')).toHaveLength(2);
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

    it('exposes an accessible dropdown action to include today', async () => {
        apiMock.post.mockResolvedValue({ data: { data: {} } });
        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="automatic" />);
        await screen.findAllByText('Attention required');
        await waitFor(() => expect(screen.getByRole('button', { name: 'Reconcile excluding today' })).toBeEnabled());
        const trigger = screen.getByRole('button', { name: 'Reconcile excluding today menu' });
        expect(trigger).toHaveAttribute('aria-haspopup', 'menu');
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(trigger);
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        const menu = screen.getByRole('menu');
        const includeToday = screen.getByRole('menuitem', { name: 'Reconcile including today' });
        expect(menu).toContainElement(includeToday);
        fireEvent.click(includeToday);
        await waitFor(() => expect(apiMock.post).toHaveBeenCalledWith('/reconciliation', { ignore_today_transactions: false }, { skipErrorToast: true }));
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    });

    it('closes the menu on Escape and outside click', async () => {
        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="automatic" />);
        await screen.findAllByText('Attention required');
        await waitFor(() => expect(screen.getByRole('button', { name: 'Reconcile excluding today' })).toBeEnabled());
        const trigger = screen.getByRole('button', { name: 'Reconcile excluding today menu' });
        fireEvent.click(trigger);
        expect(screen.getByRole('menu')).toBeInTheDocument();
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        fireEvent.click(trigger);
        expect(screen.getByRole('menu')).toBeInTheDocument();
        fireEvent.click(screen.getByText('Broker portfolio reconciliation'));
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    });

    it('disables both actions while reconciliation is busy', async () => {
        let resolvePost;
        apiMock.post.mockReturnValue(new Promise((resolve) => { resolvePost = resolve; }));
        renderWithKiteConnection(<PortfolioReconciliationCard executionMode="automatic" />);
        await screen.findAllByText('Attention required');
        await waitFor(() => expect(screen.getByRole('button', { name: 'Reconcile excluding today' })).toBeEnabled());
        fireEvent.click(screen.getByRole('button', { name: 'Reconcile excluding today' }));
        expect(await screen.findByRole('button', { name: 'Reconciling…' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Reconciling… menu' })).toBeDisabled();
        await act(async () => resolvePost({ data: { data: {} } }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Reconcile excluding today' })).toBeEnabled());
    });
});
