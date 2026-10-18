import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import PortfolioSwitcher from '../../../resources/js/src/components/PortfolioSwitcher';
import StockPricesPage from '../../../resources/js/src/pages/StockPricesPage';
import IndicesPage from '../../../resources/js/src/pages/IndicesPage';
import IndexSection from '../../../resources/js/src/components/indices/IndexSection';
import ReviewReportDetailPage from '../../../resources/js/src/pages/ReviewReportDetailPage';
import ReviewDashboardPage from '../../../resources/js/src/pages/ReviewDashboardPage';
import FundamentalInsightsPage from '../../../resources/js/src/pages/FundamentalInsightsPage';
import { PortfolioProvider } from '../../../resources/js/src/context/PortfolioContext';

vi.mock('../../../resources/js/src/api', async () => { const actual = await vi.importActual('../../../resources/js/src/api'); return { default: { get: vi.fn(), post: vi.fn() }, getApiErrorMessage: actual.getApiErrorMessage }; });
vi.mock('../../../resources/js/src/context/AuthContext', () => ({ useAuth: () => ({ isAuthenticated: true, loading: false, user: { id: 1, is_admin: false } }) }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/components/charts/PriceVolumeChart', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/indices/IndexComparisonChart', () => ({ default: () => null }));
beforeEach(() => { cleanup(); vi.clearAllMocks(); });
const unavailable = () => ({ response: { status: 503, data: { message: 'Synthetic unavailable' } }, message: 'Request failed with status code 503' });
function at(path, route, component) { return render(<MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={component} /></Routes></MemoryRouter>); }
function stockPricesAt() {
    sessionStorage.setItem('portfolio_active_id', '1');
    return at('/holdings/42/prices', '/holdings/:stockId/prices', <StockPricesPage />);
}
function stockPricesWithPortfolioProvider() {
    return <PortfolioProvider><MemoryRouter initialEntries={['/holdings/42/prices']}>
        <PortfolioSwitcher />
        <Routes><Route path="/holdings/:stockId/prices" element={<StockPricesPage />} /></Routes>
    </MemoryRouter></PortfolioProvider>;
}
function deferred() { let resolve; let reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }
function priceHistory(symbol, source, range = 'all') { return { data: { stock: { id: 42, symbol }, range, data: [{ price_date: '2026-10-01', open_price: 100, high_price: 105, low_price: 95, close_price: 101, volume: 10, data_source: source }], price_count: 1, has_price_history: true } }; }

it('recovers unavailable price reads without Investor sync authority', async () => {
    api.get.mockRejectedValueOnce(unavailable()).mockResolvedValue({ data: { stock: { symbol: 'TCS' }, data: [], has_price_history: false } });
    stockPricesAt();
    expect(await screen.findByRole('alert')).toHaveTextContent('Failed to load price history');
    fireEvent.click(screen.getByRole('button', { name: 'Retry price history' }));
    expect(await screen.findByText('TCS — Price History')).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Force sync historical prices' })).toBeNull();
    expect(screen.queryByText(/Not fetched — use Force sync/)).toBeNull(); expect(api.post).not.toHaveBeenCalled();
});
it('does not let an older stock-range response replace the latest confirmed range', async () => {
    const sinceBuy = deferred(); let allReads = 0;
    api.get.mockImplementation((_path, config) => {
        if (config.params.range === 'since_buy') return sinceBuy.promise;
        allReads += 1;
        return Promise.resolve(priceHistory('TCS', allReads === 1 ? 'ALL_INITIAL' : 'ALL_CURRENT'));
    });
    stockPricesAt();
    expect(await screen.findByText('ALL_INITIAL')).toBeVisible();
    fireEvent.click(within(screen.getByRole('group', { name: 'Stock history range' })).getByRole('button', { name: 'Since Buy', exact: true }));
    expect(screen.queryByText('ALL_INITIAL')).toBeNull();
    fireEvent.click(within(screen.getByRole('group', { name: 'Stock history range' })).getByRole('button', { name: 'All', exact: true }));
    expect(await screen.findByText('ALL_CURRENT')).toBeVisible();
    await act(async () => sinceBuy.resolve(priceHistory('TCS', 'OLD_SINCE_BUY', 'since_buy')));
    expect(screen.getByText('ALL_CURRENT')).toBeVisible();
    expect(screen.queryByText('OLD_SINCE_BUY')).toBeNull();
    expect(api.get.mock.calls.map(([, config]) => config.params.range)).toEqual(['all', 'since_buy', 'all']);
    expect(api.post).not.toHaveBeenCalled();
});
it('hides the previous portfolio while its new price read is pending and ignores the late old read', async () => {
    const oldProfile = deferred(); let priceReads = 0;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: [
            { id: 1, name: 'Main', is_default: true }, { id: 2, name: 'Research', is_default: false },
        ] } });
        priceReads += 1;
        return priceReads === 1 ? oldProfile.promise : Promise.resolve(priceHistory('TCS', 'PROFILE_TWO'));
    });
    render(stockPricesWithPortfolioProvider());
    await waitFor(() => expect(priceReads).toBe(1));
    fireEvent.click(await screen.findByRole('button', { name: 'Main', exact: true }));
    fireEvent.click(screen.getByRole('button', { name: 'Research', exact: true }));
    expect(await screen.findByText('PROFILE_TWO')).toBeVisible();
    await act(async () => oldProfile.resolve(priceHistory('TCS', 'PROFILE_ONE')));
    expect(screen.getByText('PROFILE_TWO')).toBeVisible();
    expect(screen.queryByText('PROFILE_ONE')).toBeNull();
    expect(priceReads).toBe(2);
    expect(api.post).not.toHaveBeenCalled();
});
it('keeps a failed changed-range price read unknown until explicit retry confirms an empty range', async () => {
    let sinceBuyReads = 0;
    api.get.mockImplementation((_path, config) => {
        if (config.params.range === 'all') return Promise.resolve(priceHistory('TCS', 'ALL_RANGE'));
        sinceBuyReads += 1;
        if (sinceBuyReads === 1) return Promise.reject(unavailable());
        return Promise.resolve({ data: { stock: { id: 42, symbol: 'TCS' }, range: 'since_buy', data: [], price_count: 0, has_price_history: false } });
    });
    stockPricesAt();
    expect(await screen.findByText('ALL_RANGE')).toBeVisible();
    fireEvent.click(within(screen.getByRole('group', { name: 'Stock history range' })).getByRole('button', { name: 'Since Buy', exact: true }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Failed to load price history');
    expect(screen.queryByText('ALL_RANGE')).toBeNull();
    expect(screen.getByText('Rows (Since Buy):')).toBeVisible();
    expect(screen.getByText('Rows (Since Buy):').parentElement).toHaveTextContent('—');
    fireEvent.click(screen.getByRole('button', { name: 'Retry price history' }));
    expect(await screen.findByText('Price history unavailable — ask an administrator to review source coverage')).toBeVisible();
    expect(screen.getByText('Rows (Since Buy):').parentElement).toHaveTextContent('0');
    expect(api.post).not.toHaveBeenCalled();
});
it('settles the latest stock read when StrictMode replays mount effects', async () => {
    api.get.mockResolvedValueOnce(priceHistory('TCS', 'STALE_STRICT_MODE'))
        .mockResolvedValueOnce(priceHistory('TCS', 'CONFIRMED_STRICT_MODE'));
    sessionStorage.setItem('portfolio_active_id', '1');
    render(<React.StrictMode><MemoryRouter initialEntries={['/holdings/42/prices']}><Routes><Route path="/holdings/:stockId/prices" element={<StockPricesPage />} /></Routes></MemoryRouter></React.StrictMode>);
    expect(await screen.findByText('CONFIRMED_STRICT_MODE')).toBeVisible();
    expect(screen.queryByText('STALE_STRICT_MODE')).toBeNull();
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(api.post).not.toHaveBeenCalled();
});
it('retries the failed index pair instead of leaving an unrecoverable alert', async () => {
    let failed = true; api.get.mockImplementation(async () => { if (failed) throw unavailable(); return { data: { data: { indexes: [] } } }; });
    render(<MemoryRouter><IndicesPage /></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Failed to load index data'); failed = false;
    fireEvent.click(screen.getByRole('button', { name: 'Retry index data' }));
    expect(await screen.findByRole('heading', { name: 'Indices' })).toBeVisible(); expect(api.get).toHaveBeenCalledTimes(4); expect(api.post).not.toHaveBeenCalled();
});
it('does not cache a failed index-history read as confirmed empty', async () => {
    api.get.mockRejectedValueOnce(unavailable()).mockResolvedValue({ data: { data: [] } });
    render(<MemoryRouter><IndexSection index={{ stock_id: 1, symbol: 'NIFTY50', name: 'Nifty 50', has_price_history: true }} panelId="index-fixture" expanded onToggle={() => {}} /></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Index history could not be loaded');
    fireEvent.click(screen.getByRole('button', { name: 'Retry index history' }));
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(2)); expect(api.post).not.toHaveBeenCalled();
});
it('distinguishes a report read failure from an authoritative missing report', async () => {
    api.get.mockRejectedValueOnce(unavailable()).mockRejectedValueOnce({ response: { status: 404 } });
    at('/review/reports/12', '/review/reports/:id', <ReviewReportDetailPage />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Review report could not be loaded'); expect(screen.queryByTestId('review-report-not-found')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Retry review report' }));
    expect(await screen.findByTestId('review-report-not-found')).toBeVisible(); expect(api.post).not.toHaveBeenCalled();
});
it('stops loading after a failed observational review and recovers through Refresh', async () => {
    let failed = true; api.get.mockImplementation(async path => { if (failed) throw unavailable(); return { data: { data: path.endsWith('/orders') ? [] : {} } }; });
    render(<MemoryRouter><ReviewDashboardPage /></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Review dashboard could not be loaded');
    expect(screen.queryByText('Loading…')).toBeNull(); failed = false; fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
    expect(await screen.findByText('Trade recommendations (actionable)')).toBeVisible(); expect(api.post).not.toHaveBeenCalled();
});
it('retries failed symbol resolution and keeps insights recovery read-only', async () => {
    let failed = true; api.get.mockImplementation(async path => { if (failed) throw unavailable(); return { data: { data: path === '/stocks/search' ? [{ id: 42, symbol: 'TCS', exchange: 'NSE' }] : { as_of: '2026-10-01', insights: null } } }; });
    at('/fundamentals/insights/TCS', '/fundamentals/insights/:symbol', <FundamentalInsightsPage />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic unavailable'); failed = false;
    fireEvent.click(screen.getByRole('button', { name: 'Retry fundamental insights' }));
    expect(await screen.findByText('No insight payload returned for this symbol.')).toBeVisible(); expect(api.post).not.toHaveBeenCalled();
});

it('discloses the API failure reason while retaining symbol/evidence recovery', async () => {
    let stage = 0; api.get.mockImplementation(async path => {
        if (stage === 0) throw { response: { status: 503, data: { message: 'Symbol source unavailable' } }, message: 'Request failed with status code 503' };
        if (path === '/stocks/search') return { data: { data: [{ id: 42, symbol: 'TCS', exchange: 'NSE' }] } };
        if (stage === 1) throw { response: { status: 503, data: { message: 'Evidence source unavailable' } }, message: 'Request failed with status code 503' };
        return { data: { data: { as_of: '2026-10-01', insights: null } } };
    });
    at('/fundamentals/insights/TCS', '/fundamentals/insights/:symbol', <FundamentalInsightsPage />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Symbol source unavailable'); stage = 1;
    fireEvent.click(screen.getByRole('button', { name: 'Retry fundamental insights' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Evidence source unavailable'); stage = 2;
    fireEvent.click(screen.getByRole('button', { name: 'Retry fundamental insights' }));
    expect(await screen.findByText('No insight payload returned for this symbol.')).toBeVisible(); expect(api.post).not.toHaveBeenCalled();
});
