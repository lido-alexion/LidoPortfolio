import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor, act } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import BacktestHistoryPage from '../../../resources/js/src/pages/BacktestHistoryPage';
import BacktestDetailPage from '../../../resources/js/src/pages/BacktestDetailPage';
import PortfolioReplayPanel from '../../../resources/js/src/components/portfolio/PortfolioReplayPanel';
import { showToast } from '../../../resources/js/src/toast';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, getApiErrorMessage: () => 'Unavailable' }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
beforeEach(() => { cleanup(); vi.clearAllMocks(); });
const rejected = () => ({ response: { status: 503 }, message: 'Synthetic unavailable' });
const deferred = () => {
    let resolve; let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
};
const replay = (id, name = `Replay ${id}`) => ({
    id, name, created_at: '2026-10-10T10:00:00Z', period_start: '2026-09-01', period_end: '2026-10-01',
    starting_mode: 'new_simulated', price_method: 'next_close', adverse_slippage_percent: 0.25, status: 'completed',
});

describe('Historical analysis recovery', () => {
    it('does not present failed history as an empty list and retries through a read', async () => {
        let failed = true;
        api.get.mockImplementation(async path => {
            if (path.endsWith('/meta')) return { data: { data: { ranges: [] } } };
            if (failed) throw rejected();
            return { data: { data: { runs: [{ id: 7, name: 'Recovered run', status: 'completed' }] } } };
        });
        render(<MemoryRouter><BacktestHistoryPage /></MemoryRouter>);
        expect(await screen.findByRole('alert')).toHaveTextContent('Backtest history could not be loaded');
        expect(screen.queryByText(/No backtests yet/)).toBeNull();
        failed = false; fireEvent.click(screen.getByRole('button', { name: 'Retry backtest history' }));
        expect(await screen.findByRole('link', { name: 'Recovered run' })).toBeVisible();
        expect(api.post).not.toHaveBeenCalled(); expect(api.delete).not.toHaveBeenCalled();
    });
    it('distinguishes an unavailable detail from a missing run and permits a read retry', async () => {
        api.get.mockRejectedValueOnce(rejected()).mockRejectedValueOnce({ response: { status: 404 } });
        render(<MemoryRouter initialEntries={['/backtests/7']}><Routes><Route path="/backtests/:id" element={<BacktestDetailPage />} /></Routes></MemoryRouter>);
        expect(await screen.findByRole('alert')).toHaveTextContent('Backtest could not be loaded. Please retry.');
        expect(screen.queryByText('Backtest not found.')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Retry loading backtest' }));
        expect(await screen.findByText('Backtest not found.')).toBeVisible();
        expect(screen.queryByRole('button', { name: 'Retry loading backtest' })).toBeNull(); expect(api.get).toHaveBeenCalledTimes(2); expect(api.post).not.toHaveBeenCalled();
    });
    it('associates replay assumptions with their visible labels before readiness is checked', async () => {
        api.get.mockResolvedValue({ data: { data: [] } });
        render(<PortfolioReplayPanel portfolio={{ id: 1, name: 'Main' }} />);
        for (const label of ['Starting state', 'From', 'To', 'Starting cash', 'Fill method', 'Slippage %']) expect(screen.getByLabelText(label, { exact: true })).toBeVisible();
        expect(api.post).not.toHaveBeenCalled();
    });

    it('keeps initial loading distinct from empty and permits an explicit read retry', async () => {
        const initial = deferred();
        api.get.mockReturnValueOnce(initial.promise).mockResolvedValueOnce({ data: { data: [replay(7)] } });
        render(<PortfolioReplayPanel portfolio={{ id: 1, name: 'Main' }} />);
        expect(screen.getByRole('status')).toHaveTextContent('Loading replay history');
        expect(screen.queryByText('No Portfolio Replay runs.')).toBeNull();
        await act(async () => initial.reject(rejected()));
        expect(await screen.findByRole('alert')).toHaveTextContent('Replay history could not be loaded');
        expect(screen.queryByText('No Portfolio Replay runs.')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Retry replay history' }));
        expect(await screen.findByLabelText('Compare Replay 7')).toBeVisible();
        expect(api.get).toHaveBeenCalledTimes(2);
        expect(api.post).not.toHaveBeenCalled();
        expect(api.put).not.toHaveBeenCalled();
        expect(api.delete).not.toHaveBeenCalled();
    });

    it('does not treat a malformed response as confirmed empty history', async () => {
        api.get.mockResolvedValueOnce({ data: { data: null } }).mockResolvedValueOnce({ data: { data: [] } });
        render(<PortfolioReplayPanel portfolio={{ id: 1, name: 'Main' }} />);
        expect(await screen.findByRole('alert')).toHaveTextContent('Replay history could not be loaded');
        expect(screen.queryByText('No Portfolio Replay runs.')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Retry replay history' }));
        expect(await screen.findByText('No Portfolio Replay runs.')).toBeVisible();
        expect(screen.queryByRole('alert')).toBeNull();
        expect(api.get).toHaveBeenCalledTimes(2);
    });

    it('keeps an acknowledged start separate from a failed history read and retries only the read', async () => {
        api.get.mockResolvedValueOnce({ data: { data: [replay(7)] } })
            .mockRejectedValueOnce(rejected())
            .mockResolvedValueOnce({ data: { data: [replay(8, 'Recovered replay')] } });
        api.post.mockResolvedValue({ data: { data: { readiness: null } } });
        render(<PortfolioReplayPanel portfolio={{ id: 1, name: 'Main' }} />);
        expect(await screen.findByLabelText('Compare Replay 7')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Start', exact: true }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Could not refresh replay history');
        expect(screen.getByLabelText('Compare Replay 7')).toBeVisible();
        await waitFor(() => expect(showToast).toHaveBeenCalledWith('Portfolio Replay queued'));
        fireEvent.click(screen.getByRole('button', { name: 'Retry replay history' }));
        expect(await screen.findByLabelText('Compare Replay 8')).toBeVisible();
        expect(screen.queryByLabelText('Compare Replay 7')).toBeNull();
        expect(api.get).toHaveBeenCalledTimes(3);
        expect(api.post).toHaveBeenCalledTimes(1);
        expect(api.delete).not.toHaveBeenCalled();
    });

    it('does not leave a last-known empty list presented as current after a failed refresh', async () => {
        api.get.mockResolvedValueOnce({ data: { data: [] } }).mockRejectedValueOnce(rejected()).mockResolvedValueOnce({ data: { data: [] } });
        api.post.mockResolvedValue({ data: { data: { readiness: null } } });
        render(<PortfolioReplayPanel portfolio={{ id: 1, name: 'Main' }} />);
        expect(await screen.findByText('No Portfolio Replay runs.')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Start', exact: true }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Replay history could not be loaded');
        expect(screen.queryByText('No Portfolio Replay runs.')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Retry replay history' }));
        expect(await screen.findByText('No Portfolio Replay runs.')).toBeVisible();
        expect(api.post).toHaveBeenCalledTimes(1);
        expect(api.get).toHaveBeenCalledTimes(3);
    });

    it('clears prior portfolio evidence and ignores a late detail response after a scope change', async () => {
        const staleDetail = deferred();
        let detailOneReads = 0;
        let listReads = 0;
        api.get.mockImplementation((path) => {
            if (path === '/replays') {
                listReads += 1;
                return Promise.resolve({ data: { data: listReads === 1
                    ? [replay(1, 'Portfolio A run'), replay(2, 'Portfolio A second run')]
                    : [replay(3, 'Portfolio B run')] } });
            }
            if (path === '/replays/1') {
                detailOneReads += 1;
                return detailOneReads === 1
                    ? Promise.resolve({ data: { data: { id: 1, status: 'completed' } } })
                    : staleDetail.promise;
            }
            if (path === '/replays/2') return Promise.resolve({ data: { data: { id: 2, status: 'completed' } } });
            return Promise.reject(new Error(`Unexpected read ${path}`));
        });
        api.post.mockResolvedValue({ data: { data: { compatible: true, runs: [], disclosure: 'Synthetic comparison.' } } });
        const view = render(<PortfolioReplayPanel portfolio={{ id: 1, name: 'Portfolio A' }} />);
        expect(await screen.findByLabelText('Compare Replay 1')).toBeVisible();
        fireEvent.click(screen.getByLabelText('Compare Replay 1'));
        fireEvent.click(screen.getByLabelText('Compare Replay 2'));
        fireEvent.click(screen.getByRole('button', { name: 'Compare selected (2)' }));
        expect(await screen.findByTestId('replay-comparison')).toBeVisible();
        await waitFor(() => expect(screen.getByRole('button', { name: 'Compare selected (2)' })).toBeEnabled());
        fireEvent.click(screen.getAllByRole('button', { name: 'Details', exact: true })[0]);
        expect(await screen.findByTestId('replay-evidence-detail')).toBeVisible();
        await waitFor(() => expect(screen.getAllByRole('button', { name: 'Details', exact: true })[0]).toBeEnabled());
        fireEvent.click(screen.getAllByRole('button', { name: 'Details', exact: true })[0]);
        view.rerender(<PortfolioReplayPanel portfolio={{ id: 3, name: 'Portfolio B' }} />);
        expect(await screen.findByLabelText('Compare Replay 3')).toBeVisible();
        expect(screen.queryByLabelText('Compare Replay 1')).toBeNull();
        expect(screen.queryByTestId('replay-evidence-detail')).toBeNull();
        expect(screen.queryByTestId('replay-comparison')).toBeNull();
        expect(screen.getByRole('button', { name: 'Compare selected (0)' })).toBeDisabled();
        await act(async () => staleDetail.resolve({ data: { data: { id: 1, status: 'completed' } } }));
        expect(screen.queryByTestId('replay-evidence-detail')).toBeNull();
        expect(screen.getByLabelText('Compare Replay 3')).toBeVisible();
        expect(api.post).toHaveBeenCalledTimes(1);
    });

    it('ignores a late list rejection from the previous portfolio scope', async () => {
        const staleList = deferred();
        let listReads = 0;
        api.get.mockImplementation((path) => {
            if (path !== '/replays') return Promise.reject(new Error(`Unexpected read ${path}`));
            listReads += 1;
            return listReads === 1
                ? staleList.promise
                : Promise.resolve({ data: { data: [replay(3, 'Portfolio B run')] } });
        });
        const view = render(<PortfolioReplayPanel portfolio={{ id: 1, name: 'Portfolio A' }} />);
        view.rerender(<PortfolioReplayPanel portfolio={{ id: 3, name: 'Portfolio B' }} />);
        expect(await screen.findByLabelText('Compare Replay 3')).toBeVisible();
        await act(async () => staleList.reject(rejected()));
        expect(screen.getByLabelText('Compare Replay 3')).toBeVisible();
        expect(screen.queryByRole('alert')).toBeNull();
        expect(showToast).not.toHaveBeenCalled();
        expect(api.get).toHaveBeenCalledTimes(2);
    });

    it('does not repopulate history when a prior-scope list succeeds late', async () => {
        const staleList = deferred();
        let listReads = 0;
        api.get.mockImplementation((path) => {
            if (path !== '/replays') return Promise.reject(new Error(`Unexpected read ${path}`));
            listReads += 1;
            return listReads === 1
                ? staleList.promise
                : Promise.resolve({ data: { data: [replay(3, 'Portfolio B run')] } });
        });
        const view = render(<PortfolioReplayPanel portfolio={{ id: 1, name: 'Portfolio A' }} />);
        view.rerender(<PortfolioReplayPanel portfolio={{ id: 3, name: 'Portfolio B' }} />);
        expect(await screen.findByLabelText('Compare Replay 3')).toBeVisible();
        await act(async () => staleList.resolve({ data: { data: [replay(1, 'Portfolio A late run')] } }));
        expect(screen.getByLabelText('Compare Replay 3')).toBeVisible();
        expect(screen.queryByLabelText('Compare Replay 1')).toBeNull();
        expect(screen.queryByRole('alert')).toBeNull();
        expect(api.get).toHaveBeenCalledTimes(2);
    });

    it('ignores a late comparison response from the previous portfolio scope', async () => {
        const staleComparison = deferred();
        let listReads = 0;
        api.get.mockImplementation((path) => {
            if (path !== '/replays') return Promise.reject(new Error(`Unexpected read ${path}`));
            listReads += 1;
            return Promise.resolve({ data: { data: listReads === 1
                ? [replay(1), replay(2)]
                : [replay(3, 'Portfolio B run')] } });
        });
        api.post.mockReturnValueOnce(staleComparison.promise);
        const view = render(<PortfolioReplayPanel portfolio={{ id: 1, name: 'Portfolio A' }} />);
        expect(await screen.findByLabelText('Compare Replay 1')).toBeVisible();
        fireEvent.click(screen.getByLabelText('Compare Replay 1'));
        fireEvent.click(screen.getByLabelText('Compare Replay 2'));
        fireEvent.click(screen.getByRole('button', { name: 'Compare selected (2)' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
        view.rerender(<PortfolioReplayPanel portfolio={{ id: 3, name: 'Portfolio B' }} />);
        expect(await screen.findByLabelText('Compare Replay 3')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Compare selected (0)' })).toBeDisabled();
        await act(async () => staleComparison.resolve({ data: { data: { compatible: true, runs: [], disclosure: 'Stale comparison.' } } }));
        expect(screen.queryByTestId('replay-comparison')).toBeNull();
        expect(screen.getByLabelText('Compare Replay 3')).toBeVisible();
        expect(api.post).toHaveBeenCalledTimes(1);
    });
});
