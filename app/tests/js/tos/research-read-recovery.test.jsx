import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useNavigate } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import StockPricesPage from '../../../resources/js/src/pages/StockPricesPage';
import IndicesPage from '../../../resources/js/src/pages/IndicesPage';
import IndexSection from '../../../resources/js/src/components/indices/IndexSection';
import MarketDepthPage from '../../../resources/js/src/pages/MarketDepthPage';
import ReviewReportDetailPage from '../../../resources/js/src/pages/ReviewReportDetailPage';
import ReviewDashboardPage from '../../../resources/js/src/pages/ReviewDashboardPage';
import FundamentalInsightsPage from '../../../resources/js/src/pages/FundamentalInsightsPage';
import AuthContext from '../../../resources/js/src/context/AuthContext';
import { PortfolioProvider } from '../../../resources/js/src/context/PortfolioContext';
import PortfolioSwitcher from '../../../resources/js/src/components/PortfolioSwitcher';

vi.mock('../../../resources/js/src/api', async () => { const actual = await vi.importActual('../../../resources/js/src/api'); return { default: { get: vi.fn(), post: vi.fn() }, getApiErrorMessage: actual.getApiErrorMessage }; });
vi.mock('../../../resources/js/src/context/AuthContext', async importOriginal => { const actual = await importOriginal(); return { ...actual, useAuth: () => ({ loading: false, isAuthenticated: true, user: { id: 1, default_portfolio_id: 1, is_admin: false } }) }; });
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/components/charts/PriceVolumeChart', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/indices/IndexComparisonChart', () => ({ default: () => null }));
beforeEach(() => { cleanup(); vi.clearAllMocks(); sessionStorage.clear(); sessionStorage.setItem('portfolio_active_id', '1'); });
const unavailable = () => ({ response: { status: 503, data: { message: 'Synthetic unavailable' } }, message: 'Request failed with status code 503' });
function at(path, route, component) { return render(<MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={component} /></Routes></MemoryRouter>); }
function ReportNavigation() {
    const navigate = useNavigate();
    return <><button type="button" onClick={() => navigate('/review/reports/12')}>Open report A</button><button type="button" onClick={() => navigate('/review/reports/13')}>Open report B</button></>;
}
function atReport(path = '/review/reports/12', strictMode = false) {
    const tree = <MemoryRouter initialEntries={[path]}><AuthContext.Provider value={{ loading: false, isAuthenticated: true, user: { id: 1, default_portfolio_id: 1, is_admin: false } }}><PortfolioProvider><PortfolioSwitcher /><ReportNavigation /><Routes><Route path="/review/reports/:id" element={<ReviewReportDetailPage />} /></Routes></PortfolioProvider></AuthContext.Provider></MemoryRouter>;
    return render(strictMode ? <React.StrictMode>{tree}</React.StrictMode> : tree);
}
const deferred = () => { let resolve; let reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };

it('recovers unavailable price reads without Investor sync authority', async () => {
    api.get.mockRejectedValueOnce(unavailable()).mockResolvedValue({ data: { stock: { symbol: 'TCS' }, data: [], has_price_history: false } });
    at('/holdings/42/prices', '/holdings/:stockId/prices', <StockPricesPage />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Failed to load price history');
    fireEvent.click(screen.getByRole('button', { name: 'Retry price history' }));
    expect(await screen.findByText('TCS — Price History')).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Force sync historical prices' })).toBeNull();
    expect(screen.queryByText(/Not fetched — use Force sync/)).toBeNull(); expect(api.post).not.toHaveBeenCalled();
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
it('exposes a failed breadth read rather than claiming no snapshots', async () => {
    api.get.mockRejectedValueOnce(unavailable()).mockResolvedValue({ data: { available_dates: [], matrix: null, chart_history: [] } });
    render(<MemoryRouter><MarketDepthPage /></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Market breadth could not be loaded');
    fireEvent.click(screen.getByRole('button', { name: 'Retry market breadth' }));
    expect(await screen.findByRole('option', { name: 'No snapshots' })).toBeVisible(); expect(api.get).toHaveBeenCalledTimes(2); expect(api.post).not.toHaveBeenCalled();
});
it('distinguishes a report read failure from an authoritative missing report', async () => {
    api.get.mockImplementation(async path => {
        if (path === '/portfolios') return { data: { data: [{ id: 1, name: 'Main', is_default: true }] } };
        if (path === '/v1/reviews/12') throw unavailable();
        throw new Error(`Unexpected GET ${path}`);
    });
    atReport();
    expect(await screen.findByRole('alert')).toHaveTextContent('Review report could not be loaded'); expect(screen.queryByTestId('review-report-not-found')).toBeNull();
    api.get.mockImplementation(async path => {
        if (path === '/portfolios') return { data: { data: [{ id: 1, name: 'Main', is_default: true }] } };
        if (path === '/v1/reviews/12') throw { response: { status: 404 } };
        throw new Error(`Unexpected GET ${path}`);
    });
    fireEvent.click(screen.getByRole('button', { name: 'Retry review report' }));
    expect(await screen.findByTestId('review-report-not-found')).toBeVisible(); expect(api.post).not.toHaveBeenCalled();
});

it('keeps report identity on the current route and selected portfolio while older reads settle', async () => {
    const firstA = deferred(); const currentB = deferred(); const currentA = deferred();
    let aReads = 0; const detailScopes = [];
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: [
            { id: 1, name: 'Main', is_default: true },
            { id: 2, name: 'Research', is_default: false },
        ] } });
        if (path === '/v1/reviews/12') {
            detailScopes.push(['12', sessionStorage.getItem('portfolio_active_id')]);
            return (++aReads === 1 ? firstA : currentA).promise;
        }
        if (path === '/v1/reviews/13') {
            detailScopes.push(['13', sessionStorage.getItem('portfolio_active_id')]);
            return currentB.promise;
        }
        throw new Error(`Unexpected GET ${path}`);
    });
    atReport();
    await waitFor(() => expect(detailScopes).toContainEqual(['12', '1']));
    fireEvent.click(screen.getByRole('button', { name: 'Open report B' }));
    await waitFor(() => expect(detailScopes).toContainEqual(['13', '1']));
    fireEvent.click(screen.getByRole('button', { name: 'Open report A' }));
    await waitFor(() => expect(aReads).toBe(2));
    await act(async () => currentB.resolve({ data: { data: { id: 13, status: 'old B response', period_start: '2026-09-01', period_end: '2026-09-30', metrics: {}, methodology: {} } } }));
    expect(screen.getByRole('status')).toHaveTextContent('Loading review report');
    expect(screen.queryByText('old B response')).toBeNull();
    await act(async () => firstA.reject(unavailable()));
    expect(screen.getByRole('status')).toHaveTextContent('Loading review report');
    await act(async () => currentA.resolve({ data: { data: { id: 12, status: 'current A response', period_start: '2026-10-01', period_end: '2026-10-31', metrics: {}, methodology: {} } } }));
    expect(await screen.findByText('current A response', { exact: true })).toBeVisible();
    expect(screen.queryByText('old B response')).toBeNull();
    expect(detailScopes).toEqual([['12', '1'], ['13', '1'], ['12', '1']]);
    expect(api.post).not.toHaveBeenCalled();
});

it('hides a confirmed report until the current portfolio-scoped reread settles', async () => {
    const scopes = [];
    api.get.mockImplementation(path => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: [
            { id: 1, name: 'Main', is_default: true },
            { id: 2, name: 'Research', is_default: false },
        ] } });
        if (path === '/v1/reviews/12') {
            const scope = sessionStorage.getItem('portfolio_active_id'); scopes.push(scope);
            return Promise.resolve({ data: { data: { id: 12, status: `report for ${scope}`, period_start: '2026-10-01', period_end: '2026-10-31', metrics: {}, methodology: {} } } });
        }
        throw new Error(`Unexpected GET ${path}`);
    });
    atReport();
    expect(await screen.findByText('report for 1', { exact: true })).toBeVisible();
    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(await screen.findByRole('button', { name: 'Research', exact: true }));
    expect(screen.getByRole('status')).toHaveTextContent('Loading review report');
    expect(screen.queryByText('report for 1', { exact: true })).toBeNull();
    expect(await screen.findByText('report for 2', { exact: true })).toBeVisible();
    expect(scopes).toEqual(['1', '2']);
    expect(api.post).not.toHaveBeenCalled();
});

it('waits for the real portfolio bootstrap before requesting a portfolio-scoped report', async () => {
    sessionStorage.clear();
    const portfolios = deferred(); const reportRead = deferred(); const scopes = [];
    api.get.mockImplementation(path => {
        if (path === '/portfolios') return portfolios.promise;
        if (path === '/v1/reviews/12') { scopes.push(sessionStorage.getItem('portfolio_active_id')); return reportRead.promise; }
        throw new Error(`Unexpected GET ${path}`);
    });
    atReport();
    await waitFor(() => expect(api.get.mock.calls.some(([path]) => path === '/portfolios')).toBe(true));
    expect(screen.getByRole('status')).toHaveTextContent('Waiting for the selected portfolio');
    expect(scopes).toEqual([]);
    await act(async () => portfolios.resolve({ data: { data: [{ id: 2, name: 'Research', is_default: true }] } }));
    await waitFor(() => expect(scopes).toEqual(['2']));
    await act(async () => reportRead.resolve({ data: { data: { id: 12, status: 'bootstrapped report', period_start: '2026-10-01', period_end: '2026-10-31', metrics: {}, methodology: {} } } }));
    expect(await screen.findByText('bootstrapped report', { exact: true })).toBeVisible();
    expect(api.post).not.toHaveBeenCalled();
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
