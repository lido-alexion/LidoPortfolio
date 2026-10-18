import React from 'react';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import AuthContext from '../../../resources/js/src/context/AuthContext.jsx';
import PortfolioSwitcher from '../../../resources/js/src/components/PortfolioSwitcher.jsx';
import { PortfolioProvider } from '../../../resources/js/src/context/PortfolioContext.jsx';
import PerformanceTaxPage from '../../../resources/js/src/pages/PerformanceTaxPage.jsx';
import { TEST_USER } from './fixtures/tosApi.js';
import { apiMock, axiosError, resetApiMock } from './helpers/mockApi.js';

const paths = [
    '/analysis/performance', '/analysis/account-performance', '/analysis/attribution',
    '/tax/report', '/analysis/preferences', '/analysis/preferences', '/analysis/benchmarks',
];
const expectedOptions = (read) => {
    expect(read.options.skipErrorToast).toBe(true);
    if (['/analysis/performance', '/analysis/account-performance', '/analysis/attribution'].includes(read.path)) {
        expect(read.options.params).toMatchObject({ from: expect.any(String), to: expect.any(String) });
    } else if (read.path === '/tax/report') {
        expect(read.options.params.financial_year).toMatch(/^\d{4}-\d{2}$/);
    } else if (read.path === '/analysis/preferences' && read.options.params?.scope === 'account') {
        expect(read.options.params).toEqual({ scope: 'account' });
    } else if (read.path === '/analysis/preferences') {
        expect(read.options.params).toBeUndefined();
    } else {
        expect(read.path).toBe('/analysis/benchmarks');
        expect(read.options.params).toBeUndefined();
    }
};
const toastListeners = new Set();

function responseFor(path, scope) {
    if (path === '/portfolios') return { data: { data: [
        { id: 1, name: 'Main', is_default: true }, { id: 2, name: 'Retirement' },
    ] } };
    if (path === '/stocks') return { data: { data: [] } };
    if (path === '/analysis/preferences') return { data: { data: scope === 'account' ? {
        primary_benchmark_id: 1, comparison_benchmark_ids: [], risk_free_rate: 0.04, annualization_days: 252,
    } : {
        primary_benchmark_id: 1, comparison_benchmark_ids: [], include_in_account_performance: true,
        include_in_account_tax: false, risk_free_rate: 0.04, annualization_days: 252,
    } } };
    if (path === '/analysis/benchmarks') return { data: { data: [{ id: 1, name: 'Nifty', return_type: 'price' }] } };
    if (path === '/analysis/performance') return { data: { data: {
        xirr_percent: null, twr_percent: null, excess_return_percent: null, maximum_drawdown_percent: null,
        benchmark: { name: scope === '1' ? 'Main portfolio evidence' : 'Retirement portfolio evidence' },
        volatility_percent: null, sharpe_ratio: null, completeness: 'incomplete',
    } } };
    if (path === '/analysis/account-performance') return { data: { data: {
        portfolio_ids: [1], calculation_mode: 'configured', xirr_percent: null, twr_percent: null,
    } } };
    if (path === '/analysis/attribution') return { data: { data: { method: 'synthetic', explained_net_realized: 0, reconciliation_residual: null, limitations: [] } } };
    if (path === '/tax/report') return { data: { data: {
        summary: {}, completeness: 'incomplete', limitations: [], calculation_mode: 'configured',
    } } };
    throw new Error(`Unexpected test GET: ${path}`);
}

function renderPage({ strict = false } = {}) {
    const auth = { loading: false, isAuthenticated: true, user: TEST_USER };
    const page = <AuthContext.Provider value={auth}><MemoryRouter><PortfolioProvider>
        <PortfolioSwitcher /><PerformanceTaxPage />
    </PortfolioProvider></MemoryRouter></AuthContext.Provider>;
    return render(strict ? <React.StrictMode>{page}</React.StrictMode> : page);
}

function deferred() {
    let resolve; let reject;
    const promise = new Promise((resolvePromise, rejectPromise) => { resolve = resolvePromise; reject = rejectPromise; });
    return { promise, resolve, reject };
}

describe('PerformanceTaxPage read identity', () => {
    beforeEach(() => {
        resetApiMock();
        sessionStorage.clear();
        localStorage.clear();
    });
    afterEach(() => {
        cleanup();
        toastListeners.forEach((listener) => window.removeEventListener('portfolio-toast', listener));
        toastListeners.clear();
    });

    it('hides a prior portfolio report after a switch while retaining matching account state, then refreshes the new profile explicitly', async () => {
        const reads = [];
        let retirementUnavailable = true;
        apiMock.get.mockImplementation(async (path, options) => {
            const normalizedPath = String(path).split('?')[0];
            if (paths.includes(normalizedPath)) reads.push({ path: normalizedPath, options });
            if (normalizedPath === '/analysis/performance' && sessionStorage.getItem('portfolio_active_id') === '2' && retirementUnavailable) {
                throw axiosError('Synthetic retirement analysis unavailable', { status: 503 });
            }
            return responseFor(normalizedPath, normalizedPath === '/analysis/preferences' ? options?.params?.scope : sessionStorage.getItem('portfolio_active_id'));
        });
        renderPage();
        await screen.findByText(/Benchmark: Main portfolio evidence ·/);
        expect(reads.slice(0, 7).map((read) => read.path)).toEqual(paths);
        reads.slice(0, 7).forEach(expectedOptions);
        fireEvent.change(screen.getByLabelText('Account risk-free rate'), { target: { value: '0.05' } });
        fireEvent.change(screen.getByLabelText('Annual risk-free rate'), { target: { value: '0.05' } });

        fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
        fireEvent.click(screen.getByRole('button', { name: 'Retirement', exact: true }));
        await screen.findByText('Portfolio performance is unavailable for the current portfolio and date range.');
        expect(screen.queryByText(/Benchmark: Main portfolio evidence ·/)).not.toBeInTheDocument();
        expect(screen.getByLabelText('Account risk-free rate')).toHaveValue(0.05);
        expect(screen.queryByLabelText('Annual risk-free rate')).not.toBeInTheDocument();
        expect(screen.getByText(/Account performance · configured/)).toBeVisible();
        expect(reads.slice(7, 14).map((read) => read.path)).toEqual(paths);
        reads.slice(7, 14).forEach(expectedOptions);

        retirementUnavailable = false;
        fireEvent.click(screen.getByRole('button', { name: 'Calculate', exact: true }));
        await screen.findByText(/Benchmark: Retirement portfolio evidence ·/);
        expect(screen.queryByText(/Benchmark: Main portfolio evidence ·/)).not.toBeInTheDocument();
        await waitFor(() => expect(screen.getByLabelText('Annual risk-free rate')).toHaveValue(0.04));
        expect(reads.slice(14, 21).map((read) => read.path)).toEqual(paths);
        reads.slice(14, 21).forEach(expectedOptions);
        expect(apiMock.post).not.toHaveBeenCalled();
        expect(apiMock.put).not.toHaveBeenCalled();
        expect(apiMock.patch).not.toHaveBeenCalled();
        expect(apiMock.delete).not.toHaveBeenCalled();
    });

    it('labels a same-key failure, preserves the account draft, and rehydrates only after explicit Calculate succeeds', async () => {
        let unavailable = false;
        apiMock.get.mockImplementation(async (path, options) => {
            const normalizedPath = String(path).split('?')[0];
            if (normalizedPath === '/analysis/performance' && unavailable) throw axiosError('Synthetic analysis unavailable', { status: 503 });
            return responseFor(normalizedPath, normalizedPath === '/analysis/preferences' ? options?.params?.scope : sessionStorage.getItem('portfolio_active_id'));
        });
        renderPage();
        await screen.findByLabelText('Account risk-free rate');
        fireEvent.change(screen.getByLabelText('Account risk-free rate'), { target: { value: '0.05' } });
        fireEvent.change(screen.getByLabelText('Annual risk-free rate'), { target: { value: '0.05' } });
        unavailable = true;
        fireEvent.click(screen.getByRole('button', { name: 'Calculate', exact: true }));
        await screen.findByText('Some current analysis reads are unavailable. Any retained values are last-confirmed and shown only for matching profile and input keys.');
        expect(screen.getByLabelText('Account risk-free rate')).toHaveValue(0.05);
        expect(screen.getByLabelText('Annual risk-free rate')).toHaveValue(0.05);
        expect(screen.getByText(/Synthetic analysis unavailable/)).toBeVisible();
        unavailable = false;
        fireEvent.click(screen.getByRole('button', { name: 'Calculate', exact: true }));
        await waitFor(() => expect(screen.getByLabelText('Account risk-free rate')).toHaveValue(0.04));
        expect(screen.getByLabelText('Annual risk-free rate')).toHaveValue(0.04);
        expect(apiMock.put).not.toHaveBeenCalled();
    });

    it('keeps the tax evidence panel instance and its draft across valid profile, range, and FY changes', async () => {
        apiMock.get.mockImplementation(async (path, options) => responseFor(String(path).split('?')[0], options?.params?.scope ?? sessionStorage.getItem('portfolio_active_id')));
        renderPage();
        const panel = await screen.findByRole('region', { name: 'Record tax evidence', exact: true });
        fireEvent.change(screen.getByLabelText('Dividend amount'), { target: { value: '125' } });
        fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
        fireEvent.click(screen.getByRole('button', { name: 'Retirement', exact: true }));
        await screen.findByText(/Benchmark: Retirement portfolio evidence ·/);
        expect(screen.getByRole('region', { name: 'Record tax evidence', exact: true })).toBe(panel);
        expect(screen.getByLabelText('Dividend amount')).toHaveValue(125);

        const toInput = screen.getByLabelText('Performance to');
        const earlierDate = new Date(`${toInput.value}T12:00:00`);
        earlierDate.setDate(earlierDate.getDate() - 1);
        fireEvent.change(toInput, { target: { value: earlierDate.toISOString().slice(0, 10) } });
        await waitFor(() => expect(screen.getByText(/Benchmark: Retirement portfolio evidence ·/)).toBeVisible());
        expect(screen.getByRole('region', { name: 'Record tax evidence', exact: true })).toBe(panel);
        expect(screen.getByLabelText('Dividend amount')).toHaveValue(125);

        fireEvent.change(screen.getByLabelText('Tax financial year'), { target: { value: '2025-26' } });
        await waitFor(() => expect(screen.getByText('Account Tax · FY 2025-26')).toBeVisible());
        expect(screen.getByRole('region', { name: 'Record tax evidence', exact: true })).toBe(panel);
        expect(screen.getByLabelText('Dividend amount')).toHaveValue(125);
        expect(apiMock.post).not.toHaveBeenCalled();
        expect(apiMock.put).not.toHaveBeenCalled();
    });

    it('keeps account preference drafts across valid range, FY, and what-if key changes while preserving request ID order', async () => {
        const reads = [];
        apiMock.get.mockImplementation(async (path, options) => {
            const normalizedPath = String(path).split('?')[0];
            if (paths.includes(normalizedPath)) reads.push({ path: normalizedPath, options });
            return responseFor(normalizedPath, normalizedPath === '/analysis/preferences' ? options?.params?.scope : sessionStorage.getItem('portfolio_active_id'));
        });
        renderPage();
        await screen.findByLabelText('Account risk-free rate');
        fireEvent.change(screen.getByLabelText('Account risk-free rate'), { target: { value: '0.05' } });
        fireEvent.change(screen.getByLabelText('Annual risk-free rate'), { target: { value: '0.05' } });

        const toInput = screen.getByLabelText('Performance to');
        const earlierDate = new Date(`${toInput.value}T12:00:00`);
        earlierDate.setDate(earlierDate.getDate() - 1);
        fireEvent.change(toInput, { target: { value: earlierDate.toISOString().slice(0, 10) } });
        await waitFor(() => expect(reads.length).toBe(14));
        expect(screen.getByLabelText('Account risk-free rate')).toHaveValue(0.05);
        expect(screen.getByLabelText('Annual risk-free rate')).toHaveValue(0.05);

        fireEvent.change(screen.getByLabelText('Tax financial year'), { target: { value: '2025-26' } });
        await waitFor(() => expect(reads.length).toBe(21));
        expect(screen.getByLabelText('Account risk-free rate')).toHaveValue(0.05);
        expect(screen.getByLabelText('Annual risk-free rate')).toHaveValue(0.05);
        expect(screen.getByText('Account Tax · FY 2025-26')).toBeVisible();

        fireEvent.click(screen.getByLabelText('What-if portfolio selection (does not change configured inclusion)'));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Retirement' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Main' }));
        await waitFor(() => expect(reads.length).toBe(42));
        const finalBatch = reads.slice(35, 42);
        expect(finalBatch.map(read => read.path)).toEqual(paths);
        expect(finalBatch[1].options.params.portfolio_ids).toEqual([2, 1]);
        expect(finalBatch[3].options.params).toEqual({ financial_year: '2025-26', portfolio_ids: [2, 1] });
        finalBatch.forEach(expectedOptions);
        expect(screen.getByLabelText('Account risk-free rate')).toHaveValue(0.05);
        expect(screen.getByLabelText('Annual risk-free rate')).toHaveValue(0.05);
        const xirrCard = screen.getByText('XIRR', { exact: true }).closest('.card');
        expect(xirrCard).not.toBeNull();
        expect(within(xirrCard).getByText('Incomplete', { exact: true })).toBeVisible();

        const settledCount = reads.length;
        const fromInput = screen.getByLabelText('Performance from');
        const originalFrom = fromInput.value;
        fireEvent.change(fromInput, { target: { value: screen.getByLabelText('Performance to').value } });
        expect(screen.getByRole('button', { name: 'Calculate', exact: true })).toBeDisabled();
        expect(screen.queryByRole('region', { name: 'Record tax evidence', exact: true })).not.toBeInTheDocument();
        expect(reads).toHaveLength(settledCount);
        fireEvent.change(fromInput, { target: { value: originalFrom } });
        await waitFor(() => expect(reads.length).toBe(settledCount + 7));

        const afterRangeRestore = reads.length;
        fireEvent.change(screen.getByLabelText('Tax financial year'), { target: { value: 'invalid' } });
        expect(screen.getByRole('button', { name: 'Calculate', exact: true })).toBeDisabled();
        expect(screen.queryByRole('region', { name: 'Record tax evidence', exact: true })).not.toBeInTheDocument();
        expect(reads).toHaveLength(afterRangeRestore);
        fireEvent.change(screen.getByLabelText('Tax financial year'), { target: { value: '2025-26' } });
        await waitFor(() => expect(reads.length).toBe(afterRangeRestore + 7));
        expect(screen.getByRole('region', { name: 'Record tax evidence', exact: true })).toBeVisible();
        expect(reads.length % paths.length).toBe(0);
        for (let index = 0; index < reads.length; index += paths.length) {
            const batch = reads.slice(index, index + paths.length);
            expect(batch.map((read) => read.path)).toEqual(paths);
            batch.forEach(expectedOptions);
        }
        expect(apiMock.post).not.toHaveBeenCalled(); expect(apiMock.put).not.toHaveBeenCalled();
    });

    it('does not present an old A report as fresh when returning to A after a failed B read', async () => {
        const returnedA = deferred(); let holdReturnedA = false; let returnedAStarted = false;
        apiMock.get.mockImplementation(async (path, options) => {
            const normalizedPath = String(path).split('?')[0];
            if (normalizedPath === '/analysis/performance') {
                const profile = sessionStorage.getItem('portfolio_active_id');
                if (profile === '2') throw axiosError('Synthetic B read failure', { status: 503 });
                if (profile === '1' && holdReturnedA) {
                    returnedAStarted = true;
                    return returnedA.promise;
                }
            }
            return responseFor(normalizedPath, normalizedPath === '/analysis/preferences' ? options?.params?.scope : sessionStorage.getItem('portfolio_active_id'));
        });
        renderPage();
        await screen.findByText(/Benchmark: Main portfolio evidence ·/);

        fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
        fireEvent.click(screen.getByRole('button', { name: 'Retirement', exact: true }));
        await screen.findByText('Portfolio performance is unavailable for the current portfolio and date range.');
        expect(screen.queryByText(/Benchmark: Main portfolio evidence ·/)).not.toBeInTheDocument();

        holdReturnedA = true;
        fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
        fireEvent.click(screen.getByRole('button', { name: 'Main', exact: true }));
        await waitFor(() => expect(returnedAStarted).toBe(true));
        expect(screen.getByRole('status')).toHaveTextContent(/Refreshing analysis/);
        expect(screen.queryByText(/Benchmark: Main portfolio evidence ·/)).not.toBeInTheDocument();

        await act(async () => {
            const current = responseFor('/analysis/performance', '1');
            current.data.data.benchmark.name = 'Fresh Main after B';
            returnedA.resolve(current);
            await Promise.resolve();
        });
        await screen.findByText(/Benchmark: Fresh Main after B ·/);
        expect(apiMock.post).not.toHaveBeenCalled(); expect(apiMock.put).not.toHaveBeenCalled();
    });

    it('does not present old A attribution while the returning A batch is pending after B fails', async () => {
        const returnedA = deferred(); let holdReturnedA = false; let returnedAPerformanceStarted = false;
        let attributionReads = 0;
        apiMock.get.mockImplementation(async (path, options) => {
            const normalizedPath = String(path).split('?')[0];
            const profile = sessionStorage.getItem('portfolio_active_id');
            if (normalizedPath === '/analysis/performance') {
                if (profile === '2') throw axiosError('Synthetic B performance unavailable', { status: 503 });
                if (profile === '1' && holdReturnedA) {
                    returnedAPerformanceStarted = true;
                    return returnedA.promise;
                }
            }
            if (normalizedPath === '/analysis/attribution') {
                attributionReads += 1;
                const result = responseFor(normalizedPath, profile);
                result.data.data.method = profile === '1'
                    ? (attributionReads === 1 ? 'A confirmed attribution' : 'Fresh A attribution')
                    : 'B attribution';
                return result;
            }
            return responseFor(normalizedPath, normalizedPath === '/analysis/preferences' ? options?.params?.scope : profile);
        });
        renderPage();
        await screen.findByText(/Method: A confirmed attribution/);

        fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
        fireEvent.click(screen.getByRole('button', { name: 'Retirement', exact: true }));
        await screen.findByText('Portfolio performance is unavailable for the current portfolio and date range.');
        expect(screen.queryByText(/Method: A confirmed attribution/)).not.toBeInTheDocument();

        holdReturnedA = true;
        fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
        fireEvent.click(screen.getByRole('button', { name: 'Main', exact: true }));
        await waitFor(() => expect(returnedAPerformanceStarted).toBe(true));
        expect(screen.getByRole('status')).toHaveTextContent(/Refreshing analysis/);
        expect(screen.queryByText(/Method: A confirmed attribution/)).not.toBeInTheDocument();

        await act(async () => {
            const current = responseFor('/analysis/performance', '1');
            current.data.data.benchmark.name = 'Fresh A after B failure';
            returnedA.resolve(current);
            await Promise.resolve();
        });
        await screen.findByText(/Method: Fresh A attribution/);
        expect(screen.getByText(/Benchmark: Fresh A after B failure ·/)).toBeVisible();
        expect(apiMock.post).not.toHaveBeenCalled();
        expect(apiMock.put).not.toHaveBeenCalled();
        expect(apiMock.patch).not.toHaveBeenCalled();
        expect(apiMock.delete).not.toHaveBeenCalled();
    });

    it('ignores a pending read failure after the page unmounts', async () => {
        const pending = deferred(); let pendingStarted = false; const toastEvents = [];
        const onToast = (event) => toastEvents.push(event.detail);
        toastListeners.add(onToast);
        window.addEventListener('portfolio-toast', onToast);
        apiMock.get.mockImplementation(async (path, options) => {
            const normalizedPath = String(path).split('?')[0];
            if (normalizedPath === '/analysis/performance' && pendingStarted) return pending.promise;
            return responseFor(normalizedPath, normalizedPath === '/analysis/preferences' ? options?.params?.scope : sessionStorage.getItem('portfolio_active_id'));
        });
        const view = renderPage();
        await screen.findByText(/Benchmark: Main portfolio evidence ·/);
        pendingStarted = true;
        const toInput = screen.getByLabelText('Performance to');
        const earlierDate = new Date(`${toInput.value}T12:00:00`);
        earlierDate.setDate(earlierDate.getDate() - 1);
        fireEvent.change(toInput, { target: { value: earlierDate.toISOString().slice(0, 10) } });
        view.unmount();
        await act(async () => {
            pending.reject(axiosError('Synthetic unmounted read failure', { status: 503 }));
            await Promise.resolve();
        });
        expect(toastEvents).toEqual([]);
        window.removeEventListener('portfolio-toast', onToast);
        toastListeners.delete(onToast);
    });

    it.each([
        ['success while B is pending', 'error after returning to A'],
        ['error while B is pending', 'success after returning to A'],
    ])('ignores obsolete A success/error during B and A→B→A requests (%s; %s)', async (duringB, afterAba) => {
        const firstOldA = deferred(); const secondOldA = deferred(); const currentA = deferred(); const pendingB = deferred(); let holdOldA = false;
        let heldARequests = 0; let currentAStarted = false; let pendingBStarted = false;
        apiMock.get.mockImplementation(async (path, options) => {
            const normalizedPath = String(path).split('?')[0];
            if (normalizedPath === '/analysis/performance') {
                const profile = sessionStorage.getItem('portfolio_active_id');
                if (profile === '1' && holdOldA && heldARequests < 2) {
                    heldARequests += 1;
                    return heldARequests === 1 ? firstOldA.promise : secondOldA.promise;
                }
                if (profile === '1' && holdOldA && !currentAStarted) {
                    currentAStarted = true;
                    return currentA.promise;
                }
                if (profile === '2') { pendingBStarted = true; return pendingB.promise; }
            }
            return responseFor(normalizedPath, normalizedPath === '/analysis/preferences' ? options?.params?.scope : sessionStorage.getItem('portfolio_active_id'));
        });
        renderPage({ strict: true });
        await screen.findByText(/Benchmark: Main portfolio evidence ·/);
        holdOldA = true;
        const toInput = screen.getByLabelText('Performance to');
        const changedTo = new Date(`${toInput.value}T12:00:00`);
        changedTo.setDate(changedTo.getDate() - 1);
        const obsoleteTo = changedTo.toISOString().slice(0, 10);
        fireEvent.change(toInput, { target: { value: obsoleteTo } });
        expect(screen.getByRole('status')).toHaveTextContent(/Loading analysis|Refreshing analysis/);
        expect(screen.queryByText(/Benchmark: Main portfolio evidence ·/)).not.toBeInTheDocument();
        await waitFor(() => expect(heldARequests).toBe(1));
        const fromInput = screen.getByLabelText('Performance from');
        const earlierFrom = new Date(`${obsoleteTo}T12:00:00`);
        earlierFrom.setDate(earlierFrom.getDate() - 1);
        fireEvent.change(fromInput, { target: { value: earlierFrom.toISOString().slice(0, 10) } });
        await waitFor(() => expect(heldARequests).toBe(2));
        fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
        fireEvent.click(screen.getByRole('button', { name: 'Retirement', exact: true }));
        await waitFor(() => expect(pendingBStarted).toBe(true));

        await act(async () => {
            if (duringB === 'success while B is pending') {
                const obsolete = responseFor('/analysis/performance', '1');
                obsolete.data.data.benchmark.name = 'Obsolete A while B is pending';
                firstOldA.resolve(obsolete);
            } else firstOldA.reject(axiosError('Synthetic obsolete A error while B is pending', { status: 503 }));
            await Promise.resolve();
        });
        expect(screen.getByRole('status')).toHaveTextContent('Refreshing analysis');
        expect(screen.queryByText(/Obsolete A while B is pending/)).not.toBeInTheDocument();
        expect(screen.queryByText(/Synthetic obsolete A error while B is pending/)).not.toBeInTheDocument();

        fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
        fireEvent.click(screen.getByRole('button', { name: 'Main', exact: true }));
        await waitFor(() => expect(currentAStarted).toBe(true));
        await act(async () => {
            if (afterAba === 'success after returning to A') {
                const obsolete = responseFor('/analysis/performance', '1');
                obsolete.data.data.benchmark.name = 'Obsolete A after returning to A';
                secondOldA.resolve(obsolete);
            } else secondOldA.reject(axiosError('Synthetic obsolete A error after returning to A', { status: 503 }));
            await Promise.resolve();
        });
        expect(screen.getByRole('status')).toHaveTextContent('Refreshing analysis');
        expect(screen.queryByText(/Obsolete A after returning to A/)).not.toBeInTheDocument();
        expect(screen.queryByText(/Synthetic obsolete A error after returning to A/)).not.toBeInTheDocument();
        await act(async () => {
            const current = responseFor('/analysis/performance', '1');
            current.data.data.benchmark.name = 'Current Main range';
            currentA.resolve(current);
            await Promise.resolve();
        });
        await screen.findByText(/Benchmark: Current Main range ·/);
        await act(async () => {
            const late = responseFor('/analysis/performance', '2');
            late.data.data.benchmark.name = 'Retirement late result';
            pendingB.resolve(late);
            await Promise.resolve();
        });
        expect(screen.getByText(/Benchmark: Current Main range ·/)).toBeVisible();
        expect(screen.queryByText(/Retirement late result/)).not.toBeInTheDocument();
        expect(apiMock.post).not.toHaveBeenCalled();
        expect(apiMock.put).not.toHaveBeenCalled();
    });
});
