import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import AuthContext from '../../../resources/js/src/context/AuthContext';
import { PortfolioProvider, usePortfolio } from '../../../resources/js/src/context/PortfolioContext';
import PortfolioSwitcher from '../../../resources/js/src/components/PortfolioSwitcher';
import DashboardPage from '../../../resources/js/src/pages/DashboardPage';
import { clearAllDashboardCaches, readDashboardCache, writeDashboardCache } from '../../../resources/js/src/utils/dashboardCache';

vi.mock('../../../resources/js/src/api', async (load) => ({ ...(await load()), default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../../resources/js/src/components/DataTable', () => ({ DataTableCard: ({ title, data = [], headerExtra }) => <section>{typeof title === 'string' ? title : 'Table'}{headerExtra}{data.map((row, index) => <div key={index}><span>{row.symbol}</span> <span>{row.pattern_name}</span> <span>{row.message}</span></div>)}</section> }));
vi.mock('../../../resources/js/src/components/AnalyseStockButton', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/DashboardTopMoverCard', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/DashboardAllocationCard', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/DashboardLayoutToolbar', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/KiteReadinessCard', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/PortfolioReconciliationCard', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/PercentGradientBar', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/SentimentGauge', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/MarketPhaseGauge', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/TrendGauge', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/MomentumGauge', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/VolatilityGauge', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/RiskGauge', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/MarketRegimeGauge', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/MarketBreadthGauge', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/calendar/CalendarDayEventsDialog', () => ({ DashboardCalendarCard: ({ events = [], loading, error }) => <section>{loading ? 'Calendar loading' : ''}{error}{events.map((event) => <div key={event.id}>{event.title}</div>)}</section> }));
vi.mock('../../../resources/js/src/components/PatternSketch', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));

const deferred = () => { let resolve; let reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const portfolios = { data: { data: [{ id: 1, name: 'Main', is_default: true }, { id: 2, name: 'Research' }] } };
const dashboard = (value, extras = {}) => ({ data: { portfolio_value: value, invested_value: 0, total_gain_loss: 0, alerts: [], daily_market_sync: {}, market_analytics: {}, portfolio_growth: [], relative_strength_trends: [], portfolio_analytics: { allocation: [] }, ...extras } });
const patterns = (symbol) => ({ data: { results: [{ stock_id: 8, symbol, matches: [{ id: 'p1', name: `${symbol} pattern`, category: 'breakout', bar_date: '2026-10-20', variant: 'chart' }] }] } });
function BatchSwitch() { const { setActivePortfolio } = usePortfolio(); return <button onClick={() => { setActivePortfolio(2); setActivePortfolio(1); }}>Batch A-B-A</button>; }
function mount(extra = null, user = { id: 1, default_portfolio_id: 1, is_admin: false }) { return render(<MemoryRouter initialEntries={['/dashboard']}><AuthContext.Provider value={{ loading: false, isAuthenticated: true, user }}><Routes><Route path="/dashboard" element={<PortfolioProvider><PortfolioSwitcher />{extra}<DashboardPage /></PortfolioProvider>} /></Routes></AuthContext.Provider></MemoryRouter>); }
function switchToB() { fireEvent.click(screen.getByTitle('Switch portfolio (per tab)')); fireEvent.click(screen.getByRole('button', { name: 'Research', exact: true })); }
beforeEach(() => { cleanup(); vi.restoreAllMocks(); vi.resetAllMocks(); clearAllDashboardCaches(); sessionStorage.clear(); localStorage.clear(); sessionStorage.setItem('portfolio_active_id', '1'); api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolios) : path === '/dashboard' ? Promise.resolve(dashboard(100)) : path === '/patterns/scan' ? Promise.resolve(patterns('AAA')) : Promise.resolve({ data: { data: [] } })); });
afterEach(() => { cleanup(); vi.restoreAllMocks(); });

it('keeps the latest dashboard and pattern pair after pending A resolves behind B', async () => {
    const oldDash = deferred(); const oldPatterns = deferred(); const newDash = deferred(); const newPatterns = deferred(); let aReads = 0;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve(portfolios);
        if (path === '/calendar/upcoming') return Promise.resolve({ data: { data: [] } });
        if (sessionStorage.getItem('portfolio_active_id') === '1') { aReads += 1; return path === '/dashboard' ? oldDash.promise : oldPatterns.promise; }
        return path === '/dashboard' ? newDash.promise : newPatterns.promise;
    });
    mount(); await waitFor(() => expect(aReads).toBe(2)); switchToB(); await waitFor(() => expect(api.get.mock.calls.filter(([p]) => p === '/dashboard')).toHaveLength(2));
    await act(async () => { newDash.resolve(dashboard(200)); newPatterns.resolve(patterns('BBB')); });
    await act(async () => { oldDash.resolve(dashboard(900)); oldPatterns.resolve(patterns('OLD')); });
    expect(screen.getByText(/200/)).toBeVisible(); expect(screen.getByText('BBB')).toBeVisible();
    expect(screen.queryByText(/900/)).toBeNull(); expect(screen.queryByText('OLD')).toBeNull();
});

it('ignores an old calendar response after B calendar has resolved', async () => {
    const oldCalendar = deferred(); const newCalendar = deferred(); let calendarCalls = 0;
    api.get.mockImplementation((path) => { if (path === '/portfolios') return Promise.resolve(portfolios); if (path === '/calendar/upcoming') return ++calendarCalls === 1 ? oldCalendar.promise : newCalendar.promise; return Promise.resolve(path === '/dashboard' ? dashboard(10) : patterns('AAA')); });
    mount(); await waitFor(() => expect(calendarCalls).toBe(1)); switchToB(); await waitFor(() => expect(calendarCalls).toBe(2));
    await act(async () => newCalendar.resolve({ data: { data: [{ id: 2, title: 'B calendar marker' }] } }));
    expect(await screen.findByText('B calendar marker')).toBeVisible();
    await act(async () => oldCalendar.resolve({ data: { data: [{ id: 1, title: 'A calendar marker' }] } }));
    expect(screen.getByText('B calendar marker')).toBeVisible(); expect(screen.queryByText('A calendar marker')).toBeNull();
});

it('invalidates a genuinely pending A request across batched provider A-B-A changes', async () => {
    const first = deferred(); let dashboardCalls = 0;
    api.get.mockImplementation((path) => { if (path === '/portfolios') return Promise.resolve(portfolios); if (path === '/calendar/upcoming') return Promise.resolve({ data: { data: [] } }); if (path === '/dashboard') return ++dashboardCalls === 1 ? first.promise : Promise.resolve(dashboard(333)); return Promise.resolve(patterns('AAA')); });
    mount(<BatchSwitch />); await waitFor(() => expect(dashboardCalls).toBe(1)); fireEvent.click(screen.getByRole('button', { name: 'Batch A-B-A' }));
    await waitFor(() => expect(dashboardCalls).toBe(2)); expect(await screen.findByText(/333/)).toBeVisible();
    const freshReads = dashboardCalls; await act(async () => first.resolve(dashboard(555)));
    expect(dashboardCalls).toBe(freshReads); expect(screen.getByText(/333/)).toBeVisible(); expect(screen.queryByText(/555/)).toBeNull();
});

it('does not issue scoped reads while stored portfolio is stale during provider bootstrap', async () => {
    sessionStorage.setItem('portfolio_active_id', '99');
    const portDeferred = deferred(); api.get.mockImplementation((path) => path === '/portfolios' ? portDeferred.promise : Promise.resolve(path === '/dashboard' ? dashboard(100) : { data: { data: [] } }));
    mount(); expect(api.get.mock.calls.some(([path]) => path === '/dashboard' || path === '/patterns/scan' || path === '/calendar/upcoming')).toBe(false);
    await act(async () => portDeferred.resolve(portfolios));
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/dashboard'));
});

it('waits for confirmed default Portfolio when storage is empty at bootstrap', async () => {
    sessionStorage.removeItem('portfolio_active_id');
    const portDeferred = deferred(); api.get.mockImplementation((path) => path === '/portfolios' ? portDeferred.promise : Promise.resolve(path === '/dashboard' ? dashboard(100) : path === '/patterns/scan' ? patterns('AAA') : { data: { data: [] } }));
    mount(); expect(api.get.mock.calls.some(([path]) => ['/dashboard', '/patterns/scan', '/calendar/upcoming'].includes(path))).toBe(false);
    await act(async () => portDeferred.resolve(portfolios));
    await waitFor(() => expect(sessionStorage.getItem('portfolio_active_id')).toBe('1'));
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/dashboard'));
});

it('checks storage again after synchronous rebuild confirmation before posting', async () => {
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolios) : Promise.resolve(path === '/dashboard' ? dashboard(100) : { data: { data: [] } }));
    vi.spyOn(window, 'confirm').mockImplementation(() => { sessionStorage.setItem('portfolio_active_id', '2'); return true; });
    mount(); await screen.findByText(/100/); fireEvent.click(screen.getByRole('button', { name: 'Rebuild history' }));
    expect(api.post).not.toHaveBeenCalled();
});

it('does not let an obsolete A failure clear B refresh busy state', async () => {
    const oldDash = deferred(); const oldPatterns = deferred(); const bRefresh = deferred(); let aForce = false; let bRefreshStarted = false;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve(portfolios);
        if (path === '/calendar/upcoming') return Promise.resolve({ data: { data: [] } });
        if (sessionStorage.getItem('portfolio_active_id') === '1' && aForce) return path === '/dashboard' ? oldDash.promise : oldPatterns.promise;
        if (path === '/dashboard' && bRefreshStarted) return bRefresh.promise;
        return Promise.resolve(path === '/dashboard' ? dashboard(200) : patterns('BBB'));
    });
    mount(); await screen.findByText(/200/); aForce = true; fireEvent.click(screen.getByRole('button', { name: 'Refresh dashboard' }));
    await waitFor(() => expect(api.get.mock.calls.filter(([path]) => path === '/dashboard')).toHaveLength(2));
    switchToB(); await screen.findByText(/200/);
    bRefreshStarted = true; fireEvent.click(screen.getByRole('button', { name: 'Refresh dashboard' }));
    await waitFor(() => expect(api.get.mock.calls.filter(([path]) => path === '/dashboard')).toHaveLength(4));
    await act(async () => { oldDash.reject(new Error('old request')); oldPatterns.reject(new Error('old patterns')); });
    expect(screen.getByRole('button', { name: 'Refreshing…' })).toBeDisabled();
    await act(async () => { bRefresh.resolve(dashboard(222)); });
    expect(await screen.findByText(/222/)).toBeVisible();
    expect(screen.getByRole('button', { name: 'Refresh dashboard' })).toBeEnabled();
});

it('lets the latest same-scope paired read own the dashboard and keeps failure retryable', async () => {
    const first = deferred(); const second = deferred(); const failure = deferred(); let dashboardReads = 0;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve(portfolios);
        if (path === '/calendar/upcoming') return Promise.resolve({ data: { data: [] } });
        if (path === '/dashboard') { dashboardReads += 1; if (dashboardReads === 2) return first.promise; if (dashboardReads === 3) return second.promise; if (dashboardReads === 4) return failure.promise; }
        return Promise.resolve(path === '/dashboard' ? dashboard(111) : patterns('AAA'));
    });
    api.post.mockResolvedValue({ data: {} }); vi.spyOn(window, 'confirm').mockReturnValue(true);
    mount(); expect(await screen.findByText(/111/)).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh dashboard' }));
    await waitFor(() => expect(dashboardReads).toBe(2));
    fireEvent.click(screen.getByRole('button', { name: 'Rebuild history' }));
    await waitFor(() => expect(dashboardReads).toBe(3));
    await act(async () => second.resolve(dashboard(333)));
    expect(await screen.findByText(/333/)).toBeVisible();
    await act(async () => first.resolve(dashboard(999)));
    expect(screen.getByText(/333/)).toBeVisible(); expect(screen.queryByText(/999/)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh dashboard' }));
    await waitFor(() => expect(dashboardReads).toBe(4));
    // The current request rejects. Existing presentation remains and Refresh remains an explicit retry.
    await act(async () => { failure.reject(new Error('service unavailable')); });
    expect(screen.getByText(/333/)).toBeVisible(); expect(await screen.findByRole('alert')).toHaveTextContent('Failed to load dashboard');
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolios) : path === '/calendar/upcoming' ? Promise.resolve({ data: { data: [] } }) : path === '/dashboard' ? Promise.resolve(dashboard(444)) : Promise.resolve(patterns('CCC')));
    fireEvent.click(screen.getByRole('button', { name: 'Refresh dashboard' }));
    expect(await screen.findByText(/444/)).toBeVisible();
});

it('restores current profile cache and never writes a stale A result after B is selected', async () => {
    writeDashboardCache(1, 1, { dashboard: dashboard(111).data, patternRows: [{ stock_id: 8, symbol: 'CACHED_A', pattern_name: 'Cache pattern' }] });
    const lateA = deferred();
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve(portfolios);
        if (path === '/calendar/upcoming') return Promise.resolve({ data: { data: [] } });
        if (path === '/dashboard' && sessionStorage.getItem('portfolio_active_id') === '1') return lateA.promise;
        return Promise.resolve(path === '/dashboard' ? dashboard(222) : patterns('BBB'));
    });
    mount(); expect(await screen.findByText(/111/)).toBeVisible(); expect(screen.getByText('CACHED_A')).toBeVisible();
    expect(api.get).not.toHaveBeenCalledWith('/dashboard');
    fireEvent.click(screen.getByRole('button', { name: 'Refresh dashboard' })); await waitFor(() => expect(api.get).toHaveBeenCalledWith('/dashboard'));
    switchToB(); expect(await screen.findByText(/222/)).toBeVisible();
    await act(async () => lateA.resolve(dashboard(999)));
    expect(readDashboardCache(1, 1)).toBeNull();
    expect(readDashboardCache(1, 2)?.dashboard.portfolio_value).toBe(222);
});

it('does not apply a pending result after Dashboard unmount', async () => {
    const request = deferred();
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolios) : path === '/calendar/upcoming' ? Promise.resolve({ data: { data: [] } }) : path === '/dashboard' ? request.promise : Promise.resolve(patterns('AAA')));
    const view = mount(); await waitFor(() => expect(api.get).toHaveBeenCalledWith('/dashboard')); view.unmount();
    await act(async () => request.resolve(dashboard(987)));
    expect(readDashboardCache(1, 1)).toBeNull();
});

it('preserves same-scope rebuild POST payload and success copy', async () => {
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolios) : path === '/calendar/upcoming' ? Promise.resolve({ data: { data: [] } }) : Promise.resolve(path === '/dashboard' ? dashboard(100) : patterns('AAA')));
    api.post.mockResolvedValue({ data: { rebuild: { snapshots_written: 4 } } });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const { showToast } = await import('../../../resources/js/src/toast');
    mount(); await screen.findByText(/100/); fireEvent.click(screen.getByRole('button', { name: 'Rebuild history' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/portfolio/rebuild-history'));
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Portfolio history rebuilt (4 snapshots).'));
    expect(api.post).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(api.get.mock.calls.filter(([path]) => path === '/dashboard')).toHaveLength(2));
});

it('preserves same-scope alert clear POST, response copy, and reload', async () => {
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolios) : path === '/calendar/upcoming' ? Promise.resolve({ data: { data: [] } }) : Promise.resolve(path === '/dashboard' ? dashboard(100, { alerts: [{ id: 14, message: 'Alert row' }] }) : patterns('AAA')));
    api.post.mockResolvedValue({ data: { message: 'Alerts cleared successfully.' } });
    const { showToast } = await import('../../../resources/js/src/toast');
    mount(); await screen.findByText('Alert row'); fireEvent.click(screen.getByRole('button', { name: 'Clear all' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/alerts/expire-all'));
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Alerts cleared successfully.'));
    expect(api.post).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(api.get.mock.calls.filter(([path]) => path === '/dashboard')).toHaveLength(2));
});

 it('keeps same-scope reads functional with StrictMode at the fixture root', async () => {
    render(<React.StrictMode><MemoryRouter><AuthContext.Provider value={{ loading: false, isAuthenticated: true, user: { id: 1, default_portfolio_id: 1, is_admin: false } }}><PortfolioProvider><PortfolioSwitcher /><DashboardPage /></PortfolioProvider></AuthContext.Provider></MemoryRouter></React.StrictMode>);
    expect(await screen.findByText(/100/)).toBeVisible();
    expect(screen.getByText('AAA')).toBeVisible();
    expect(api.post).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh dashboard' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Refresh dashboard' })).toBeEnabled());
    expect(screen.getByText(/100/)).toBeVisible();
});
