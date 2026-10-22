import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import { PortfolioProvider, usePortfolio } from '../../../resources/js/src/context/PortfolioContext';
import AuthContext from '../../../resources/js/src/context/AuthContext';
import PortfolioSwitcher from '../../../resources/js/src/components/PortfolioSwitcher';
import ReviewDashboardPage from '../../../resources/js/src/pages/ReviewDashboardPage';
import { showToast } from '../../../resources/js/src/toast';

vi.mock('../../../resources/js/src/api', async () => { const actual = await vi.importActual('../../../resources/js/src/api'); return { default: { get: vi.fn(), post: vi.fn() }, getApiErrorMessage: actual.getApiErrorMessage }; });
vi.mock('../../../resources/js/src/context/AuthContext', async importOriginal => { const actual = await importOriginal(); return { ...actual, useAuth: () => ({ loading: false, isAuthenticated: true, user: { id: 1, default_portfolio_id: 1, is_admin: false } }) }; });
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
const deferred = () => { let resolve; let reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
beforeEach(() => { cleanup(); vi.resetAllMocks(); sessionStorage.clear(); sessionStorage.setItem('portfolio_active_id', '1'); });
afterEach(() => { cleanup(); vi.restoreAllMocks(); });
const portfolios = () => ({ data: { data: [{ id: 1, name: 'Main', is_default: true }, { id: 2, name: 'Research' }] } });
const dashboard = (value = 111) => ({ data: { data: { portfolio: { portfolio_value: value }, outcomes: [], recent_reviews: [] } } });
const orderList = (orders = []) => ({ data: { data: orders } });
function BatchSwitch() {
    const { setActivePortfolio } = usePortfolio();
    return <button type="button" onClick={() => { setActivePortfolio(2); setActivePortfolio(1); }}>Batch A-B-A</button>;
}
function mount(extra = null) {
    return render(<MemoryRouter><AuthContext.Provider value={{ loading: false, isAuthenticated: true, user: { id: 1, default_portfolio_id: 1, is_admin: false } }}><PortfolioProvider><PortfolioSwitcher />{extra}<ReviewDashboardPage /></PortfolioProvider></AuthContext.Provider></MemoryRouter>);
}
function switchTo(name = 'Research') {
    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(screen.getByRole('button', { name, exact: true }));
}

it('hides confirmed A data before B reads settle and ignores a genuinely pending A success', async () => {
    const aDash = deferred(); const aOrders = deferred(); const bDash = deferred(); const bOrders = deferred(); const calls = [];
    api.get.mockImplementation(path => {
        if (path === '/portfolios') return Promise.resolve(portfolios());
        const scope = sessionStorage.getItem('portfolio_active_id'); calls.push([path, scope]);
        if (scope === '1') return path.endsWith('/orders') ? aOrders.promise : aDash.promise;
        return path.endsWith('/orders') ? bOrders.promise : bDash.promise;
    });
    mount();
    await waitFor(() => expect(calls).toContainEqual(['/v1/orders', '1']));
    await act(async () => { aDash.resolve(dashboard(111)); aOrders.resolve(orderList()); });
    expect(await screen.findByText(/111/)).toBeVisible();
    const lateADash = deferred(); const lateAOrders = deferred();
    api.get.mockImplementation(path => {
        if (path === '/portfolios') return Promise.resolve(portfolios());
        const scope = sessionStorage.getItem('portfolio_active_id'); calls.push([path, scope]);
        if (scope === '1') return path.endsWith('/orders') ? lateAOrders.promise : lateADash.promise;
        return path.endsWith('/orders') ? bOrders.promise : bDash.promise;
    });
    // Start a fresh, real A read from Refresh before changing portfolio.
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
    await waitFor(() => expect(calls.filter(([, scope]) => scope === '1')).toHaveLength(4));
    switchTo();
    expect(screen.getByText('Loading…')).toBeVisible();
    expect(screen.queryByText(/111/)).toBeNull();
    await waitFor(() => expect(calls.filter(([, scope]) => scope === '2')).toHaveLength(2));
    await act(async () => { lateADash.resolve(dashboard(999)); lateAOrders.resolve(orderList()); });
    expect(screen.queryByText(/999/)).toBeNull();
    await act(async () => { bDash.resolve(dashboard(222)); bOrders.resolve(orderList()); });
    expect(await screen.findByText(/222/)).toBeVisible();
    expect(screen.queryByText(/111/)).toBeNull();
});

it('ignores a genuinely pending A read error after B becomes current', async () => {
    const aDash = deferred(); const aOrders = deferred(); const bDash = deferred(); const bOrders = deferred(); const calls = [];
    api.get.mockImplementation(path => {
        if (path === '/portfolios') return Promise.resolve(portfolios());
        const scope = sessionStorage.getItem('portfolio_active_id'); calls.push([path, scope]);
        return scope === '1' ? (path.endsWith('/orders') ? aOrders.promise : aDash.promise) : (path.endsWith('/orders') ? bOrders.promise : bDash.promise);
    });
    mount(); await waitFor(() => expect(calls).toContainEqual(['/v1/orders', '1']));
    switchTo(); await waitFor(() => expect(calls).toContainEqual(['/v1/orders', '2']));
    await act(async () => { aDash.reject({ response: { status: 503, data: { message: 'old A failure' } } }); aOrders.reject(new Error('old A orders failure')); });
    expect(screen.queryByRole('alert')).toBeNull(); expect(showToast).not.toHaveBeenCalled();
    await act(async () => { bDash.resolve(dashboard(222)); bOrders.resolve(orderList()); });
    expect(await screen.findByText(/222/)).toBeVisible();
});

it('keeps the latest paired read when an action reload overlaps Refresh', async () => {
    const cancel = deferred(); const pairs = []; let deferReads = false;
    const orders = [{ id: 17, status: 'pending', broker_status: 'unknown' }];
    api.get.mockImplementation(path => {
        if (path === '/portfolios') return Promise.resolve(portfolios());
        if (!deferReads) return Promise.resolve(path.endsWith('/orders') ? orderList(orders) : dashboard(111));
        if (path === '/v1/review/dashboard') { const pair = { dash: deferred(), orders: deferred() }; pairs.push(pair); return pair.dash.promise; }
        return pairs[pairs.length - 1].orders.promise;
    });
    api.post.mockReturnValue(cancel.promise);
    mount(); await screen.findByText(/111/);
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/orders/17/cancel', null, { skipErrorToast: true }));
    deferReads = true;
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
    await waitFor(() => expect(pairs).toHaveLength(1));
    await act(async () => cancel.resolve({ data: { data: { cancellation_status: 'confirmed' } } }));
    await waitFor(() => expect(pairs).toHaveLength(2));
    await act(async () => { pairs[1].dash.resolve(dashboard(333)); pairs[1].orders.resolve(orderList(orders)); });
    expect(await screen.findByText(/333/)).toBeVisible();
    await act(async () => { pairs[0].dash.resolve(dashboard(999)); pairs[0].orders.resolve(orderList()); });
    expect(screen.queryByText(/999/)).toBeNull();
    expect(screen.getByText(/333/)).toBeVisible();
    expect(api.post).toHaveBeenCalledTimes(1);
});

it('invalidates the first A paired read across a batched A-B-A event sequence', async () => {
    const pairs = [];
    api.get.mockImplementation(path => {
        if (path === '/portfolios') return Promise.resolve(portfolios());
        if (path === '/v1/review/dashboard') { const pair = { dash: deferred(), orders: deferred() }; pairs.push(pair); return pair.dash.promise; }
        return pairs[pairs.length - 1].orders.promise;
    });
    mount(<BatchSwitch />);
    await waitFor(() => expect(pairs).toHaveLength(1));
    fireEvent.click(screen.getByRole('button', { name: 'Batch A-B-A' }));
    await waitFor(() => expect(pairs).toHaveLength(2));
    await act(async () => { pairs[1].dash.resolve(dashboard(333)); pairs[1].orders.resolve(orderList()); });
    expect(await screen.findByText(/333/)).toBeVisible();
    await act(async () => { pairs[0].dash.resolve(dashboard(555)); pairs[0].orders.resolve(orderList()); });
    expect(screen.queryByText(/555/)).toBeNull();
    expect(screen.getByText(/333/)).toBeVisible();
    expect(api.post).not.toHaveBeenCalled();
});

it.each(['storage', 'event', 'aba'])('blocks execute if %s ownership changes inside the synchronous prompt', async mode => {
    api.get.mockImplementation(async path => path === '/portfolios' ? portfolios() : path === '/v1/orders' ? orderList([{ id: 17, status: 'pending', broker_status: '' }]) : dashboard());
    const prompt = vi.spyOn(window, 'prompt').mockImplementation(() => {
        sessionStorage.setItem('portfolio_active_id', '2');
        if (mode !== 'storage') window.dispatchEvent(new CustomEvent('portfolio-changed', { detail: { portfolioId: '2' } }));
        if (mode === 'aba') {
            sessionStorage.setItem('portfolio_active_id', '1');
            window.dispatchEvent(new CustomEvent('portfolio-changed', { detail: { portfolioId: '1' } }));
        }
        return '10';
    });
    mount(); fireEvent.click(await screen.findByRole('button', { name: 'Add transaction' }));
    expect(prompt).toHaveBeenCalledWith('Execution price'); expect(api.post).not.toHaveBeenCalled();
});

it('preserves same-scope execute, cancel, reconcile copy, payloads, and reload behavior', async () => {
    const orders = [{ id: 17, status: 'pending', broker_status: '' }, { id: 18, status: 'pending', broker_status: 'unknown' }];
    const cancellationOutcomes = ['pending', 'cancelled']; let cancellationIndex = 0;
    const brokerStatuses = ['unknown', 'open', 'rejected']; let brokerStatusIndex = 0;
    api.get.mockImplementation(async path => path === '/portfolios' ? portfolios() : path === '/v1/orders' ? orderList(orders) : dashboard());
    api.post.mockImplementation(async path => {
        if (path.endsWith('/cancel')) return { data: { data: { cancellation_status: cancellationOutcomes[cancellationIndex++] } } };
        if (path.endsWith('/reconcile')) return { data: { data: { broker_status: brokerStatuses[brokerStatusIndex++] } } };
        return { data: { data: {} } };
    });
    vi.spyOn(window, 'prompt').mockReturnValue('12.5'); mount();
    fireEvent.click(await screen.findByRole('button', { name: 'Add transaction' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/orders/17/execute', { price: 12.5 }, { skipErrorToast: true }));
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Transaction added', 'success'));
    fireEvent.click((await screen.findAllByRole('button', { name: 'Cancel' }))[0]);
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/orders/17/cancel', null, { skipErrorToast: true }));
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Cancellation requested; waiting for Kite confirmation.', 'success'));
    fireEvent.click((await screen.findAllByRole('button', { name: 'Cancel' }))[0]);
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Order cancellation confirmed.', 'success'));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Reconcile' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Reconcile' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/orders/18/reconcile', null, { skipErrorToast: true }));
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Broker status remains unknown. Do not retry this order yet.', 'warning'));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Reconcile' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Reconcile' }));
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Latest broker status: open. Do not submit a duplicate while it is in flight.', 'info'));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Reconcile' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Reconcile' }));
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Broker confirms rejected. Check the filled quantity before retrying.', 'info'));
    expect(api.get.mock.calls.filter(([path]) => path === '/v1/orders').length).toBeGreaterThanOrEqual(4);
});

it('keeps a newer portfolio reconcile busy state when an older completion settles', async () => {
    const oldReconcile = deferred(); const newReconcile = deferred();
    api.get.mockImplementation(async path => path === '/portfolios' ? portfolios() : path === '/v1/orders' ? orderList([{ id: sessionStorage.getItem('portfolio_active_id') === '1' ? 17 : 27, status: 'pending', broker_status: 'unknown' }]) : dashboard());
    api.post.mockImplementation(path => path.includes('/17/') ? oldReconcile.promise : newReconcile.promise);
    mount(); fireEvent.click(await screen.findByRole('button', { name: 'Reconcile' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/orders/17/reconcile', null, { skipErrorToast: true }));
    switchTo(); await waitFor(() => expect(screen.getByRole('button', { name: 'Reconcile' })).toBeVisible());
    fireEvent.click(screen.getByRole('button', { name: 'Reconcile' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/orders/27/reconcile', null, { skipErrorToast: true }));
    const readsBeforeOldCompletion = api.get.mock.calls.length;
    await act(async () => oldReconcile.resolve({ data: { data: { broker_status: 'open' } } }));
    expect(screen.getByRole('button', { name: 'Reconciling…' })).toBeDisabled();
    expect(api.get.mock.calls).toHaveLength(readsBeforeOldCompletion);
    expect(showToast).not.toHaveBeenCalled();
    await act(async () => newReconcile.resolve({ data: { data: { broker_status: 'unknown' } } }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Reconcile' })).toBeVisible());
});

it('keeps the latest same-scope reconcile busy when an older reconcile rejects', async () => {
    const oldReconcile = deferred(); const newReconcile = deferred();
    api.get.mockImplementation(async path => path === '/portfolios' ? portfolios() : path === '/v1/orders'
        ? orderList([{ id: 18, status: 'pending', broker_status: 'unknown' }, { id: 19, status: 'pending', broker_status: 'unknown' }])
        : dashboard());
    api.post.mockImplementation(path => path.includes('/18/') ? oldReconcile.promise : newReconcile.promise);
    mount(); const actions = await screen.findAllByRole('button', { name: 'Reconcile' });
    fireEvent.click(actions[0]);
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/orders/18/reconcile', null, { skipErrorToast: true }));
    fireEvent.click(screen.getAllByRole('button', { name: 'Reconcile' })[0]);
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/v1/orders/19/reconcile', null, { skipErrorToast: true }));
    await act(async () => oldReconcile.reject({ response: { status: 503, data: { message: 'old reconcile failed' } } }));
    expect(screen.getByRole('button', { name: 'Reconciling…' })).toBeDisabled();
    expect(showToast).not.toHaveBeenCalledWith('old reconcile failed', 'danger');
    await act(async () => newReconcile.resolve({ data: { data: { broker_status: 'unknown' } } }));
    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Reconcile' })).toHaveLength(2));
});

it.each(['none', 'stale'])('waits for %s bootstrap identity and ignores pending reads after unmount', async stored => {
    const bootstrap = deferred(); const pendingRead = deferred();
    api.get.mockImplementation(path => path === '/portfolios' ? bootstrap.promise : pendingRead.promise);
    sessionStorage.clear(); if (stored === 'stale') sessionStorage.setItem('portfolio_active_id', '99'); const mounted = mount();
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/portfolios', { skipErrorToast: true }));
    expect(screen.getByText('Loading…')).toBeVisible();
    expect(api.get).toHaveBeenCalledTimes(1);
    await act(async () => bootstrap.resolve({ data: { data: [{ id: 2, name: 'Research', is_default: true }] } }));
    await waitFor(() => expect(api.get.mock.calls.some(([path]) => path === '/v1/orders')).toBe(true));
    mounted.unmount(); await act(async () => { pendingRead.resolve(dashboard(999)); });
    expect(showToast).not.toHaveBeenCalled(); expect(api.post).not.toHaveBeenCalled();
});
