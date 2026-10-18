import React, { StrictMode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import MarketDepthPage from '../../../resources/js/src/pages/MarketDepthPage';
import { showToast } from '../../../resources/js/src/toast';

vi.mock('../../../resources/js/src/api', async () => {
    const actual = await vi.importActual('../../../resources/js/src/api');
    return { default: { get: vi.fn(), post: vi.fn() }, getApiErrorMessage: actual.getApiErrorMessage };
});
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));

beforeEach(() => { cleanup(); api.get.mockReset(); api.post.mockReset(); showToast.mockReset(); });
const unavailable = () => ({ response: { status: 503, data: { message: 'Synthetic unavailable' } }, message: 'Request failed with status code 503' });
function deferred() { let resolve; let reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }
function breadth(date, value, availableDates = ['2026-10-01', '2026-09-30', '2026-09-29']) {
    return { data: { available_dates: availableDates, as_of_date: date, matrix: { as_of_date: date, columns: [{ key: 'rising', label: 'Rising' }], rows: [{ symbol: 'NIFTY50', name: 'Nifty 50', eligible: 10, pct_rising: value, pass_rising: value == null ? null : Math.round(value / 10), scanned_rising: value == null ? null : 10 }] }, chart_history: [{ date: '2026-10-01', metrics: { rising: { pct: 50, pass: 5, scanned: 10 } } }] } };
}

it('exposes an unavailable breadth read without claiming confirmed empty, then recovers to empty', async () => {
    api.get.mockRejectedValueOnce(unavailable()).mockResolvedValue({ data: { available_dates: [], matrix: null, chart_history: [] } });
    render(<MemoryRouter><MarketDepthPage /></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Market breadth could not be loaded');
    expect(screen.queryByRole('option', { name: 'No snapshots' })).toBeNull();
    expect(screen.getByRole('option', { name: 'Snapshots unavailable' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Retry market breadth' }));
    expect(await screen.findByRole('option', { name: 'No snapshots' })).toBeVisible();
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(api.post).not.toHaveBeenCalled();
});

it('keeps the latest dated matrix and independent chart history when an older read resolves late', async () => {
    const olderDate = deferred(); let reads = 0;
    api.get.mockImplementation((_path, config) => {
        reads += 1;
        const date = config.params.date || '2026-10-01';
        if (date === '2026-09-30') return olderDate.promise;
        const response = breadth(date, date === '2026-10-01' ? 50 : 80);
        response.data.chart_history = date === '2026-10-01'
            ? [
                { date: '2026-10-01', metrics: { rising: { pct: 50, pass: 5, scanned: 10 } } },
                { date: '2026-09-30', metrics: { rising: { pct: 40, pass: 4, scanned: 10 } } },
            ]
            : [];
        return Promise.resolve(response);
    });
    render(<MemoryRouter><MarketDepthPage /></MemoryRouter>);
    await waitFor(() => expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('50%'));
    expect(screen.getByText('Nifty 50 — last 2 snapshots (%)', { exact: true })).toBeVisible();
    fireEvent.change(screen.getByLabelText('Date', { exact: true }), { target: { value: '2026-09-30' } });
    fireEvent.change(screen.getByLabelText('Date', { exact: true }), { target: { value: '2026-09-29' } });
    await waitFor(() => expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('80%'));
    await act(async () => olderDate.resolve(breadth('2026-09-30', 20)));
    expect(screen.getByLabelText('Date', { exact: true })).toHaveValue('2026-09-29');
    expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('80%');
    expect(document.querySelector('.lido-market-depth-cell')).not.toHaveTextContent('20%');
    expect(screen.getByText('Nifty 50 — last 2 snapshots (%)', { exact: true })).toBeVisible();
    expect(reads).toBe(3);
    expect(api.post).not.toHaveBeenCalled();
});

it('retains a same-date confirmed matrix as stale evidence after a failed refresh', async () => {
    let refresh = false;
    api.get.mockImplementation(() => refresh ? Promise.reject(unavailable()) : Promise.resolve(breadth('2026-10-01', 50)));
    render(<MemoryRouter><MarketDepthPage /></MemoryRouter>);
    await waitFor(() => expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('50%'));
    refresh = true;
    fireEvent.change(screen.getByLabelText('Date', { exact: true }), { target: { value: '2026-10-01' } });
    expect(await screen.findByRole('alert')).toHaveTextContent('Market breadth could not be loaded');
    expect(screen.getByRole('status')).toHaveTextContent('last confirmed matrix for this date');
    expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('50%');
    refresh = false;
    fireEvent.click(screen.getByRole('button', { name: 'Retry market breadth' }));
    await waitFor(() => expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('50%'));
    expect(api.post).not.toHaveBeenCalled();
});

it('keeps the newest StrictMode request loading until its own response confirms the matrix', async () => {
    const requests = [deferred(), deferred()]; let requestIndex = 0;
    api.get.mockImplementation(() => requests[requestIndex++].promise);
    render(<StrictMode><MemoryRouter><MarketDepthPage /></MemoryRouter></StrictMode>);

    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(2));
    expect(api.get).toHaveBeenNthCalledWith(1, '/market-depth', { params: {} });
    expect(api.get).toHaveBeenNthCalledWith(2, '/market-depth', { params: {} });
    expect(screen.getByRole('status')).toHaveTextContent('Loading market breadth');
    await act(async () => requests[0].resolve(breadth('2026-10-01', 15)));
    expect(screen.getByRole('status')).toHaveTextContent('Loading market breadth');
    expect(document.querySelector('.lido-market-depth-cell')).toBeNull();
    await act(async () => requests[1].resolve(breadth('2026-10-01', 75)));
    expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('75%');
    expect(screen.queryByRole('status', { name: /Loading market breadth/ })).toBeNull();
    expect(api.post).not.toHaveBeenCalled();
});

it('ignores an old same-date rejection and intermediate success while an A→B→A read is pending', async () => {
    const firstA = deferred(); const requestB = deferred(); const currentA = deferred(); let aReads = 0; const reads = [];
    api.get.mockImplementation((path, config) => {
        reads.push({ path, date: config.params.date || '' });
        const date = config.params.date || '2026-10-01';
        if (date === '2026-10-01') return Promise.resolve(breadth(date, 50));
        if (date === '2026-09-30') return (++aReads === 1 ? firstA : currentA).promise;
        if (date === '2026-09-29') return requestB.promise;
        throw new Error(`Unexpected date ${date}`);
    });
    render(<MemoryRouter><MarketDepthPage /></MemoryRouter>);
    await waitFor(() => expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('50%'));
    const date = screen.getByLabelText('Date', { exact: true });
    fireEvent.change(date, { target: { value: '2026-09-30' } });
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(2));
    fireEvent.change(date, { target: { value: '2026-09-29' } });
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(3));
    fireEvent.change(date, { target: { value: '2026-09-30' } });
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(4));
    expect(screen.getByRole('status')).toHaveTextContent('Loading market breadth');

    await act(async () => { firstA.reject(unavailable()); requestB.resolve(breadth('2026-09-29', 20)); });
    expect(screen.getByLabelText('Date', { exact: true })).toHaveValue('2026-09-30');
    expect(screen.getByRole('status')).toHaveTextContent('Loading market breadth');
    expect(screen.queryByRole('alert')).toBeNull();
    expect(document.querySelector('.lido-market-depth-cell')).toBeNull();
    expect(showToast).not.toHaveBeenCalled();

    await act(async () => currentA.resolve(breadth('2026-09-30', 70)));
    expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('70%');
    expect(reads).toEqual([
        { path: '/market-depth', date: '' },
        { path: '/market-depth', date: '2026-09-30' },
        { path: '/market-depth', date: '2026-09-29' },
        { path: '/market-depth', date: '2026-09-30' },
    ]);
    expect(api.post).not.toHaveBeenCalled();
});

it('does not turn an absent matrix payload into confirmed empty snapshots', async () => {
    api.get.mockResolvedValueOnce({ data: { available_dates: [] } })
        .mockResolvedValueOnce({ data: { available_dates: [], as_of_date: '', matrix: null, chart_history: [] } });
    render(<MemoryRouter><MarketDepthPage /></MemoryRouter>);

    expect(await screen.findByRole('alert')).toHaveTextContent('Market breadth could not be loaded');
    expect(screen.getByRole('option', { name: 'Snapshots unavailable' })).toBeVisible();
    expect(screen.queryByRole('option', { name: 'No snapshots' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Retry market breadth' }));
    expect(await screen.findByRole('option', { name: 'No snapshots' })).toBeVisible();
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(api.get).toHaveBeenNthCalledWith(1, '/market-depth', { params: {} });
    expect(api.get).toHaveBeenNthCalledWith(2, '/market-depth', { params: {} });
    expect(api.post).not.toHaveBeenCalled();
});

it('keeps missing breadth metrics unavailable in both percent and count views', async () => {
    api.get.mockResolvedValue(breadth('2026-10-01', null));
    render(<MemoryRouter><MarketDepthPage /></MemoryRouter>);
    await waitFor(() => expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('—'));
    fireEvent.click(within(screen.getByRole('group', { name: 'Value mode' })).getByRole('button', { name: 'Count', exact: true }));
    expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('—');
    expect(api.get).toHaveBeenCalledTimes(1);
    expect(api.get).toHaveBeenCalledWith('/market-depth', { params: {} });
    expect(api.post).not.toHaveBeenCalled();
});

it('does not surface a stale failure after a current read is unmounted', async () => {
    const request = deferred();
    api.get.mockReturnValue(request.promise);
    const view = render(<MemoryRouter><MarketDepthPage /></MemoryRouter>);
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(1));
    view.unmount();
    await act(async () => request.reject(unavailable()));
    expect(showToast).not.toHaveBeenCalled();
    expect(api.post).not.toHaveBeenCalled();
});
