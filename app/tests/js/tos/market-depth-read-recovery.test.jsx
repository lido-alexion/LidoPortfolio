import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import MarketDepthPage from '../../../resources/js/src/pages/MarketDepthPage';

vi.mock('../../../resources/js/src/api', async () => {
    const actual = await vi.importActual('../../../resources/js/src/api');
    return { default: { get: vi.fn(), post: vi.fn() }, getApiErrorMessage: actual.getApiErrorMessage };
});
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));

beforeEach(() => { cleanup(); vi.clearAllMocks(); });
const unavailable = () => ({ response: { status: 503, data: { message: 'Synthetic unavailable' } }, message: 'Request failed with status code 503' });
function deferred() { let resolve; const promise = new Promise((yes) => { resolve = yes; }); return { promise, resolve }; }
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

it('keeps Market Depth matrix selection on the latest requested date after an older date resolves', async () => {
    const olderDate = deferred(); let reads = 0;
    api.get.mockImplementation((_path, config) => {
        reads += 1;
        const date = config.params.date || '2026-10-01';
        if (date === '2026-09-30') return olderDate.promise;
        return Promise.resolve(breadth(date, date === '2026-10-01' ? 50 : 80));
    });
    render(<MemoryRouter><MarketDepthPage /></MemoryRouter>);
    await waitFor(() => expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('50%'));
    fireEvent.change(screen.getByLabelText('Date', { exact: true }), { target: { value: '2026-09-30' } });
    fireEvent.change(screen.getByLabelText('Date', { exact: true }), { target: { value: '2026-09-29' } });
    await waitFor(() => expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('80%'));
    await act(async () => olderDate.resolve(breadth('2026-09-30', 20)));
    expect(screen.getByLabelText('Date', { exact: true })).toHaveValue('2026-09-29');
    expect(document.querySelector('.lido-market-depth-cell')).toHaveTextContent('80%');
    expect(document.querySelector('.lido-market-depth-cell')).not.toHaveTextContent('20%');
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
