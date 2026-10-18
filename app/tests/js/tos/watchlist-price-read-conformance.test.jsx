import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useNavigate } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import AuthContext from '../../../resources/js/src/context/AuthContext';
import { PortfolioProvider } from '../../../resources/js/src/context/PortfolioContext';
import PortfolioSwitcher from '../../../resources/js/src/components/PortfolioSwitcher';
import WatchlistPage from '../../../resources/js/src/pages/WatchlistPage';

vi.mock('../../../resources/js/src/api', () => ({
    default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/components/charts/PriceVolumeChart', () => ({
    default: ({ rows, loading, emptyMessage }) => (
        <div data-testid="price-chart">
            {loading ? 'Loading chart…' : rows.length ? `Confirmed ${rows.length} price row(s)` : emptyMessage}
        </div>
    ),
}));
vi.mock('../../../resources/js/src/components/charts/StockDetailsComboChart', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/WatchlistResearchPanel', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/AddToWatchlistComboButton', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/AnalyseStockButton', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/CompareStrengthComboButton', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/ManageWatchlistsModal', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/PatternSketch', () => ({ default: () => null }));

const portfolios = [
    { id: 1, name: 'Main', is_default: true },
    { id: 2, name: 'Research', is_default: false },
];
const stocks = {
    42: { id: 42, symbol: 'TCS', name: 'Tata Consultancy Services', exchange: 'NSE' },
    84: { id: 84, symbol: 'INFY', name: 'Infosys Limited', exchange: 'NSE' },
};
const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};
const pricePayload = (stock, close) => ({
    stock,
    data: [{ price_date: '2026-10-16', open_price: close - 1, high_price: close + 1, low_price: close - 2, close_price: close, volume: 100, data_source: 'SYNTHETIC_FIXTURE_ONLY' }],
    price_count: 1,
    has_price_history: true,
    latest_close: close,
    latest_price_date: '2026-10-16',
    from_date: '2026-10-16',
    to_date: '2026-10-16',
});

function RouteDriver({ phase }) {
    const navigate = useNavigate();
    return <div>
        <button type="button" onClick={() => { if (phase) phase.current = 'B'; navigate('/watchlist/INFY'); }}>Open INFY route</button>
        <button type="button" onClick={() => { if (phase) phase.current = 'return-A'; navigate('/watchlist/TCS'); }}>Return to TCS route</button>
    </div>;
}

function renderWatchlist({ path = '/watchlist/TCS', strictMode = false, phase } = {}) {
    const tree = (
        <MemoryRouter initialEntries={[path]}>
            <AuthContext.Provider value={{ loading: false, isAuthenticated: true, user: { id: 1, default_portfolio_id: 1, is_admin: false } }}>
                <PortfolioProvider>
                    <PortfolioSwitcher />
                    <RouteDriver phase={phase} />
                    <Routes><Route path="/watchlist/:symbol?" element={<WatchlistPage />} /></Routes>
                </PortfolioProvider>
            </AuthContext.Provider>
        </MemoryRouter>
    );
    return render(strictMode ? <React.StrictMode>{tree}</React.StrictMode> : tree);
}

function installBaseReads(onPriceRead) {
    const requestedPrices = [];
    api.get.mockImplementation((path, options) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/indexes') return Promise.resolve({ data: { data: { indexes: [] } } });
        if (path === '/watchlists') return Promise.resolve({ data: { data: [{ id: 5, name: 'Research', item_count: 0 }] } });
        if (path === '/watchlists/5/items') return Promise.resolve({ data: { data: [] } });
        if (path === '/watchlist/membership') return Promise.resolve({ data: { watchlist_ids: [] } });
        if (path === '/stocks/search') {
            const stock = Object.values(stocks).find((row) => row.symbol === String(options?.params?.q || '').toUpperCase());
            return Promise.resolve({ data: { data: stock ? [stock] : [] } });
        }
        const priceMatch = path.match(/^\/stocks\/(\d+)\/market-prices$/);
        if (priceMatch) {
            const stockId = Number(priceMatch[1]);
            requestedPrices.push(stockId);
            return onPriceRead(stockId, requestedPrices.length);
        }
        if (/^\/stocks\/\d+\/pattern-scan$/.test(path)) return Promise.resolve({ data: { matches: [] } });
        throw new Error(`Unexpected GET ${path}`);
    });
    return requestedPrices;
}

beforeEach(() => {
    cleanup();
    vi.resetAllMocks();
    localStorage.clear();
    sessionStorage.clear();
});
afterEach(() => cleanup());

it('keeps a late TCS response out of the pending INFY price-history view', async () => {
    const tcs = deferred();
    const infy = deferred();
    const requested = installBaseReads((stockId) => (stockId === 42 ? tcs.promise : infy.promise));

    renderWatchlist();
    await waitFor(() => expect(requested).toContain(42));
    expect(screen.getByTestId('price-chart')).toHaveTextContent('Loading chart');

    fireEvent.click(screen.getByRole('button', { name: 'Open INFY route' }));
    await waitFor(() => expect(requested).toContain(84));
    expect(screen.getByText('INFY', { exact: true })).toBeVisible();

    await act(async () => { tcs.reject(new Error('Synthetic stale TCS read failure')); });
    expect(screen.getByTestId('price-chart')).toHaveTextContent('Loading chart');
    expect(screen.queryByText('Latest close')).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();

    await act(async () => { infy.resolve({ data: pricePayload(stocks[84], 200) }); });
    await waitFor(() => expect(screen.getByTestId('price-chart')).toHaveTextContent('Confirmed 1 price row'));
    expect(screen.getByText('Latest close')).toBeVisible();
    expect(screen.getByText('₹ 200')).toBeVisible();
    expect(requested).toEqual([42, 84]);
    expect(api.post).not.toHaveBeenCalled();
    expect(api.put).not.toHaveBeenCalled();
    expect(api.delete).not.toHaveBeenCalled();
});

it('ignores a pre-ABA TCS completion while the current TCS request is pending in StrictMode', async () => {
    const oldTcsReads = [];
    const infyReads = [];
    const currentTcs = deferred();
    const phase = { current: 'initial-A' };
    let currentTcsCalls = 0;
    const requested = installBaseReads((stockId) => {
        const read = deferred();
        if (stockId === 42 && phase.current === 'return-A') {
            currentTcsCalls += 1;
            return currentTcs.promise;
        }
        if (stockId === 42) oldTcsReads.push(read);
        else infyReads.push(read);
        return read.promise;
    });

    renderWatchlist({ strictMode: true, phase });
    await waitFor(() => expect(requested).toContain(42));
    fireEvent.click(screen.getByRole('button', { name: 'Open INFY route' }));
    await waitFor(() => expect(requested).toContain(84));
    fireEvent.click(screen.getByRole('button', { name: 'Return to TCS route' }));
    await waitFor(() => expect(currentTcsCalls).toBeGreaterThan(0));

    const staleReads = [...oldTcsReads];
    await act(async () => {
        staleReads.forEach((read) => read.resolve({ data: pricePayload(stocks[42], 100) }));
        infyReads.forEach((read) => read.resolve({ data: pricePayload(stocks[84], 200) }));
    });
    expect(screen.getByTestId('price-chart')).toHaveTextContent('Loading chart');
    expect(screen.queryByText('₹ 100')).toBeNull();

    await act(async () => { currentTcs.resolve({ data: pricePayload(stocks[42], 110) }); });
    await waitFor(() => expect(screen.getByTestId('price-chart')).toHaveTextContent('Confirmed 1 price row'));
    expect(screen.getByText('₹ 110')).toBeVisible();
    expect(screen.queryByText('₹ 100')).toBeNull();
    expect(api.post).not.toHaveBeenCalled();
    expect(api.put).not.toHaveBeenCalled();
    expect(api.delete).not.toHaveBeenCalled();
});

it('clears the selected-stock projection through the real portfolio switch event', async () => {
    const tcs = deferred();
    const requested = installBaseReads((stockId) => (stockId === 42 ? tcs.promise : Promise.resolve({ data: pricePayload(stocks[stockId], 200) })));

    renderWatchlist();
    await waitFor(() => expect(requested).toContain(42));
    expect(screen.getByTestId('price-chart')).toHaveTextContent('Loading chart');
    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(await screen.findByRole('button', { name: 'Research', exact: true }));
    expect(screen.queryByTestId('price-chart')).toBeNull();

    await act(async () => { tcs.resolve({ data: pricePayload(stocks[42], 100) }); });
    expect(screen.queryByText('Latest close')).toBeNull();
    expect(screen.queryByText('TCS', { exact: true })).toBeNull();
    expect(api.post).not.toHaveBeenCalled();
    expect(api.put).not.toHaveBeenCalled();
    expect(api.delete).not.toHaveBeenCalled();
});

it('keeps malformed history unavailable and distinguishes a confirmed empty read after explicit retry', async () => {
    let attempts = 0;
    const requested = installBaseReads((stockId) => {
        attempts += 1;
        if (attempts === 1) return Promise.resolve({ data: { price_count: 0, has_price_history: false } });
        if (attempts === 2) return Promise.resolve({ data: { data: [], price_count: 0, has_price_history: false, latest_close: null, latest_price_date: null, from_date: null, to_date: null } });
        if (attempts === 3) return Promise.resolve({ data: { stock: stocks[84], data: [], price_count: 0, has_price_history: false } });
        return Promise.resolve({ data: { stock: stocks[stockId], data: [], price_count: 0, has_price_history: false, latest_close: null, latest_price_date: null, from_date: null, to_date: null } });
    });

    renderWatchlist();
    expect(await screen.findByRole('alert')).toHaveTextContent('Price history could not be confirmed');
    expect(screen.getByTestId('price-chart')).toHaveTextContent('Price history is unavailable');
    expect(screen.queryByText(/No cached OHLCV/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Retry price history' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Price history could not be confirmed');
    expect(screen.queryByText(/No cached OHLCV/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Retry price history' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Price history could not be confirmed');
    expect(screen.queryByText(/No cached OHLCV/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Retry price history' }));
    await waitFor(() => expect(screen.getByTestId('price-chart')).toHaveTextContent('No cached price history'));
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.getByText(/No cached OHLCV/)).toBeVisible();
    expect(requested).toEqual([42, 42, 42, 42]);
    expect(api.post).not.toHaveBeenCalled();
    expect(api.put).not.toHaveBeenCalled();
    expect(api.delete).not.toHaveBeenCalled();
});
