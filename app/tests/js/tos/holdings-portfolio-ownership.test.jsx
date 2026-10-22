import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import AuthContext from '../../../resources/js/src/context/AuthContext';
import { PortfolioProvider, usePortfolio } from '../../../resources/js/src/context/PortfolioContext';
import PortfolioSwitcher from '../../../resources/js/src/components/PortfolioSwitcher';
import HoldingsPage from '../../../resources/js/src/pages/HoldingsPage';

vi.mock('../../../resources/js/src/api', async (load) => ({ ...(await load()), default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../../resources/js/src/components/DataTable', () => ({
    DataTableColumnMenu: () => null,
    useDataTableController: (props) => props,
    // The page's default is Complex; expose that visible controller once, not its hidden Simple table.
    DataTableView: ({ controller, loading }) => controller.storageKey !== 'holdings' ? null : <section>{loading ? 'Holdings loading' : controller.data.map((row) => <div key={row.id}><span>{row.stock.symbol}</span>{controller.columns.at(-1).cell({ row: { original: row } })}</div>)}</section>,
}));
vi.mock('../../../resources/js/src/components/AnalyseStockButton', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/ComboButton', () => ({ default: ({ label, onPrimaryClick, menuItems = [] }) => <span><button onClick={onPrimaryClick}>{label}</button>{menuItems.map((item) => <button key={item.label} onClick={item.onClick}>{item.label}</button>)}</span> }));
vi.mock('../../../resources/js/src/components/SegmentToggle', () => ({ default: () => null }));

const deferred = () => { let resolve; let reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const portfolioResponse = { data: { data: [{ id: 1, name: 'Main', is_default: true }, { id: 2, name: 'Research' }] } };
const row = (id, symbol, extras = {}) => ({ id, stock_id: id, stock: { id, symbol, name: `${symbol} Ltd` }, quantity: 4, avg_buy_price: 12, invested_amount: 48, stoploss_summary: { latest_close: 14 }, ...extras });
const rows = (symbol, extras) => ({ data: { data: [row(symbol === 'AAA' ? 11 : symbol === 'BBB' ? 22 : 33, symbol, extras)] } });
const modeResponse = (mode = 'manual') => ({ data: { data: { execution_mode: mode, execution_code_label: 'Test authenticator code' } } });
const protectionResponse = (data = []) => ({ data: { data } });
function BatchSwitch() { const { setActivePortfolio } = usePortfolio(); return <button onClick={() => { setActivePortfolio(2); setActivePortfolio(1); }}>Batch A-B-A</button>; }
function SameScopeRefresh() { const { setActivePortfolio } = usePortfolio(); return <button onClick={() => setActivePortfolio(1)}>Refresh same portfolio</button>; }
function RefreshCatalog() { const { bootstrap } = usePortfolio(); return <button onClick={() => { bootstrap(); }}>Refresh Portfolio list</button>; }
function LocationProbe() { const location = useLocation(); return <output>{location.pathname}:{JSON.stringify(location.state || {})}</output>; }
function mount({ strict = false, extra = null } = {}) {
    const tree = <MemoryRouter><AuthContext.Provider value={{ loading: false, isAuthenticated: true, user: { id: 7, default_portfolio_id: 1 } }}><PortfolioProvider><PortfolioSwitcher />{extra}<HoldingsPage /><LocationProbe /></PortfolioProvider></AuthContext.Provider></MemoryRouter>;
    return render(strict ? <React.StrictMode>{tree}</React.StrictMode> : tree);
}
function switchToB() { fireEvent.click(screen.getByTitle('Switch portfolio (per tab)')); fireEvent.click(screen.getByRole('button', { name: 'Research', exact: true })); }
function baseApi() {
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolioResponse)
        : path === '/holdings' ? Promise.resolve(sessionStorage.getItem('portfolio_active_id') === '2' ? rows('BBB') : rows('AAA'))
            : path === '/v1/execution/mode' ? Promise.resolve(modeResponse()) : path === '/v1/protections' ? Promise.resolve(protectionResponse())
                : path === '/v1/strategy-registry' ? Promise.resolve({ data: { data: [{ id: 9, name: 'Strategy Nine', status: 'active' }] } }) : Promise.resolve({ data: { data: [] } }));
}
beforeEach(() => { api.get.mockReset(); api.post.mockReset(); sessionStorage.clear(); localStorage.clear(); sessionStorage.setItem('portfolio_active_id', '1'); baseApi(); });
afterEach(() => { cleanup(); api.get.mockClear(); api.post.mockClear(); vi.restoreAllMocks(); });

it('keeps B rows when a genuinely pending ordinary A holdings response arrives last', async () => {
    const lateA = deferred(); let obsoleteNestedReads = 0;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve(portfolioResponse);
        if (path === '/holdings' && sessionStorage.getItem('portfolio_active_id') === '1') return lateA.promise;
        if ((path === '/v1/execution/mode' || path === '/v1/protections') && sessionStorage.getItem('portfolio_active_id') === '1') obsoleteNestedReads += 1;
        return path === '/holdings' ? Promise.resolve(rows('BBB')) : path === '/v1/execution/mode' ? Promise.resolve(modeResponse()) : Promise.resolve(protectionResponse());
    });
    mount(); await waitFor(() => expect(api.get).toHaveBeenCalledWith('/holdings'));
    switchToB(); expect(await screen.findByText('BBB')).toBeVisible();
    const nestedReads = api.get.mock.calls.filter(([path]) => ['/v1/execution/mode', '/v1/protections'].includes(path)).length;
    await act(async () => { lateA.resolve(rows('AAA')); });
    expect(api.get.mock.calls.filter(([path]) => ['/v1/execution/mode', '/v1/protections'].includes(path))).toHaveLength(nestedReads);
    expect(screen.getByText('BBB')).toBeVisible(); expect(screen.queryByText('AAA')).toBeNull(); expect(obsoleteNestedReads).toBe(0);
});

it('ignores late A holdings and nested mode/protections after B became active', async () => {
    const lateRows = deferred(); const lateMode = deferred(); const lateProtections = deferred(); let aProtectionsStarted = 0; let aModeStarted = 0;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve(portfolioResponse);
        if (sessionStorage.getItem('portfolio_active_id') === '1' && path === '/holdings') return lateRows.promise;
        if (sessionStorage.getItem('portfolio_active_id') === '1' && path === '/v1/execution/mode') { aModeStarted += 1; return lateMode.promise; }
        if (sessionStorage.getItem('portfolio_active_id') === '1' && path === '/v1/protections') { aProtectionsStarted += 1; return lateProtections.promise; }
        if (path === '/holdings') return Promise.resolve(rows('BBB'));
        if (path === '/v1/execution/mode') return Promise.resolve(modeResponse());
        return Promise.resolve(protectionResponse());
    });
    mount(); await waitFor(() => expect(api.get).toHaveBeenCalledWith('/holdings'));
    await act(async () => { lateRows.resolve(rows('AAA')); });
    await waitFor(() => { expect(aProtectionsStarted).toBeGreaterThan(0); expect(aModeStarted).toBeGreaterThan(0); });
    switchToB(); expect(await screen.findByText('BBB')).toBeVisible();
    await act(async () => { lateMode.resolve(modeResponse('semi_automatic')); lateProtections.resolve(protectionResponse()); });
    expect(screen.getByText('BBB')).toBeVisible(); expect(screen.queryByText('AAA')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Place GTT Target' })).toBeNull();
});

it('gives batched A-B-A a new scope and fresh A read without relying on StrictMode', async () => {
    let reads = 0; const first = deferred();
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolioResponse) : path === '/holdings' ? (++reads === 1 ? first.promise : Promise.resolve(rows('AAA'))) : path === '/v1/execution/mode' ? Promise.resolve(modeResponse()) : Promise.resolve(protectionResponse()));
    mount({ extra: <BatchSwitch /> }); await waitFor(() => expect(reads).toBe(1));
    fireEvent.click(screen.getByRole('button', { name: 'Batch A-B-A' }));
    await waitFor(() => expect(reads).toBeGreaterThan(1)); expect(await screen.findByText('AAA')).toBeVisible();
    const freshReads = reads; await act(async () => { first.resolve(rows('OLD')); });
    expect(reads).toBe(freshReads); expect(screen.queryByText('OLD')).toBeNull();
});

it('does not read scoped rows before stale or null portfolio bootstrap is confirmed', async () => {
    sessionStorage.setItem('portfolio_active_id', '99'); const bootstrap = deferred();
    api.get.mockImplementation((path) => path === '/portfolios' ? bootstrap.promise : Promise.resolve(rows('AAA')));
    mount(); expect(api.get.mock.calls.filter(([path]) => path === '/holdings' || path === '/v1/protections').length).toBe(0);
    await act(async () => { bootstrap.resolve(portfolioResponse); });
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/holdings'));
    cleanup(); api.get.mockClear(); sessionStorage.removeItem('portfolio_active_id');
    const nullBootstrap = deferred(); api.get.mockImplementation((path) => path === '/portfolios' ? nullBootstrap.promise : Promise.resolve(rows('AAA')));
    mount(); expect(api.get.mock.calls.some(([path]) => path === '/holdings')).toBe(false);
    await act(async () => { nullBootstrap.resolve(portfolioResponse); }); await waitFor(() => expect(api.get).toHaveBeenCalledWith('/holdings'));
});

it('ignores a prior strategy registry success and error after adoption dialog replacement', async () => {
    const oldStrategy = deferred(); let strategyReads = 0;
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolioResponse) : path === '/holdings' ? Promise.resolve(sessionStorage.getItem('portfolio_active_id') === '2' ? rows('BBB', { is_unmanaged: true }) : rows('AAA', { is_unmanaged: true })) : path === '/v1/execution/mode' ? Promise.resolve(modeResponse()) : path === '/v1/protections' ? Promise.resolve(protectionResponse()) : path === '/v1/strategy-registry' ? (++strategyReads === 1 ? oldStrategy.promise : Promise.resolve({ data: { data: [{ id: 22, name: 'Current', status: 'active' }] } })) : Promise.resolve({ data: { data: [] } }));
    mount(); await screen.findByText('AAA'); fireEvent.click(screen.getByRole('button', { name: 'Adopt' }));
    await waitFor(() => expect(strategyReads).toBe(1)); switchToB(); await screen.findByText('BBB');
    fireEvent.click(screen.getByRole('button', { name: 'Adopt' })); await waitFor(() => expect(strategyReads).toBe(2));
    expect(await screen.findByLabelText('Destination strategy')).toHaveValue('22');
    await act(async () => { oldStrategy.resolve({ data: { data: [{ id: 99, name: 'Stale', status: 'active' }] } }); });
    expect(screen.getByLabelText('Destination strategy')).toHaveValue('22');
});

it('ignores a stale strategy registry rejection after the replacement adoption strategy is loaded', async () => {
    const oldStrategy = deferred(); let reads = 0;
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolioResponse) : path === '/holdings' ? Promise.resolve(sessionStorage.getItem('portfolio_active_id') === '2' ? rows('BBB', { is_unmanaged: true }) : rows('AAA', { is_unmanaged: true })) : path === '/v1/execution/mode' ? Promise.resolve(modeResponse()) : path === '/v1/protections' ? Promise.resolve(protectionResponse()) : path === '/v1/strategy-registry' ? (++reads === 1 ? oldStrategy.promise : Promise.resolve({ data: { data: [{ id: 22, name: 'Replacement', status: 'active' }] } })) : Promise.resolve({ data: { data: [] } }));
    mount(); await screen.findByText('AAA'); fireEvent.click(screen.getByRole('button', { name: 'Adopt' })); await waitFor(() => expect(reads).toBe(1));
    switchToB(); await screen.findByText('BBB'); fireEvent.click(screen.getByRole('button', { name: 'Adopt' }));
    await waitFor(() => expect(reads).toBe(2)); await screen.findByLabelText('Destination strategy');
    await act(async () => { oldStrategy.reject(new Error('stale strategy error')); });
    expect(screen.getByLabelText('Destination strategy')).toHaveValue('22'); expect(screen.queryByText('Could not load strategies.')).toBeNull();
});

it('keeps a new adoption busy state when an older POST succeeds or fails late', async () => {
    const oldPost = deferred(); let posts = 0;
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolioResponse) : path === '/holdings' ? Promise.resolve(sessionStorage.getItem('portfolio_active_id') === '2' ? rows('BBB', { is_unmanaged: true }) : rows('AAA', { is_unmanaged: true })) : path === '/v1/execution/mode' ? Promise.resolve(modeResponse()) : path === '/v1/protections' ? Promise.resolve(protectionResponse()) : path === '/v1/strategy-registry' ? Promise.resolve({ data: { data: [{ id: 9, name: 'Ready', status: 'active' }] } }) : Promise.resolve({ data: { data: [] } }));
    api.post.mockImplementation(() => (++posts === 1 ? oldPost.promise : new Promise(() => {})));
    mount(); await screen.findByText('AAA'); fireEvent.click(screen.getByRole('button', { name: 'Adopt' })); await waitFor(() => expect(screen.getByLabelText('Destination strategy')).toHaveValue('9')); fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Adopt', exact: true }));
    await waitFor(() => expect(posts).toBe(1)); switchToB(); await screen.findByText('BBB');
    fireEvent.click(screen.getByRole('button', { name: 'Adopt' })); await screen.findByLabelText('Destination strategy');
    await waitFor(() => expect(screen.getByLabelText('Destination strategy')).toHaveValue('9'));
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Adopt', exact: true }));
    await waitFor(() => expect(posts).toBe(2));
    expect(api.post).toHaveBeenNthCalledWith(1, '/holdings/11/adopt', { strategy_id: 9 });
    expect(api.post).toHaveBeenNthCalledWith(2, '/holdings/22/adopt', { strategy_id: 9 });
    await act(async () => { oldPost.resolve({}); });
    expect(screen.getByRole('button', { name: 'Adopting…' })).toBeDisabled();
});

it('ignores an old adoption POST error after a replacement dialog has its own busy request', async () => {
    const oldPost = deferred(); let posts = 0; let strategyReads = 0;
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolioResponse) : path === '/holdings' ? Promise.resolve(sessionStorage.getItem('portfolio_active_id') === '2' ? rows('BBB', { is_unmanaged: true }) : rows('AAA', { is_unmanaged: true })) : path === '/v1/execution/mode' ? Promise.resolve(modeResponse()) : path === '/v1/protections' ? Promise.resolve(protectionResponse()) : path === '/v1/strategy-registry' ? (++strategyReads, Promise.resolve({ data: { data: [{ id: 9, name: 'Ready', status: 'active' }] } })) : Promise.resolve({ data: { data: [] } }));
    api.post.mockImplementation(() => (++posts === 1 ? oldPost.promise : new Promise(() => {})));
    mount(); await screen.findByText('AAA'); fireEvent.click(screen.getByRole('button', { name: 'Adopt' }));
    await waitFor(() => expect(screen.getByLabelText('Destination strategy')).toHaveValue('9'));
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Adopt', exact: true })); await waitFor(() => expect(posts).toBe(1));
    switchToB(); await screen.findByText('BBB'); fireEvent.click(screen.getByRole('button', { name: 'Adopt' }));
    await waitFor(() => expect(strategyReads).toBe(2)); await waitFor(() => expect(screen.getByLabelText('Destination strategy')).toHaveValue('9'));
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Adopt', exact: true })); await waitFor(() => expect(posts).toBe(2));
    await act(async () => { oldPost.reject({ response: { data: { message: 'stale adoption error' } } }); });
    expect(screen.getByRole('button', { name: 'Adopting…' })).toBeDisabled(); expect(screen.queryByText('stale adoption error')).toBeNull();
});

it('keeps a new protection prompt after an old protection POST rejects', async () => {
    const oldPost = deferred(); let posts = 0;
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolioResponse) : path === '/holdings' ? Promise.resolve(sessionStorage.getItem('portfolio_active_id') === '2' ? rows('BBB') : rows('AAA')) : path === '/v1/execution/mode' ? Promise.resolve(modeResponse('semi_automatic')) : path === '/v1/protections' ? Promise.resolve(protectionResponse()) : Promise.resolve({ data: { data: [] } }));
    api.post.mockImplementation(() => (++posts === 1 ? oldPost.promise : new Promise(() => {})));
    mount(); await screen.findByText('AAA'); fireEvent.click(screen.getAllByRole('button', { name: 'Place GTT Target', exact: true })[0]);
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirm', exact: true }));
    await waitFor(() => expect(posts).toBe(1));
    switchToB(); await screen.findByText('BBB'); fireEvent.click(screen.getAllByRole('button', { name: 'Place GTT Target', exact: true })[0]);
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirm', exact: true })); await waitFor(() => expect(posts).toBe(2));
    expect(api.post).toHaveBeenNthCalledWith(1, '/v1/protections', { holding_id: 11, type: 'target', totp: undefined });
    expect(api.post).toHaveBeenNthCalledWith(2, '/v1/protections', { holding_id: 22, type: 'target', totp: undefined });
    await act(async () => { oldPost.reject(new Error('old error')); });
    expect(within(screen.getByRole('dialog')).getByRole('button', { name: 'Submitting…' })).toBeDisabled();
    expect(screen.queryByText('old error')).toBeNull();
});

it('ignores an old protection success without clearing a replacement prompt busy state', async () => {
    const oldPost = deferred(); let posts = 0;
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolioResponse) : path === '/holdings' ? Promise.resolve(sessionStorage.getItem('portfolio_active_id') === '2' ? rows('BBB') : rows('AAA')) : path === '/v1/execution/mode' ? Promise.resolve(modeResponse('semi_automatic')) : path === '/v1/protections' ? Promise.resolve(protectionResponse()) : Promise.resolve({ data: { data: [] } }));
    api.post.mockImplementation(() => (++posts === 1 ? oldPost.promise : new Promise(() => {})));
    mount(); await screen.findByText('AAA'); fireEvent.click(screen.getAllByRole('button', { name: 'Place GTT Target', exact: true })[0]);
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirm' })); await waitFor(() => expect(posts).toBe(1));
    switchToB(); await screen.findByText('BBB'); fireEvent.click(screen.getAllByRole('button', { name: 'Place GTT Stop-Loss', exact: true })[0]);
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirm' })); await waitFor(() => expect(posts).toBe(2));
    await act(async () => { oldPost.resolve({}); });
    expect(api.post).toHaveBeenNthCalledWith(1, '/v1/protections', { holding_id: 11, type: 'target', totp: undefined });
    expect(api.post).toHaveBeenNthCalledWith(2, '/v1/protections', { holding_id: 22, type: 'stop', totp: undefined });
    expect(within(screen.getByRole('dialog')).getByRole('button', { name: 'Submitting…' })).toBeDisabled();
});

it('guards synchronous storage changes, preserves same-scope navigation, and scopes unmount work', async () => {
    baseApi(); const view = mount(); await screen.findByText('AAA');
    fireEvent.click(screen.getByRole('button', { name: 'Sell', exact: true }));
    const sellLocation = screen.getByText(/\/transactions:/).textContent;
    expect(JSON.parse(sellLocation.slice(sellLocation.indexOf(':') + 1))).toEqual({ sellPrefill: { stock: { id: 11, symbol: 'AAA', name: 'AAA Ltd', exchange: 'NSE' }, quantity: 4, price: 14, owner_key: null, strategy_id: null } });
    fireEvent.click(screen.getByRole('button', { name: 'Split/Bonus' }));
    const corporateActionLocation = screen.getByText(/\/corporate-action:/).textContent;
    expect(JSON.parse(corporateActionLocation.slice(corporateActionLocation.indexOf(':') + 1))).toEqual({ corporateActionStock: { stock_id: 11, symbol: 'AAA', name: 'AAA Ltd', exchange: 'NSE' } });
    sessionStorage.setItem('portfolio_active_id', '2'); fireEvent.click(screen.getByRole('button', { name: 'Sell', exact: true }));
    expect(screen.getByText(/\/corporate-action:/)).toBeVisible();
    view.unmount();
    const pending = deferred(); api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolioResponse) : path === '/holdings' ? pending.promise : Promise.resolve(modeResponse()));
    api.get.mockClear();
    mount(); await waitFor(() => expect(api.get).toHaveBeenCalledWith('/holdings'));
    cleanup(); api.get.mockClear(); await act(async () => { pending.resolve(rows('UNMOUNTED')); });
    expect(screen.queryByText('UNMOUNTED')).toBeNull(); expect(api.get).not.toHaveBeenCalled();
});

it('refuses adoption POST after synchronous storage scope change before confirmation', async () => {
    const defaultGet = api.get.getMockImplementation();
    api.get.mockImplementation((path) => path === '/holdings' ? Promise.resolve(rows('AAA', { is_unmanaged: true })) : defaultGet(path));
    mount(); await screen.findByText('AAA');
    fireEvent.click(screen.getByRole('button', { name: 'Adopt' })); await waitFor(() => expect(screen.getByLabelText('Destination strategy')).toHaveValue('9'));
    sessionStorage.setItem('portfolio_active_id', '2');
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Adopt', exact: true }));
    expect(api.post).not.toHaveBeenCalled();
});

it('refuses protection POST after synchronous storage scope change before confirmation', async () => {
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolioResponse) : path === '/holdings' ? Promise.resolve(rows('AAA')) : path === '/v1/execution/mode' ? Promise.resolve(modeResponse('semi_automatic')) : path === '/v1/protections' ? Promise.resolve(protectionResponse()) : Promise.resolve({ data: { data: [] } }));
    mount(); await screen.findByText('AAA'); fireEvent.click(screen.getAllByRole('button', { name: 'Place GTT Target', exact: true })[0]);
    sessionStorage.setItem('portfolio_active_id', '2'); fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirm' }));
    expect(api.post).not.toHaveBeenCalled();
});

it('runs current-scope reads with StrictMode at the fixture root', async () => {
    mount({ strict: true }); await screen.findByText('AAA');
});

it('keeps the latest read after repeated same-Portfolio selection events', async () => {
    let reads = 0; const first = deferred(); const second = deferred();
    api.get.mockImplementation((path) => path === '/portfolios' ? Promise.resolve(portfolioResponse) : path === '/holdings' ? (++reads === 1 ? Promise.resolve(rows('AAA')) : reads === 2 ? first.promise : second.promise) : path === '/v1/execution/mode' ? Promise.resolve(modeResponse()) : Promise.resolve(protectionResponse()));
    mount({ extra: <SameScopeRefresh /> }); await screen.findByText('AAA');
    fireEvent.click(screen.getByRole('button', { name: 'Refresh same portfolio' })); await waitFor(() => expect(reads).toBe(2));
    fireEvent.click(screen.getByRole('button', { name: 'Refresh same portfolio' })); await waitFor(() => expect(reads).toBe(3));
    await act(async () => { second.resolve(rows('LATEST')); }); await waitFor(() => expect(screen.getByText('LATEST')).toBeVisible());
    await act(async () => { first.resolve(rows('STALE')); }); expect(screen.getByText('LATEST')).toBeVisible(); expect(screen.queryByText('STALE')).toBeNull();
});


it('preserves a current adoption dialog through a benign Portfolio catalogue refresh', async () => {
    const refresh = deferred(); let catalogueReads = 0;
    const defaultGet = api.get.getMockImplementation();
    api.get.mockImplementation((path) => path === '/portfolios' ? (++catalogueReads === 1 ? Promise.resolve(portfolioResponse) : refresh.promise) : path === '/holdings' ? Promise.resolve(rows('AAA', { is_unmanaged: true })) : defaultGet(path));
    mount({ extra: <RefreshCatalog /> }); await screen.findByText('AAA');
    fireEvent.click(screen.getByRole('button', { name: 'Adopt', exact: true }));
    await waitFor(() => expect(screen.getByLabelText('Destination strategy')).toHaveValue('9'));
    fireEvent.click(screen.getByRole('button', { name: 'Refresh Portfolio list' }));
    await waitFor(() => expect(catalogueReads).toBe(2));
    expect(screen.getByLabelText('Destination strategy')).toHaveValue('9');
    await act(async () => { refresh.resolve(portfolioResponse); });
    expect(screen.getByLabelText('Destination strategy')).toHaveValue('9'); expect(api.post).not.toHaveBeenCalled();
});

it('preserves a same-scope successful adoption payload, close and single reload', async () => {
    const defaultGet = api.get.getMockImplementation();
    api.get.mockImplementation((path) => path === '/holdings' ? Promise.resolve(rows('AAA', { is_unmanaged: true })) : defaultGet(path));
    api.post.mockResolvedValue({ data: { data: {} } });
    mount(); await screen.findByText('AAA'); fireEvent.click(screen.getByRole('button', { name: 'Adopt', exact: true }));
    await waitFor(() => expect(screen.getByLabelText('Destination strategy')).toHaveValue('9'));
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Adopt', exact: true }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(api.get.mock.calls.filter(([path]) => path === '/holdings')).toHaveLength(2));
    expect(api.post).toHaveBeenCalledTimes(1); expect(api.post).toHaveBeenCalledWith('/holdings/11/adopt', { strategy_id: 9 });
});

it.each([
    { label: 'Place GTT Target', path: '/v1/protections', body: { holding_id: 11, type: 'target', totp: '123456' } },
    { label: 'Cancel protection', path: '/v1/protections/55/cancel', body: { totp: '123456' } },
])('preserves same-scope $label confirmation payload and reload', async ({ label, path, body }) => {
    const defaultGet = api.get.getMockImplementation();
    api.get.mockImplementation((url) => url === '/v1/execution/mode' ? Promise.resolve(modeResponse('semi_automatic')) : url === '/v1/protections' ? Promise.resolve(protectionResponse([{ id: 55, holding_id: 11, state: 'active', type: 'target' }])) : defaultGet(url));
    api.post.mockResolvedValue({ data: { data: {} } });
    mount(); await screen.findByText('AAA'); fireEvent.click(screen.getByRole('button', { name: label, exact: true }));
    fireEvent.change(screen.getByLabelText('Test authenticator code'), { target: { value: '123456' } });
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirm', exact: true }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(api.get.mock.calls.filter(([url]) => url === '/holdings')).toHaveLength(2));
    expect(api.post).toHaveBeenCalledTimes(1); expect(api.post).toHaveBeenCalledWith(path, body);
});


it('retains only the reopened same-Portfolio adoption session after an ordinary close', async () => {
    const oldStrategy = deferred(); let strategyReads = 0; const defaultGet = api.get.getMockImplementation();
    api.get.mockImplementation((path) => path === '/holdings' ? Promise.resolve(rows('AAA', { is_unmanaged: true })) : path === '/v1/strategy-registry' ? (++strategyReads === 1 ? oldStrategy.promise : Promise.resolve({ data: { data: [{ id: 22, name: 'Reopened strategy', status: 'active' }] } })) : defaultGet(path));
    mount(); await screen.findByText('AAA'); fireEvent.click(screen.getByRole('button', { name: 'Adopt', exact: true }));
    await waitFor(() => expect(strategyReads).toBe(1)); fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancel', exact: true }));
    fireEvent.click(screen.getByRole('button', { name: 'Adopt', exact: true })); await waitFor(() => expect(strategyReads).toBe(2));
    await waitFor(() => expect(screen.getByLabelText('Destination strategy')).toHaveValue('22'));
    await act(async () => { oldStrategy.resolve({ data: { data: [{ id: 99, name: 'Obsolete strategy', status: 'active' }] } }); });
    expect(screen.getByLabelText('Destination strategy')).toHaveValue('22'); expect(screen.queryByRole('option', { name: 'Obsolete strategy' })).toBeNull(); expect(api.post).not.toHaveBeenCalled();
});
