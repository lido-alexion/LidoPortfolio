import React, { useState } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import IndicesPage from '../../../resources/js/src/pages/IndicesPage';
import IndexSection from '../../../resources/js/src/components/indices/IndexSection';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));
vi.mock('../../../resources/js/src/components/indices/IndexComparisonChart', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/indices/IndiaVixAlertSettings', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/charts/PriceVolumeChart', async () => {
    const ReactModule = await import('react');
    return {
        default: ({ rows = [], loading, emptyMessage }) => ReactModule.createElement(
            'div',
            { 'data-testid': 'index-history-chart' },
            loading ? 'Loading chart…' : (rows.length ? rows.map((row) => row.close_price).join(', ') : emptyMessage),
        ),
    };
});

beforeEach(() => {
    cleanup();
    api.get.mockReset();
    api.post.mockReset();
    api.put.mockReset();
});

const index = {
    stock_id: 501,
    symbol: 'NIFTY50',
    name: 'Nifty 50',
    exchange: 'NSE',
    tier: 'broad',
    has_price_history: true,
    price_from: '2026-09-01',
    price_to: '2026-10-01',
    price_count: 20,
    latest_close: 200,
    latest_price_date: '2026-10-01',
    change_percent: {},
    constituents_available: true,
};

function indexOverview(indexes = [index]) {
    return { data: { data: { primary_symbol: 'NIFTY50', indexes } } };
}

function indexComparison(series = []) {
    return { data: { data: { months: 12, baseline_date: null, series } } };
}

function deferred() {
    let resolve;
    const promise = new Promise((yes) => { resolve = yes; });
    return { promise, resolve };
}

it.each(['overview', 'comparison-series', 'comparison-point'])('retries a malformed %s success without confusing it with a valid empty result', async (invalidPart) => {
    const pageResponses = invalidPart === 'overview'
        ? [{ data: { data: { primary_symbol: 'NIFTY50' } } }, indexOverview([])]
        : [indexOverview([index]), indexOverview([index])];
    const malformedComparison = invalidPart === 'comparison-point'
        ? { data: { data: { months: 12, baseline_date: null, series: [{ symbol: 'NIFTY50', name: 'Nifty 50', points: [{ date: '2026-10-01', gain_percent: null }] }] } } }
        : { data: { data: { months: 12, baseline_date: null } } };
    const comparisonResponses = invalidPart === 'overview'
        ? [indexComparison([]), indexComparison([])]
        : [malformedComparison, indexComparison([])];
    let pageRead = 0;
    let comparisonRead = 0;
    api.get.mockImplementation((path, options) => {
        if (path === '/indexes/page') return Promise.resolve(pageResponses[pageRead++]);
        if (path === '/indexes/comparison') {
            expect(options).toEqual({ params: { months: 12 } });
            return Promise.resolve(comparisonResponses[comparisonRead++]);
        }
        throw new Error(`Unexpected GET ${path}`);
    });

    render(<MemoryRouter><IndicesPage /></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Failed to load index data');
    expect(screen.queryByRole('heading', { name: 'Nifty 50' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Retry index data' }));

    expect(await screen.findByRole('heading', { name: 'Indices' })).toBeVisible();
    expect(screen.queryByRole('alert')).toBeNull();
    if (invalidPart === 'overview') {
        expect(screen.queryByRole('heading', { name: 'Nifty 50' })).toBeNull();
        expect(screen.getByRole('status')).toHaveTextContent('No index records are available.');
    } else {
        expect(screen.getByRole('button', { name: /Nifty 50 \(NIFTY50\)/ })).toBeVisible();
    }
    expect(pageRead).toBe(2);
    expect(comparisonRead).toBe(2);
    expect(api.post).not.toHaveBeenCalled();
    expect(api.put).not.toHaveBeenCalled();
});

it('rejects a malformed history body, retries the same index, and accepts a confirmed empty array', async () => {
    api.get.mockImplementationOnce(() => Promise.resolve({ data: { stock: { id: 501, symbol: 'NIFTY50' } } }))
        .mockImplementationOnce(() => Promise.resolve({
            data: {
                stock: { id: 501, symbol: 'NIFTY50' },
                data: [],
                has_price_history: false,
            },
        }));
    render(<MemoryRouter><IndexSection index={{ ...index, has_price_history: false, price_count: 0, price_from: null, price_to: null }} panelId="index-history-fixture" expanded onToggle={() => {}} /></MemoryRouter>);

    expect(await screen.findByRole('alert')).toHaveTextContent('Index history could not be loaded');
    expect(screen.getByRole('button', { name: 'Retry index history' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Retry index history' }));
    expect(await screen.findByTestId('index-history-chart')).toHaveTextContent('No price history in cache.');
    expect(screen.queryByRole('alert')).toBeNull();
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(api.get).toHaveBeenNthCalledWith(1, '/stocks/501/market-prices');
    expect(api.get).toHaveBeenNthCalledWith(2, '/stocks/501/market-prices');
    expect(api.post).not.toHaveBeenCalled();
    expect(api.put).not.toHaveBeenCalled();
});

it('offers Retry for the service’s HTTP-200 constituent failure and then renders the returned rows', async () => {
    let constituentRead = 0;
    api.get.mockImplementation((path) => {
        if (path === '/stocks/501/market-prices') {
            return Promise.resolve({ data: { stock: { id: 501, symbol: 'NIFTY50' }, data: [], has_price_history: false } });
        }
        if (path === '/indexes/NIFTY50/constituents') {
            constituentRead += 1;
            return Promise.resolve({ data: { data: {
                symbol: 'NIFTY50',
                name: 'Nifty 50',
                available: true,
                constituents: constituentRead === 1 ? [] : [{ symbol: 'TCS', name: 'Tata Consultancy Services', stock_id: 42 }],
                message: constituentRead === 1
                    ? 'Could not load constituents from NSE right now. Cached list is empty — try again later.'
                    : null,
            } } });
        }
        throw new Error(`Unexpected GET ${path}`);
    });
    render(<MemoryRouter><IndexSection index={index} panelId="index-constituent-fixture" expanded onToggle={() => {}} /></MemoryRouter>);

    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load constituents from NSE right now');
    expect(screen.getByRole('button', { name: 'Retry index constituents' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Retry index constituents' }));
    expect(await screen.findByRole('link', { name: 'TCS', exact: true })).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Retry index constituents' })).toBeNull();
    expect(api.get).toHaveBeenCalledTimes(3);
    expect(api.get).toHaveBeenNthCalledWith(2, '/indexes/NIFTY50/constituents');
    expect(api.get).toHaveBeenNthCalledWith(3, '/indexes/NIFTY50/constituents');
    expect(api.post).not.toHaveBeenCalled();
    expect(api.put).not.toHaveBeenCalled();
});

it('rejects successful subresource responses for a different index identity', async () => {
    api.get.mockImplementation((path) => {
        if (path === '/stocks/501/market-prices') {
            return Promise.resolve({ data: { stock: { id: 502, symbol: 'NIFTYBANK' }, data: [{ close_price: 888 }], has_price_history: true } });
        }
        if (path === '/indexes/NIFTY50/constituents') {
            return Promise.resolve({ data: { data: {
                symbol: 'NIFTYBANK',
                available: true,
                constituents: [{ symbol: 'BANKTCS', name: 'Wrong index fixture', stock_id: 42 }],
                message: null,
            } } });
        }
        throw new Error(`Unexpected GET ${path}`);
    });
    render(<MemoryRouter><IndexSection index={index} panelId="index-identity-fixture" expanded onToggle={() => {}} /></MemoryRouter>);

    const alerts = await screen.findAllByRole('alert');
    const alertText = alerts.map((alert) => alert.textContent).join(' ');
    expect(alertText).toContain('Index history could not be loaded');
    expect(alertText).toContain('Index constituents could not be loaded');
    expect(screen.getByRole('button', { name: 'Retry index history' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Retry index constituents' })).toBeVisible();
    expect(screen.queryByText('Wrong index fixture')).toBeNull();
    expect(screen.queryByText('888')).toBeNull();
    expect(api.post).not.toHaveBeenCalled();
    expect(api.put).not.toHaveBeenCalled();
});

it('ignores the collapsed index request after reopening and keeps the new request loading', async () => {
    const oldRequest = deferred();
    const reopenedRequest = deferred();
    let reads = 0;
    function ToggleSection() {
        const [expanded, setExpanded] = useState(true);
        return <IndexSection index={{ ...index, constituents_available: false }} panelId="index-reopen-fixture" expanded={expanded} onToggle={() => setExpanded((value) => !value)} />;
    }
    api.get.mockImplementation(() => (++reads === 1 ? oldRequest.promise : reopenedRequest.promise));
    render(<MemoryRouter><ToggleSection /></MemoryRouter>);

    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: /Nifty 50 \(NIFTY50\)/ }));
    expect(screen.queryByRole('region', { name: /Nifty 50 \(NIFTY50\)/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /Nifty 50 \(NIFTY50\)/ }));
    await waitFor(() => expect(api.get).toHaveBeenCalledTimes(2));
    expect(screen.getByTestId('index-history-chart')).toHaveTextContent('Loading chart…');

    await act(async () => oldRequest.resolve({
        data: { stock: { id: 501, symbol: 'NIFTY50' }, data: [{ close_price: 111 }], has_price_history: true },
    }));
    expect(screen.getByTestId('index-history-chart')).toHaveTextContent('Loading chart…');
    expect(screen.queryByText('111')).toBeNull();

    await act(async () => reopenedRequest.resolve({
        data: { stock: { id: 501, symbol: 'NIFTY50' }, data: [{ close_price: 222 }], has_price_history: true },
    }));
    expect(screen.getByTestId('index-history-chart')).toHaveTextContent('222');
    expect(screen.queryByText('111')).toBeNull();
    expect(api.post).not.toHaveBeenCalled();
    expect(api.put).not.toHaveBeenCalled();
});
