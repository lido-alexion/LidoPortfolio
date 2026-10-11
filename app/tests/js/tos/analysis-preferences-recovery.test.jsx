import React from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import PerformanceTaxPage from '../../../resources/js/src/pages/PerformanceTaxPage.jsx';
import { apiMock } from './helpers/mockApi.js';

vi.mock('../../../resources/js/src/context/PortfolioContext.jsx', () => ({ usePortfolio: () => ({ activePortfolio: { id: 1, name: 'Main' }, portfolios: [{ id: 1, name: 'Main' }] }) }));
function reads() {
    let preferences = { primary_benchmark_id: 1, comparison_benchmark_ids: [], include_in_account_performance: true, include_in_account_tax: false, risk_free_rate: 0.04, annualization_days: 252 };
    apiMock.get.mockImplementation(async (path, options) => ({ data: { data: path === '/analysis/preferences' ? options?.params?.scope === 'account' ? null : preferences
        : path === '/analysis/benchmarks' ? [{ id: 1, name: 'Nifty', return_type: 'price' }, { id: 2, name: 'Sensex', return_type: 'price' }]
            : path === '/analysis/performance' ? { completeness: 'incomplete' }
                : path === '/analysis/account-performance' ? { portfolio_ids: [1], calculation_mode: 'configured' }
                    : path === '/analysis/attribution' ? { method: 'synthetic', limitations: ['Missing inputs'] }
                        : path === '/tax/report' ? { summary: {}, completeness: 'incomplete', limitations: ['Missing evidence'], calculation_mode: 'configured' } : [] } }));
    return next => { preferences = next; };
}
describe('analysis operation recovery', () => {
    it('retains changed preferences after rejection and retries the same reviewed settings', async () => {
        const saved = reads(); let attempts = 0;
        apiMock.put.mockImplementation(async (path, body) => { expect(path).toBe('/analysis/preferences'); attempts++; if (attempts === 1) throw { response: { status: 503 } }; saved(body); return { data: {} }; });
        render(<PerformanceTaxPage />); await screen.findByLabelText('Primary benchmark');
        fireEvent.change(screen.getByLabelText('Primary benchmark'), { target: { value: '2' } });
        fireEvent.change(screen.getByLabelText('Annual risk-free rate'), { target: { value: '0.05' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save settings', exact: true }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Analysis preferences could not be saved');
        expect(screen.getByLabelText('Primary benchmark')).toHaveValue('2'); expect(screen.getByLabelText('Annual risk-free rate')).toHaveValue(0.05);
        fireEvent.click(screen.getByRole('button', { name: 'Save settings', exact: true }));
        await vi.waitFor(() => expect(attempts).toBe(2)); expect(apiMock.put.mock.calls[0][1]).toEqual(apiMock.put.mock.calls[1][1]); expect(apiMock.post).not.toHaveBeenCalled();
    });
    it('announces failed evidence preservation and leaves explicit read-only reporting available for retry', async () => {
        reads(); apiMock.post.mockRejectedValueOnce({ response: { status: 503 } }).mockResolvedValueOnce({ data: {} }); render(<PerformanceTaxPage />);
        const heading = await screen.findByRole('heading', { name: 'Portfolio performance', exact: true }); const actions = within(heading.parentElement);
        fireEvent.click(actions.getByRole('button', { name: 'Preserve evidence' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Calculation evidence could not be preserved');
        expect(heading).toBeVisible(); fireEvent.click(actions.getByRole('button', { name: 'Preserve evidence' }));
        await vi.waitFor(() => expect(apiMock.post).toHaveBeenCalledTimes(2)); expect(apiMock.post.mock.calls[0][1]).toMatchObject({ calculation_type: 'portfolio_performance' }); expect(apiMock.post.mock.calls[0][1]).toEqual(apiMock.post.mock.calls[1][1]); expect(apiMock.put).not.toHaveBeenCalled();
    });
});
