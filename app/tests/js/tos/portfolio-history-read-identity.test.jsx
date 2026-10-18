import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import AuthContext from '../../../resources/js/src/context/AuthContext';
import { PortfolioProvider } from '../../../resources/js/src/context/PortfolioContext';
import PortfolioSwitcher from '../../../resources/js/src/components/PortfolioSwitcher';
import HistoricalHoldingsPage from '../../../resources/js/src/pages/HistoricalHoldingsPage';
import PortfolioSnapshotsPage from '../../../resources/js/src/pages/PortfolioSnapshotsPage';

vi.mock('../../../resources/js/src/api', () => ({
    default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
    getApiErrorMessage: (error, fallback) => error?.message || fallback,
}));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/components/portfolio/PortfolioSnapshotGrowthChart', () => ({
    default: ({ snapshots }) => <div data-testid="snapshot-chart">{snapshots.length} chart row(s)</div>,
}));

const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};
const selectedPortfolios = [
    { id: 1, name: 'Main', is_default: true },
    { id: 2, name: 'Research', is_default: false },
];
const historical = (asOf, symbol, invested, cash = 500) => ({
    as_of: asOf,
    holdings: symbol ? [{ stock_id: symbol === 'TCS' ? 10 : 20, symbol, name: symbol, quantity: 2, avg_buy_price: invested / 2, invested_amount: invested, price_available: false, as_of_price: null, price_as_of: null, market_value: null, unrealized_profit: null }] : [],
    warnings: [],
    totals: { invested_value: invested, market_value: null, cash_balance: cash, total_value: symbol == null ? cash : null, unrealized_profit: null, unrealized_gain_percent: null, valuation_complete: symbol == null },
    completeness: { valuation_complete: symbol == null, total_value_complete: symbol == null, missing_price_count: symbol ? 2 : 0, holding_count: symbol ? 1 : 0 },
});
const snapshot = (id, date, value) => ({ id: String(id), snapshot_date: date, portfolio_value: String(value), invested_value: String(value - 100) });
const snapshotPayload = (rows) => ({ snapshots: rows, meta: { count: rows.length, from_date: rows[0]?.snapshot_date ?? null, to_date: rows.at(-1)?.snapshot_date ?? null, limit: rows.length || 365 } });
const rejected = () => Object.assign(new Error('Synthetic read error'), { response: { status: 503 } });
const rowContaining = (text) => screen.getAllByRole('row').find((row) => row.textContent.includes(text));

function renderWithProvider(page, { strictMode = false } = {}) {
    const content = (
        <MemoryRouter>
            <AuthContext.Provider value={{ isAuthenticated: true, user: { id: 1, default_portfolio_id: 1 }, loading: false }}>
                <PortfolioProvider>
                    <PortfolioSwitcher />
                    {page}
                </PortfolioProvider>
            </AuthContext.Provider>
        </MemoryRouter>
    );
    return render(strictMode ? <React.StrictMode>{content}</React.StrictMode> : content);
}

beforeEach(() => {
    cleanup();
    vi.resetAllMocks();
    localStorage.clear();
    sessionStorage.clear();
});
afterEach(() => cleanup());

it('waits for the real provider, hides late A data under B/date, and confirms an empty date only after retry', async () => {
    const portfolios = deferred();
    const aRead = deferred();
    const bRead = deferred();
    const requested = [];
    let laterDateReads = 0;
    api.get.mockImplementation((path, options) => {
        if (path === '/portfolios') return portfolios.promise;
        if (path === '/portfolio/historical-holdings') {
            const profile = sessionStorage.getItem('portfolio_active_id');
            const asOf = options.params.as_of;
            requested.push({ profile, asOf });
            if (profile === '1') return aRead.promise;
            if (asOf === '2026-10-01') {
                laterDateReads += 1;
                return laterDateReads === 1 ? Promise.reject(rejected()) : Promise.resolve({ data: historical(asOf, null, 0, 0) });
            }
            return bRead.promise;
        }
        throw new Error(`Unexpected GET ${path}`);
    });

    renderWithProvider(<HistoricalHoldingsPage />, { strictMode: true });
    expect(screen.getByRole('status')).toHaveTextContent('Portfolio selection is not confirmed');
    expect(requested).toEqual([]);
    await act(async () => portfolios.resolve({ data: { data: selectedPortfolios } }));
    await waitFor(() => expect(requested).toEqual([{ profile: '1', asOf: expect.any(String) }]));
    expect(screen.getByText(/Invested:/)).toHaveTextContent('Unavailable');

    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(await screen.findByRole('button', { name: 'Research', exact: true }));
    await waitFor(() => expect(requested).toHaveLength(2));
    expect(requested[1]).toMatchObject({ profile: '2', asOf: requested[0].asOf });
    await act(async () => aRead.resolve({ data: historical(requested[0].asOf, 'TCS', 1000) }));
    expect(rowContaining('TCS')).toBeUndefined();
    expect(screen.getByText(/Invested:/)).toHaveTextContent('Unavailable');
    expect(screen.getByRole('status')).toHaveTextContent('Loading historical holdings');

    await act(async () => bRead.resolve({ data: historical(requested[1].asOf, 'INFY', 2000, 700) }));
    await waitFor(() => expect(rowContaining('INFY')).toBeVisible());
    expect(rowContaining('TCS')).toBeUndefined();
    expect(screen.getByText(/Invested:/)).toHaveTextContent('₹ 2,000.00');

    fireEvent.change(screen.getByLabelText('As-of date'), { target: { value: '01-Oct-2026' } });
    await waitFor(() => expect(requested.at(-1)).toEqual({ profile: '2', asOf: '2026-10-01' }));
    await screen.findByRole('alert');
    expect(rowContaining('INFY')).toBeUndefined();
    expect(screen.getByText(/Invested:/)).toHaveTextContent('Unavailable');
    expect(screen.getByText(/Cash:/)).toHaveTextContent('Unavailable');
    expect(screen.queryByText(/No open holdings on this date/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh', exact: true }));
    await screen.findByText(/No open holdings on this date/);
    expect(requested.at(-1)).toEqual({ profile: '2', asOf: '2026-10-01' });
    expect(screen.getByText(/Invested:/)).toHaveTextContent('Invested: ₹ 0.00');
    expect(api.post).not.toHaveBeenCalled();
});

it('ignores an old-scope rejection while the newly selected profile read is still pending', async () => {
    const oldRead = deferred();
    const currentRead = deferred();
    const requested = [];
    api.get.mockImplementation((path, options) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: selectedPortfolios } });
        if (path === '/portfolio/historical-holdings') {
            const profile = sessionStorage.getItem('portfolio_active_id');
            requested.push({ profile, asOf: options.params.as_of });
            return profile === '1' ? oldRead.promise : currentRead.promise;
        }
        throw new Error(`Unexpected GET ${path}`);
    });
    renderWithProvider(<HistoricalHoldingsPage />);
    await waitFor(() => expect(requested).toEqual([{ profile: '1', asOf: expect.any(String) }]));

    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(await screen.findByRole('button', { name: 'Research', exact: true }));
    await waitFor(() => expect(requested).toEqual([
        { profile: '1', asOf: expect.any(String) },
        { profile: '2', asOf: requested[0].asOf },
    ]));
    await act(async () => oldRead.reject(rejected()));

    expect(screen.getByRole('status')).toHaveTextContent('Loading historical holdings');
    expect(screen.queryByRole('alert')).toBeNull();
    expect(rowContaining('TCS')).toBeUndefined();
    await act(async () => currentRead.resolve({ data: historical(requested[1].asOf, 'INFY', 2000) }));
    await waitFor(() => expect(rowContaining('INFY')).toBeVisible());
    expect(screen.queryByRole('alert')).toBeNull();
    expect(api.post).not.toHaveBeenCalled();
});

it('does not let an old A response win after an A→B→A profile transition', async () => {
    const firstA = deferred();
    const pendingB = deferred();
    const currentA = deferred();
    const requested = [];
    let aReads = 0;
    api.get.mockImplementation((path, options) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: selectedPortfolios } });
        if (path === '/portfolio/historical-holdings') {
            const profile = sessionStorage.getItem('portfolio_active_id');
            requested.push({ profile, asOf: options.params.as_of });
            if (profile === '1') {
                aReads += 1;
                return aReads === 1 ? firstA.promise : currentA.promise;
            }
            return pendingB.promise;
        }
        throw new Error(`Unexpected GET ${path}`);
    });
    renderWithProvider(<HistoricalHoldingsPage />);
    await waitFor(() => expect(requested).toHaveLength(1));

    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(await screen.findByRole('button', { name: 'Research', exact: true }));
    await waitFor(() => expect(requested).toHaveLength(2));
    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(await screen.findByRole('button', { name: 'Main', exact: true }));
    await waitFor(() => expect(requested).toHaveLength(3));
    expect(requested.map((item) => item.profile)).toEqual(['1', '2', '1']);

    await act(async () => firstA.resolve({ data: historical(requested[0].asOf, 'OLD', 900) }));
    expect(screen.getByRole('status')).toHaveTextContent('Loading historical holdings');
    expect(rowContaining('OLD')).toBeUndefined();
    expect(screen.getByText(/Invested:/)).toHaveTextContent('Unavailable');

    await act(async () => currentA.resolve({ data: historical(requested[2].asOf, 'FRESH', 1100) }));
    await waitFor(() => expect(rowContaining('FRESH')).toBeVisible());
    expect(rowContaining('OLD')).toBeUndefined();

    await act(async () => pendingB.resolve({ data: historical(requested[1].asOf, 'INFY', 2000) }));
    expect(rowContaining('FRESH')).toBeVisible();
    expect(rowContaining('INFY')).toBeUndefined();
    expect(api.post).not.toHaveBeenCalled();
});

it('retains a same-date last-good holdings result with a separate stale label and retries only the read', async () => {
    let reads = 0;
    api.get.mockImplementation(async (path, options) => {
        if (path === '/portfolios') return { data: { data: [selectedPortfolios[0]] } };
        if (path === '/portfolio/historical-holdings') {
            reads += 1;
            if (reads === 2) throw rejected();
            return { data: historical(options.params.as_of, reads === 1 ? 'TCS' : 'INFY', reads === 1 ? 1000 : 1500) };
        }
        throw new Error(`Unexpected GET ${path}`);
    });
    renderWithProvider(<HistoricalHoldingsPage />);
    await waitFor(() => expect(rowContaining('TCS')).toBeVisible());

    fireEvent.click(screen.getByRole('button', { name: 'Refresh', exact: true }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Showing the last successfully loaded holdings for this same date');
    expect(rowContaining('TCS')).toBeVisible();
    expect(rowContaining('INFY')).toBeUndefined();

    fireEvent.click(screen.getByRole('button', { name: 'Refresh', exact: true }));
    await waitFor(() => expect(rowContaining('INFY')).toBeVisible());
    expect(screen.queryByRole('alert')).toBeNull();
    expect(reads).toBe(3);
    expect(api.post).not.toHaveBeenCalled();
});

it('keeps invalid date text from presenting the prior date result as current', async () => {
    api.get.mockImplementation(async (path, options) => {
        if (path === '/portfolios') return { data: { data: [selectedPortfolios[0]] } };
        if (path === '/portfolio/historical-holdings') return { data: historical(options.params.as_of, 'TCS', 1000) };
        throw new Error(`Unexpected GET ${path}`);
    });
    renderWithProvider(<HistoricalHoldingsPage />);
    await waitFor(() => expect(rowContaining('TCS')).toBeVisible());
    const reads = api.get.mock.calls.filter(([path]) => path === '/portfolio/historical-holdings').length;
    fireEvent.change(screen.getByLabelText('As-of date'), { target: { value: 'not a date' } });
    expect(rowContaining('TCS')).toBeUndefined();
    expect(screen.getByText(/Invested:/)).toHaveTextContent('Unavailable');
    expect(screen.getByRole('button', { name: 'Refresh', exact: true })).toBeDisabled();
    expect(api.get.mock.calls.filter(([path]) => path === '/portfolio/historical-holdings')).toHaveLength(reads);
});

it('fences snapshot ranges, distinguishes failed reads from confirmed empty history, and preserves same-range rows as stale', async () => {
    const rangeFailure = deferred();
    let rangeReads = 0;
    api.get.mockImplementation((path, options) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: [selectedPortfolios[0]] } });
        if (path === '/portfolio/snapshots') {
            if (options.params.limit === 90) {
                rangeReads += 1;
                return rangeReads === 1 ? rangeFailure.promise : Promise.resolve({ data: snapshotPayload([]) });
            }
            return Promise.resolve({ data: snapshotPayload([snapshot(1, '2026-10-10', 120000)]) });
        }
        throw new Error(`Unexpected GET ${path}`);
    });
    renderWithProvider(<PortfolioSnapshotsPage />);
    expect(await screen.findByLabelText('Select snapshot 2026-10-10')).toBeVisible();

    fireEvent.click(screen.getByRole('button', { name: '90 days', exact: true }));
    await waitFor(() => expect(rangeReads).toBe(1));
    expect(screen.queryByLabelText('Select snapshot 2026-10-10')).toBeNull();
    expect(screen.getAllByText('Unavailable', { selector: '.fw-semibold' })).toHaveLength(4);
    expect(screen.queryByText(/No portfolio snapshots yet/)).toBeNull();
    await act(async () => rangeFailure.reject(rejected()));
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load portfolio snapshots');
    expect(screen.queryByText(/No portfolio snapshots yet/)).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Refresh', exact: true }));
    expect(await screen.findByText(/No portfolio snapshots yet/)).toBeVisible();
    expect(screen.getByText('0 snapshot(s)')).toBeVisible();
    expect(api.post).not.toHaveBeenCalled();
});

it('shows same-key snapshot rows as stale through a failed refresh and replaces them after explicit retry', async () => {
    let snapshotReads = 0;
    api.get.mockImplementation(async (path) => {
        if (path === '/portfolios') return { data: { data: [selectedPortfolios[0]] } };
        if (path === '/portfolio/snapshots') {
            snapshotReads += 1;
            if (snapshotReads === 2) throw rejected();
            return { data: snapshotPayload([snapshot(snapshotReads, snapshotReads === 1 ? '2026-10-10' : '2026-10-11', snapshotReads === 1 ? 120000 : 130000)]) };
        }
        throw new Error(`Unexpected GET ${path}`);
    });
    renderWithProvider(<PortfolioSnapshotsPage />);
    expect(await screen.findByLabelText('Select snapshot 2026-10-10')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh', exact: true }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Showing the last successfully loaded range');
    expect(screen.getByLabelText('Select snapshot 2026-10-10')).toBeVisible();
    expect(screen.queryByText(/No portfolio snapshots yet/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh', exact: true }));
    expect(await screen.findByLabelText('Select snapshot 2026-10-11')).toBeVisible();
    expect(screen.queryByLabelText('Select snapshot 2026-10-10')).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
    expect(api.post).not.toHaveBeenCalled();
});

it('does not turn a confirmed no-portfolio bootstrap into empty snapshot history', async () => {
    sessionStorage.clear();
    api.get.mockImplementation(async (path) => {
        if (path === '/portfolios') return { data: { data: [] } };
        throw new Error(`Unexpected GET ${path}`);
    });
    renderWithProvider(<PortfolioSnapshotsPage />, { strictMode: true });
    expect(await screen.findByRole('status')).toHaveTextContent('Portfolio selection is not confirmed');
    expect(api.get.mock.calls.filter(([path]) => path === '/portfolio/snapshots')).toHaveLength(0);
    expect(screen.queryByText(/No portfolio snapshots yet/)).toBeNull();
    expect(screen.getAllByText('Unavailable', { selector: '.fw-semibold' })).toHaveLength(4);
    expect(api.post).not.toHaveBeenCalled();
});

it('keeps portfolio snapshot scope unknown when the provider bootstrap fails', async () => {
    sessionStorage.clear();
    api.get.mockImplementation(async (path) => {
        if (path === '/portfolios') throw rejected();
        throw new Error(`Unexpected GET ${path}`);
    });
    renderWithProvider(<PortfolioSnapshotsPage />, { strictMode: true });
    expect(await screen.findByRole('status')).toHaveTextContent('Portfolio selection is not confirmed');
    expect(api.get.mock.calls.filter(([path]) => path === '/portfolio/snapshots')).toHaveLength(0);
    expect(screen.queryByText(/No portfolio snapshots yet/)).toBeNull();
    expect(api.post).not.toHaveBeenCalled();
});
