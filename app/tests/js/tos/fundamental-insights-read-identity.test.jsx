import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useNavigate } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import FundamentalInsightsPage from '../../../resources/js/src/pages/FundamentalInsightsPage';

vi.mock('../../../resources/js/src/api', async () => {
    const actual = await vi.importActual('../../../resources/js/src/api');
    return { default: { get: vi.fn(), post: vi.fn() }, getApiErrorMessage: actual.getApiErrorMessage };
});
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));

beforeEach(() => { cleanup(); vi.clearAllMocks(); });
const deferred = () => {
    let resolve; let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};
const unavailable = () => ({ response: { status: 503, data: { message: 'Synthetic old read failed' } }, message: 'Request failed with status code 503' });

function RouteControls() {
    const navigate = useNavigate();
    return <><button type="button" onClick={() => navigate('/fundamentals/insights/INFY')}>Select INFY</button><button type="button" onClick={() => navigate('/fundamentals/insights/TCS')}>Select TCS</button></>;
}

function renderPage(strictMode = false) {
    const tree = <MemoryRouter initialEntries={['/fundamentals/insights/TCS']}>
        <RouteControls />
        <Routes><Route path="/fundamentals/insights/:symbol" element={<FundamentalInsightsPage />} /></Routes>
    </MemoryRouter>;
    return render(strictMode ? <React.StrictMode>{tree}</React.StrictMode> : tree);
}

it('ignores stale route reads and their failure/finally while the current A→B→A read is pending', async () => {
    const firstA = deferred(); const oldB = deferred(); const currentA = deferred();
    let aReads = 0; const fundamentalsReads = [];
    api.get.mockImplementation((path, config) => {
        if (path === '/stocks/search') {
            const symbol = config.params.q;
            return Promise.resolve({ data: { data: [{ id: symbol === 'TCS' ? 42 : 43, symbol, exchange: 'NSE', name: `${symbol} fixture` }] } });
        }
        if (path === '/v1/stocks/42/fundamentals') {
            fundamentalsReads.push({ id: 42, ...config.params });
            return (++aReads === 1 ? firstA : currentA).promise;
        }
        if (path === '/v1/stocks/43/fundamentals') {
            fundamentalsReads.push({ id: 43, ...config.params });
            return oldB.promise;
        }
        throw new Error(`Unexpected GET ${path}`);
    });

    renderPage();
    await waitFor(() => expect(aReads).toBe(1));
    fireEvent.click(screen.getByRole('button', { name: 'Select INFY' }));
    await waitFor(() => expect(fundamentalsReads.some(read => read.id === 43)).toBe(true));
    expect(screen.getByRole('status')).toHaveTextContent('Loading insights');
    fireEvent.click(screen.getByRole('button', { name: 'Select TCS' }));
    await waitFor(() => expect(aReads).toBe(2));

    await act(async () => oldB.resolve({ data: { data: { as_of: '2026-10-17', insights: { summary: 'stale B evidence' } } } }));
    expect(screen.getByRole('status')).toHaveTextContent('Loading insights');
    expect(screen.queryByRole('heading', { name: /INFY/ })).toBeNull();
    expect(screen.queryByText('stale B evidence')).toBeNull();
    await act(async () => firstA.reject(unavailable()));
    expect(screen.getByRole('status')).toHaveTextContent('Loading insights');
    expect(screen.queryByRole('alert')).toBeNull();

    await act(async () => currentA.resolve({ data: { data: { as_of: '2026-10-18', freshness: { status: 'current' }, insights: null } } }));
    expect(await screen.findByText('As of 2026-10-18 · Freshness: current', { exact: true })).toBeVisible();
    expect(screen.queryByText('stale B evidence')).toBeNull();
    for (const read of fundamentalsReads) {
        expect(read.include_insights).toBe(1);
        expect(read.include_ai_insights).toBe(1);
    }
    expect(api.post).not.toHaveBeenCalled();
});

it('retains only same-symbol confirmed fundamentals as explicitly stale on refresh failure', async () => {
    let calls = 0;
    api.get.mockImplementation((path) => {
        if (path === '/stocks/search') return Promise.resolve({ data: { data: [{ id: 42, symbol: 'TCS', exchange: 'NSE', name: 'TCS fixture' }] } });
        if (path === '/v1/stocks/42/fundamentals') {
            calls += 1;
            if (calls === 1) return Promise.resolve({ data: { data: { as_of: '2026-10-17', freshness: { status: 'stale' }, insights: { summary: 'confirmed TCS evidence' } } } });
            return Promise.reject(unavailable());
        }
        throw new Error(`Unexpected GET ${path}`);
    });
    renderPage();
    expect(await screen.findByText('confirmed TCS evidence', { exact: true })).toBeVisible();
    fireEvent.click(await screen.findByRole('button', { name: 'Refresh AI insights' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic old read failed');
    expect(screen.getByText(/Showing last confirmed fundamentals for TCS/)).toBeVisible();
    expect(screen.getByText('confirmed TCS evidence', { exact: true })).toBeVisible();
    expect(calls).toBe(2);
    expect(api.post).not.toHaveBeenCalled();
});

it('ignores a pending fundamentals response after StrictMode cleanup and unmount', async () => {
    const reads = [];
    api.get.mockImplementation(path => {
        if (path === '/stocks/search') return Promise.resolve({ data: { data: [{ id: 42, symbol: 'TCS', exchange: 'NSE', name: 'TCS fixture' }] } });
        if (path === '/v1/stocks/42/fundamentals') {
            const read = deferred(); reads.push(read); return read.promise;
        }
        throw new Error(`Unexpected GET ${path}`);
    });
    const mounted = renderPage(true);
    await waitFor(() => expect(reads.length).toBeGreaterThan(0));
    mounted.unmount();
    await act(async () => {
        reads.forEach(read => read.resolve({ data: { data: { as_of: '2026-10-18', insights: { summary: 'late unmounted evidence' } } } }));
        await Promise.all(reads.map(read => read.promise));
    });
    expect(api.post).not.toHaveBeenCalled();
});
