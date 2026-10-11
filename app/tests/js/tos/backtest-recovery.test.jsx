import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import BacktestHistoryPage from '../../../resources/js/src/pages/BacktestHistoryPage';
import BacktestDetailPage from '../../../resources/js/src/pages/BacktestDetailPage';
import PortfolioReplayPanel from '../../../resources/js/src/components/portfolio/PortfolioReplayPanel';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }, getApiErrorMessage: () => 'Unavailable' }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
beforeEach(() => { cleanup(); vi.clearAllMocks(); });
const rejected = () => ({ response: { status: 503 }, message: 'Synthetic unavailable' });

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
});
