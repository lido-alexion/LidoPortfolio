import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, useNavigate } from 'react-router-dom';
import { flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import api from '../../../resources/js/src/api';
import AuthContext from '../../../resources/js/src/context/AuthContext';
import { PortfolioProvider, usePortfolio } from '../../../resources/js/src/context/PortfolioContext';
import PortfolioSwitcher from '../../../resources/js/src/components/PortfolioSwitcher';
import TransactionsPage from '../../../resources/js/src/pages/TransactionsPage';
import { showToast } from '../../../resources/js/src/toast';
import { calculateTransactionFees, DEFAULT_FEE_COMPONENTS } from '../../../resources/js/src/utils/feeCalculator';
import { getLocalTodayDateString } from '../../../resources/js/src/utils/transactionDate';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/components/DataTable', () => ({
    DataTableCard: ({ data = [], loading, columns = [] }) => {
        const table = useReactTable({ data, columns, getCoreRowModel: getCoreRowModel() });
        return <section aria-label="Transaction rows">{loading ? 'Transactions loading' : <table><tbody>
            {table.getRowModel().rows.map((row) => <tr key={row.id}>
                {row.getVisibleCells().map((cell) => <td key={cell.id}>
                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                </td>)}
            </tr>)}
        </tbody></table>}</section>;
    },
}));
vi.mock('../../../resources/js/src/components/SegmentToggle', () => ({ default: ({ options = [], onChange }) => <div>
    {options.map((option) => <button type="button" key={option.value} onClick={() => onChange(option.value)}>{option.label}</button>)}
</div> }));

const deferred = () => {
    let resolve; let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};
const portfolios = [{ id: 1, name: 'Main', is_default: true }, { id: 2, name: 'Research' }];
const rows = (symbol) => ({ data: { data: [{ id: symbol, stock_id: 1, stock: { symbol, name: symbol }, quantity: 1, price: 2, transaction_date: '2026-10-01', type: 'buy' }] } });
function mount({ strict = false, extra = null, initialEntry = '/transactions' } = {}) {
    const tree = <MemoryRouter initialEntries={[initialEntry]}><AuthContext.Provider value={{ loading: false, isAuthenticated: true, user: { id: 1, default_portfolio_id: 1 } }}>
        <PortfolioProvider><PortfolioSwitcher />{extra}<TransactionsPage /></PortfolioProvider>
    </AuthContext.Provider></MemoryRouter>;
    return render(strict ? <React.StrictMode>{tree}</React.StrictMode> : tree);
}
function switchToResearch() {
    fireEvent.click(screen.getByTitle('Switch portfolio (per tab)'));
    fireEvent.click(screen.getByRole('button', { name: 'Research', exact: true }));
}
function BatchPortfolioReturn() {
    const { setActivePortfolio } = usePortfolio();
    return <button type="button" onClick={() => { setActivePortfolio(2); setActivePortfolio(1); }}>Batch A-B-A</button>;
}
function ReconfirmPortfolio() {
    const { setActivePortfolio } = usePortfolio();
    return <button type="button" onClick={() => setActivePortfolio(1)}>Reload current Portfolio</button>;
}
function RefreshPortfolioList() {
    const { bootstrap } = usePortfolio();
    return <button type="button" onClick={() => { bootstrap(); }}>Refresh Portfolio catalogue</button>;
}
function CurrentPortfolioPrefill({ sell = false }) {
    const navigate = useNavigate();
    const state = sell ? { sellPrefill: {
        stock: { id: 42, symbol: 'BETA', name: 'Beta Corp', exchange: 'NSE' }, quantity: 2, price: 10, owner_key: '',
    } } : { executeRecommendation: {
        stock_id: 42, symbol: 'BETA', name: 'Beta Corp', quantity: 2, price: 10, type: 'buy', recommendation_id: 77,
    } };
    return <button type="button" onClick={() => navigate('/transactions', { state })}>Prefill current Portfolio</button>;
}

beforeEach(() => {
    cleanup();
    api.get.mockReset(); api.post.mockReset(); api.put.mockReset(); api.delete.mockReset();
    showToast.mockClear();
    sessionStorage.clear();
    sessionStorage.setItem('portfolio_active_id', '1');
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/settings') return Promise.resolve({ data: { data: { fee_components: [] } } });
        if (path === '/holdings') return Promise.resolve({ data: { data: [] } });
        if (path === '/transactions') return Promise.resolve(rows(sessionStorage.getItem('portfolio_active_id') === '2' ? 'BETA' : 'ALPHA'));
        throw new Error(`Unexpected GET ${path}`);
    });
});
afterEach(() => cleanup());

it('keeps B rows after an ordinary confirmed A to B switch resolves the held A request last', async () => {
    const oldA = deferred(); let firstTransactionRead = true;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/settings') return Promise.resolve({ data: { data: { fee_components: [] } } });
        if (path === '/transactions' && sessionStorage.getItem('portfolio_active_id') === '1' && firstTransactionRead) {
            firstTransactionRead = false;
            return oldA.promise;
        }
        if (path === '/transactions') return Promise.resolve(rows('BETA'));
        throw new Error(`Unexpected GET ${path}`);
    });
    mount();
    await waitFor(() => expect(firstTransactionRead).toBe(false));
    switchToResearch();
    expect(await screen.findByText('BETA')).toBeInTheDocument();
    await act(async () => oldA.resolve(rows('ALPHA')));
    expect(screen.getByText('BETA')).toBeInTheDocument();
    expect(screen.queryByText('ALPHA')).not.toBeInTheDocument();
});

it('invalidates genuinely batched A to B to A events even when the final id is unchanged', async () => {
    const heldA = deferred(); let aReads = 0; let returned = false;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/settings') return Promise.resolve({ data: { data: { fee_components: [] } } });
        if (path === '/transactions' && sessionStorage.getItem('portfolio_active_id') === '1') {
            aReads += 1;
            if (aReads === 1) return heldA.promise;
            return Promise.resolve(rows(returned ? 'LATEST A' : 'INITIAL A'));
        }
        if (path === '/transactions') return Promise.resolve(rows(sessionStorage.getItem('portfolio_active_id') === '2' ? 'BETA' : 'LATEST A'));
        throw new Error(`Unexpected GET ${path}`);
    });
    mount({ strict: true, extra: <BatchPortfolioReturn /> });
    await waitFor(() => expect(aReads).toBeGreaterThan(0));
    const readsBeforeReturn = aReads; returned = true;
    fireEvent.click(screen.getByRole('button', { name: 'Batch A-B-A' }));
    await waitFor(() => expect(aReads).toBeGreaterThan(readsBeforeReturn));
    expect(await screen.findByText('LATEST A')).toBeInTheDocument();
    await act(async () => heldA.resolve(rows('STALE A')));
    expect(screen.getByText('LATEST A')).toBeInTheDocument();
    expect(screen.queryByText('STALE A')).not.toBeInTheDocument();
});

it.each([null, '99'])('waits through unconfirmed stored Portfolio %s bootstrap without page reads', async (stored) => {
    const portfolioBootstrap = deferred();
    if (stored === null) sessionStorage.removeItem('portfolio_active_id');
    else sessionStorage.setItem('portfolio_active_id', stored);
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return portfolioBootstrap.promise;
        if (path === '/settings') return Promise.resolve({ data: { data: { fee_components: [] } } });
        if (path === '/transactions') return Promise.resolve(rows('ALPHA'));
        throw new Error(`Unexpected GET ${path}`);
    });
    mount();
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/portfolios', { skipErrorToast: true }));
    expect(api.get.mock.calls.some(([path]) => path === '/transactions' || path === '/settings')).toBe(false);
    await act(async () => portfolioBootstrap.resolve({ data: { data: portfolios } }));
    expect(await screen.findByText('ALPHA')).toBeInTheDocument();
    expect(sessionStorage.getItem('portfolio_active_id')).toBe('1');
    expect(api.get.mock.calls.filter(([path]) => path === '/transactions')).toHaveLength(1);
});

it('lets the newest same-scope read win when confirmed Portfolio events overlap', async () => {
    const oldRead = deferred(); let transactionReads = 0;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/settings') return Promise.resolve({ data: { data: { fee_components: [] } } });
        if (path === '/transactions') return ++transactionReads === 1 ? oldRead.promise : Promise.resolve(rows('CURRENT'));
        throw new Error(`Unexpected GET ${path}`);
    });
    mount({ extra: <ReconfirmPortfolio /> });
    await waitFor(() => expect(transactionReads).toBe(1));
    fireEvent.click(screen.getByRole('button', { name: 'Reload current Portfolio' }));
    expect(await screen.findByText('CURRENT')).toBeInTheDocument();
    await act(async () => oldRead.resolve(rows('OLDER')));
    expect(screen.getByText('CURRENT')).toBeInTheDocument();
    expect(screen.queryByText('OLDER')).not.toBeInTheDocument();
});

it('preserves the confirmed form and avoids duplicate reads on benign catalogue refresh', async () => {
    const refreshedCatalogue = deferred(); let portfolioReads = 0; let transactionReads = 0;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return ++portfolioReads === 1
            ? Promise.resolve({ data: { data: portfolios } }) : refreshedCatalogue.promise;
        if (path === '/transactions') { transactionReads += 1; return Promise.resolve(rows('ALPHA')); }
        if (path === '/settings') return Promise.resolve({ data: { data: { fee_components: [] } } });
        throw new Error(`Unexpected GET ${path}`);
    });
    mount({ extra: <RefreshPortfolioList /> });
    await screen.findByText('ALPHA');
    fireEvent.click(screen.getByRole('button', { name: 'Prefill current Portfolio' }));
    await screen.findByRole('button', { name: 'Save Transaction' });
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Keep through catalogue refresh' } });
    fireEvent.click(screen.getByRole('button', { name: 'Refresh Portfolio catalogue' }));
    await waitFor(() => expect(portfolioReads).toBe(2));
    expect(screen.getByLabelText('Notes')).toHaveValue('Keep through catalogue refresh');
    await act(async () => refreshedCatalogue.resolve({ data: { data: portfolios } }));
    expect(screen.getByLabelText('Notes')).toHaveValue('Keep through catalogue refresh');
    expect(transactionReads).toBe(1);
});

it('ignores old settings and sell-lot completions after a new confirmed Portfolio form is active', async () => {
    const oldSettings = deferred();
    const oldLots = deferred();
    let settingsReads = 0;
    let lotsReads = 0;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/transactions') return Promise.resolve(rows(sessionStorage.getItem('portfolio_active_id') === '2' ? 'BETA' : 'ALPHA'));
        if (path === '/settings') {
            settingsReads += 1;
            return settingsReads === 1 ? oldSettings.promise : Promise.resolve({ data: { data: { fee_components: [{ id: 'b_fee', label: 'B fee', value: '1', mode: 'percentage' }] } } });
        }
        if (path === '/holdings') {
            lotsReads += 1;
            return sessionStorage.getItem('portfolio_active_id') === '2'
                ? Promise.resolve({ data: { data: [{ stock_id: 42, quantity: 5, owner_key: 'strategy:8' }, { stock_id: 42, quantity: 2, owner_key: 'unmanaged' }] } }) : oldLots.promise;
        }
        throw new Error(`Unexpected GET ${path}`);
    });
    mount({ extra: <CurrentPortfolioPrefill sell />, initialEntry: { pathname: '/transactions', state: { sellPrefill: {
        stock: { id: 42, symbol: 'ALPHA', name: 'Alpha Corp', exchange: 'NSE' }, quantity: 2, price: 10, owner_key: 'strategy:4',
    } } } });
    await waitFor(() => { expect(settingsReads).toBeGreaterThan(0); expect(lotsReads).toBeGreaterThan(0); });
    switchToResearch();
    expect(await screen.findByText('BETA')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Prefill current Portfolio' }));
    expect(await screen.findByRole('button', { name: /B fee:/ })).toBeInTheDocument();
    await waitFor(() => expect([...screen.getByRole('combobox', { name: 'Owner' }).options].map(option => option.value)).toEqual(['', 'strategy:8', 'unmanaged']));
    await act(async () => {
        oldSettings.resolve({ data: { data: { fee_components: [{ id: 'a_fee', label: 'A fee', value: '2', mode: 'percentage' }] } } });
        oldLots.resolve({ data: { data: [
            { stock_id: 42, quantity: 1, owner_key: 'strategy:4' },
            { stock_id: 42, quantity: 1, owner_key: 'unmanaged' },
        ] } });
    });
    expect(screen.getByRole('button', { name: /B fee:/ })).toBeInTheDocument();
    expect([...screen.getByRole('combobox', { name: 'Owner' }).options].map(option => option.value)).toEqual(['', 'strategy:8', 'unmanaged']);
    expect(screen.queryByRole('button', { name: /A fee:/ })).not.toBeInTheDocument();
    expect(screen.getByLabelText('Notes')).toHaveValue('');
});

it('ignores old settings and sell-lot failures after a new confirmed Portfolio form is active', async () => {
    const oldSettings = deferred(); const oldLots = deferred(); let settingsReads = 0;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/transactions') return Promise.resolve(rows(sessionStorage.getItem('portfolio_active_id') === '2' ? 'BETA' : 'ALPHA'));
        if (path === '/settings') return ++settingsReads === 1 ? oldSettings.promise
            : Promise.resolve({ data: { data: { fee_components: [{ id: 'b_fee', label: 'B fee', value: '1', mode: 'percentage' }] } } });
        if (path === '/holdings') return sessionStorage.getItem('portfolio_active_id') === '2'
            ? Promise.resolve({ data: { data: [{ stock_id: 42, quantity: 5, owner_key: 'strategy:8' }, { stock_id: 42, quantity: 2, owner_key: 'unmanaged' }] } }) : oldLots.promise;
        throw new Error(`Unexpected GET ${path}`);
    });
    mount({ extra: <CurrentPortfolioPrefill sell />, initialEntry: { pathname: '/transactions', state: { sellPrefill: {
        stock: { id: 42, symbol: 'ALPHA', name: 'Alpha Corp', exchange: 'NSE' }, quantity: 2, price: 10, owner_key: 'strategy:4',
    } } } });
    await waitFor(() => expect(api.get.mock.calls.some(([path]) => path === '/holdings')).toBe(true));
    switchToResearch();
    expect(await screen.findByText('BETA')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Prefill current Portfolio' }));
    expect(await screen.findByRole('button', { name: /B fee:/ })).toBeInTheDocument();
    await waitFor(() => expect([...screen.getByRole('combobox', { name: 'Owner' }).options].map(option => option.value)).toEqual(['', 'strategy:8', 'unmanaged']));
    await act(async () => {
        oldSettings.reject(new Error('stale A settings failure'));
        oldLots.reject(new Error('stale A lots failure'));
    });
    expect(screen.getByRole('button', { name: /B fee:/ })).toBeInTheDocument();
    expect([...screen.getByRole('combobox', { name: 'Owner' }).options].map(option => option.value)).toEqual(['', 'strategy:8', 'unmanaged']);
    expect(screen.getByLabelText('Notes')).toHaveValue('');
    expect(screen.queryByText('stale A settings failure')).not.toBeInTheDocument();
});

it('does not commit a held read after page unmount', async () => {
    const pending = deferred();
    api.get.mockImplementation((path) => path === '/portfolios'
        ? Promise.resolve({ data: { data: portfolios } })
        : path === '/settings' ? Promise.resolve({ data: { data: { fee_components: [] } } })
            : path === '/transactions' ? pending.promise : Promise.resolve({ data: { data: [] } }));
    const page = mount({ strict: true });
    await waitFor(() => expect(api.get.mock.calls.some(([path]) => path === '/transactions')).toBe(true));
    page.unmount();
    await act(async () => pending.resolve(rows('UNMOUNTED')));
    expect(screen.queryByText('UNMOUNTED')).not.toBeInTheDocument();
});

it('ignores a late A read error after B has committed', async () => {
    const oldA = deferred(); let firstA = true;
    api.get.mockImplementation((path) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/settings') return Promise.resolve({ data: { data: { fee_components: [] } } });
        if (path === '/transactions' && sessionStorage.getItem('portfolio_active_id') === '1' && firstA) {
            firstA = false;
            return oldA.promise;
        }
        if (path === '/transactions') return Promise.resolve(rows('BETA'));
        throw new Error(`Unexpected GET ${path}`);
    });
    mount();
    await waitFor(() => expect(firstA).toBe(false));
    switchToResearch();
    expect(await screen.findByText('BETA')).toBeInTheDocument();
    await act(async () => oldA.reject(new Error('late A read failed')));
    expect(screen.getByText('BETA')).toBeInTheDocument();
    expect(screen.queryByText('late A read failed')).not.toBeInTheDocument();
});

it('refuses a stale row delete if session storage changes inside confirmation', async () => {
    api.get.mockImplementation((path) => path === '/portfolios'
        ? Promise.resolve({ data: { data: portfolios } })
        : path === '/settings' ? Promise.resolve({ data: { data: { fee_components: [] } } })
            : path === '/transactions' ? Promise.resolve(rows('ALPHA')) : Promise.resolve({ data: { data: [] } }));
    mount();
    expect(await screen.findByText('ALPHA')).toBeInTheDocument();
    const originalConfirm = window.confirm;
    window.confirm = () => { sessionStorage.setItem('portfolio_active_id', '2'); return true; };
    try {
        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
    } finally {
        window.confirm = originalConfirm;
    }
    expect(api.delete).not.toHaveBeenCalled();
    expect(showToast).not.toHaveBeenCalled();
});

it('keeps the replacement edit draft and busy state when an older PUT succeeds', async () => {
    const oldPut = deferred();
    const newPut = deferred();
    let puts = 0;
    const original = { id: 91, stock_id: 42, stock: { id: 42, symbol: 'INFY', name: 'Infosys', exchange: 'NSE' }, type: 'buy', quantity: 2, price: 100, fees: 0, transaction_date: '2026-10-01', notes: 'Original evidence' };
    api.get.mockImplementation((path) => path === '/portfolios'
        ? Promise.resolve({ data: { data: portfolios } })
        : path === '/transactions' ? Promise.resolve({ data: { data: [original] } })
            : path === '/settings' ? Promise.resolve({ data: { data: { fee_components: [] } } }) : Promise.resolve({ data: { data: [] } }));
    api.put.mockImplementation(() => (++puts === 1 ? oldPut.promise : newPut.promise));
    mount({ initialEntry: { pathname: '/transactions', state: { editTransaction: original } } });
    expect(await screen.findByRole('button', { name: 'Update Transaction' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Update Transaction' }));
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Replacement draft' } });
    fireEvent.click(screen.getByRole('button', { name: 'Update Transaction' }));
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(2));
    expect(screen.getByLabelText('Notes')).toHaveValue('Replacement draft');
    expect(screen.getByRole('button', { name: 'Updating...' })).toBeDisabled();
    await act(async () => oldPut.resolve({ data: {} }));
    expect(screen.getByLabelText('Notes')).toHaveValue('Replacement draft');
    expect(screen.getByRole('button', { name: 'Updating...' })).toBeDisabled();
    expect(showToast).not.toHaveBeenCalledWith('Transaction updated');
    await act(async () => newPut.resolve({ data: {} }));
    await waitFor(() => expect(showToast).toHaveBeenCalledTimes(1));
    expect(showToast).toHaveBeenCalledWith('Transaction updated');
});

it('keeps a replacement edit busy and draft when an older PUT rejects', async () => {
    const oldPut = deferred();
    const newPut = deferred();
    let puts = 0;
    const original = { id: 92, stock_id: 42, stock: { id: 42, symbol: 'INFY', name: 'Infosys', exchange: 'NSE' }, type: 'buy', quantity: 2, price: 100, fees: 0, transaction_date: '2026-10-01', notes: 'Original evidence' };
    api.get.mockImplementation((path) => path === '/portfolios'
        ? Promise.resolve({ data: { data: portfolios } })
        : path === '/transactions' ? Promise.resolve({ data: { data: [original] } })
            : path === '/settings' ? Promise.resolve({ data: { data: { fee_components: [] } } }) : Promise.resolve({ data: { data: [] } }));
    api.put.mockImplementation(() => (++puts === 1 ? oldPut.promise : newPut.promise));
    mount({ initialEntry: { pathname: '/transactions', state: { editTransaction: original } } });
    await screen.findByRole('button', { name: 'Update Transaction' });
    fireEvent.click(screen.getByRole('button', { name: 'Update Transaction' }));
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Newer evidence' } });
    fireEvent.click(screen.getByRole('button', { name: 'Update Transaction' }));
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(2));
    await act(async () => oldPut.reject(new Error('stale transport failure')));
    expect(screen.getByLabelText('Notes')).toHaveValue('Newer evidence');
    expect(screen.getByRole('button', { name: 'Updating...' })).toBeDisabled();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(showToast).not.toHaveBeenCalled();
    await act(async () => newPut.resolve({ data: {} }));
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Transaction updated'));
});

it('ignores a validation rejection before trying a second exchange after ownership changes', async () => {
    const firstExchange = deferred();
    api.get.mockImplementation((path) => path === '/portfolios'
        ? Promise.resolve({ data: { data: portfolios } })
        : path === '/settings' ? Promise.resolve({ data: { data: { fee_components: [] } } })
            : path === '/transactions' ? Promise.resolve(rows(sessionStorage.getItem('portfolio_active_id') === '2' ? 'BETA' : 'ALPHA'))
                : path === '/stocks/search' ? Promise.resolve({ data: { data: [] } }) : Promise.resolve({ data: { data: [] } }));
    api.post.mockImplementation(() => firstExchange.promise);
    mount();
    await screen.findByText('ALPHA');
    fireEvent.change(screen.getByPlaceholderText('e.g. INFY or company name'), { target: { value: 'ZZTOP' } });
    fireEvent.click(screen.getByRole('button', { name: 'Validate symbol' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
    expect(api.post).toHaveBeenCalledWith('/stocks/validate', { symbol: 'ZZTOP', exchange: 'NSE', check_only: true });
    switchToResearch();
    expect(await screen.findByText('BETA')).toBeInTheDocument();
    await act(async () => firstExchange.reject(new Error('old NSE validation failed')));
    expect(api.post).toHaveBeenCalledTimes(1);
    expect(screen.queryByText('Symbol validation failed')).not.toBeInTheDocument();
});

it('ignores old Portfolio DELETE success and error after a new Portfolio edit starts', async () => {
    for (const outcome of ['success', 'error']) {
        cleanup();
        sessionStorage.setItem('portfolio_active_id', '1');
        api.get.mockReset(); api.delete.mockReset();
        showToast.mockClear();
        const pendingDelete = deferred();
        const original = { id: outcome === 'success' ? 93 : 94, stock_id: 42, stock: { id: 42, symbol: 'INFY', name: 'Infosys', exchange: 'NSE' }, type: 'buy', quantity: 2, price: 100, fees: 0, transaction_date: '2026-10-01', notes: 'Original evidence' };
        api.get.mockImplementation((path) => path === '/portfolios'
            ? Promise.resolve({ data: { data: portfolios } })
            : path === '/transactions' ? Promise.resolve({ data: { data: sessionStorage.getItem('portfolio_active_id') === '2' ? [{ ...original, id: original.id + 100, stock: { ...original.stock, symbol: 'BETA' } }] : [original] } })
                : path === '/settings' ? Promise.resolve({ data: { data: { fee_components: [] } } }) : Promise.resolve({ data: { data: [] } }));
        api.delete.mockImplementation(() => pendingDelete.promise);
        mount();
        expect(await screen.findByText('INFY')).toBeInTheDocument();
        const originalConfirm = window.confirm;
        window.confirm = () => true;
        try { fireEvent.click(screen.getByRole('button', { name: 'Delete' })); }
        finally { window.confirm = originalConfirm; }
        await waitFor(() => expect(api.delete).toHaveBeenCalledWith(`/transactions/${original.id}`));
        switchToResearch();
        await screen.findByText('BETA');
        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
        const currentReads = api.get.mock.calls.filter(([path]) => path === '/transactions').length;
        fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Current form evidence' } });
        if (outcome === 'success') {
            await act(async () => pendingDelete.resolve({ data: { message: 'Old delete completed' } }));
        } else {
            await act(async () => pendingDelete.reject(new Error('Old delete failed')));
        }
        expect(screen.getByLabelText('Notes')).toHaveValue('Current form evidence');
        expect(showToast).not.toHaveBeenCalled();
        expect(api.get.mock.calls.filter(([path]) => path === '/transactions')).toHaveLength(currentReads);
        expect(screen.getByText('BETA')).toBeInTheDocument();
    }
});

it('preserves same-scope recommendation create payload, success feedback, and reload', async () => {
    api.get.mockImplementation((path) => path === '/portfolios'
        ? Promise.resolve({ data: { data: portfolios } })
        : path === '/transactions' ? Promise.resolve(rows('ALPHA'))
            : path === '/settings' ? Promise.resolve({ data: { data: { fee_components: DEFAULT_FEE_COMPONENTS } } })
                : path === '/stocks/search' ? Promise.resolve({ data: { data: [{ id: 42, symbol: 'BETA', name: 'Beta Corp', exchange: 'NSE' }] } })
                : Promise.resolve({ data: { data: [] } }));
    api.post.mockResolvedValue({ data: { message: 'Transaction saved compatibly' } });
    mount({ extra: <CurrentPortfolioPrefill /> });
    await screen.findByText('ALPHA');
    fireEvent.click(screen.getByRole('button', { name: 'Prefill current Portfolio' }));
    await waitFor(() => expect(screen.getByPlaceholderText('e.g. INFY or company name')).toHaveValue('BETA'));
    fireEvent.click(screen.getByRole('button', { name: 'Validate symbol' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save Transaction' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Save Transaction' }));
    const expectedFees = calculateTransactionFees({
        quantity: 2, price: 10, type: 'buy', exchange: 'NSE', feeComponents: DEFAULT_FEE_COMPONENTS,
    }).total;
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/transactions', {
        type: 'buy', quantity: 2, price: 10, fees: expectedFees, transaction_date: getLocalTodayDateString(),
        notes: '', recommendation_id: 77, source: 'recommendation', stock_id: 42,
    }));
    await waitFor(() => expect(api.get.mock.calls.filter(([path]) => path === '/transactions')).toHaveLength(2));
    expect(showToast).toHaveBeenCalledWith('Transaction saved compatibly', 'success');
});

it('preserves same-scope confirmed delete URL, toast, and single reload', async () => {
    const original = { id: 95, stock_id: 42, stock: { id: 42, symbol: 'INFY', name: 'Infosys', exchange: 'NSE' }, type: 'buy', quantity: 2, price: 100, fees: 0, transaction_date: '2026-10-01', notes: '' };
    api.get.mockImplementation((path) => path === '/portfolios'
        ? Promise.resolve({ data: { data: portfolios } })
        : path === '/transactions' ? Promise.resolve({ data: { data: [original] } })
            : path === '/settings' ? Promise.resolve({ data: { data: { fee_components: [] } } }) : Promise.resolve({ data: { data: [] } }));
    api.delete.mockResolvedValue({ data: { message: 'Transaction removed compatibly' } });
    mount();
    expect(await screen.findByText('INFY')).toBeInTheDocument();
    const originalConfirm = window.confirm;
    window.confirm = () => true;
    try { fireEvent.click(screen.getByRole('button', { name: 'Delete' })); }
    finally { window.confirm = originalConfirm; }
    await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/transactions/95'));
    await waitFor(() => expect(api.get.mock.calls.filter(([path]) => path === '/transactions')).toHaveLength(2));
    expect(showToast).toHaveBeenCalledWith('Transaction removed compatibly', undefined);
});

it('finishes a current save when an unrelated same-Portfolio delete completes first', async () => {
    const pendingPut = deferred(); let deleted = false;
    const original = { id: 91, stock_id: 42, stock: { id: 42, symbol: 'INFY', name: 'Infosys', exchange: 'NSE' }, type: 'buy', quantity: 2, price: 100, fees: 0, transaction_date: '2026-10-01', notes: '' };
    const sibling = { ...original, id: 96, stock_id: 43, stock: { id: 43, symbol: 'OTHER', name: 'Other', exchange: 'NSE' } };
    api.get.mockImplementation((path) => path === '/portfolios'
        ? Promise.resolve({ data: { data: portfolios } })
        : path === '/transactions' ? Promise.resolve({ data: { data: deleted ? [original] : [original, sibling] } })
            : path === '/settings' ? Promise.resolve({ data: { data: { fee_components: [] } } })
                : Promise.resolve({ data: { data: [] } }));
    api.put.mockImplementation(() => pendingPut.promise);
    api.delete.mockImplementation(async () => { deleted = true; return { data: { message: 'Sibling removed' } }; });
    mount({ initialEntry: { pathname: '/transactions', state: { editTransaction: original } } });
    await screen.findByRole('button', { name: 'Update Transaction' });
    fireEvent.click(screen.getByRole('button', { name: 'Update Transaction' }));
    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(1));
    const siblingRow = screen.getByText('OTHER').closest('tr');
    const originalConfirm = window.confirm; window.confirm = () => true;
    try { fireEvent.click(within(siblingRow).getByRole('button', { name: 'Delete' })); }
    finally { window.confirm = originalConfirm; }
    await waitFor(() => expect(screen.queryByText('OTHER')).not.toBeInTheDocument());
    expect(api.delete).toHaveBeenCalledWith('/transactions/96');
    expect(screen.getByRole('button', { name: 'Updating...' })).toBeDisabled();
    await act(async () => pendingPut.resolve({ data: {} }));
    await screen.findByRole('button', { name: 'Save Transaction' });
    expect(screen.queryByRole('button', { name: 'Updating...' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Adding...' })).not.toBeInTheDocument();
    expect(showToast).toHaveBeenCalledWith('Transaction updated');
    expect(api.put).toHaveBeenCalledTimes(1);
    expect(api.post).not.toHaveBeenCalled();
});

it('does not replace a newly typed symbol with an older same-Portfolio validation result', async () => {
    const oldLookup = deferred();
    api.get.mockImplementation((path, options) => {
        if (path === '/portfolios') return Promise.resolve({ data: { data: portfolios } });
        if (path === '/transactions') return Promise.resolve(rows('ALPHA'));
        if (path === '/settings') return Promise.resolve({ data: { data: { fee_components: [] } } });
        if (path === '/stocks/search') return options?.params?.q === 'ZZTOP'
            ? oldLookup.promise : Promise.resolve({ data: { data: [] } });
        return Promise.resolve({ data: { data: [] } });
    });
    mount();
    await screen.findByText('ALPHA');
    const symbolInput = screen.getByPlaceholderText('e.g. INFY or company name');
    fireEvent.change(symbolInput, { target: { value: 'ZZTOP' } });
    fireEvent.click(screen.getByRole('button', { name: 'Validate symbol' }));
    await waitFor(() => expect(api.get.mock.calls.some(([path, options]) => path === '/stocks/search' && options?.params?.q === 'ZZTOP')).toBe(true));
    fireEvent.change(symbolInput, { target: { value: 'OTHER' } });
    await act(async () => oldLookup.resolve({ data: { data: [{ id: 44, symbol: 'ZZTOP', name: 'Old lookup', exchange: 'NSE' }] } }));
    expect(symbolInput).toHaveValue('OTHER');
    expect(screen.getByRole('button', { name: 'Validate symbol' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Save Transaction' })).toBeDisabled();
    expect(api.post).not.toHaveBeenCalled();
});
