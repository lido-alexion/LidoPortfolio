import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import StockPricesPage from '../../../resources/js/src/pages/StockPricesPage';
import IndicesPage from '../../../resources/js/src/pages/IndicesPage';
import IndexSection from '../../../resources/js/src/components/indices/IndexSection';
import MarketDepthPage from '../../../resources/js/src/pages/MarketDepthPage';
import ReviewReportDetailPage from '../../../resources/js/src/pages/ReviewReportDetailPage';
import ReviewDashboardPage from '../../../resources/js/src/pages/ReviewDashboardPage';
import FundamentalInsightsPage from '../../../resources/js/src/pages/FundamentalInsightsPage';

vi.mock('../../../resources/js/src/api', async () => { const actual = await vi.importActual('../../../resources/js/src/api'); return { default: { get: vi.fn(), post: vi.fn() }, getApiErrorMessage: actual.getApiErrorMessage }; });
vi.mock('../../../resources/js/src/context/AuthContext', () => ({ useAuth: () => ({ user: { is_admin: false } }) }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/components/charts/PriceVolumeChart', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/indices/IndexComparisonChart', () => ({ default: () => null }));
beforeEach(() => { cleanup(); vi.clearAllMocks(); });
const unavailable = () => ({ response: { status: 503, data: { message: 'Synthetic unavailable' } }, message: 'Request failed with status code 503' });
function at(path, route, component) { return render(<MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={component} /></Routes></MemoryRouter>); }

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
