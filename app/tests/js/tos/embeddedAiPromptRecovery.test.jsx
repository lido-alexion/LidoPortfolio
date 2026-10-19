import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { apiMock } from './helpers/mockApi';
import AnalyseStockButton from '../../../resources/js/src/components/AnalyseStockButton';

const stock = { id: 42, symbol: 'TCS', name: 'Tata Consultancy Services', exchange: 'NSE' };
const cachedInsight = {
    status: 'ready',
    fingerprint: 'c'.repeat(64),
    response: {
        summary: 'Cached company evidence remains available.',
        technical_price_context: 'Recent price evidence is limited.',
        fundamental_context: 'No recent fundamentals were available.',
        positive_signals: 'No additional signal asserted.',
        risks_watch_items: 'Price history is incomplete.',
        what_to_check_next: 'Review the next company filing.',
        data_limitations: 'Two expected prices are unavailable.',
    },
    data_as_of: { ohlcv_through: '2026-09-09', fundamentals_period: '2026-06-30', holding_personalized: true },
};
const newestFirstRows = [
    { price_date: '2026-09-09', open_price: 107.4, high_price: 109.5, low_price: 106.9, close_price: 108.8, volume: 7890 },
    { price_date: '2026-09-08', open_price: 106.8, high_price: 108.3, low_price: 106.2, close_price: 107.4, volume: 6789 },
    { price_date: '2026-09-07', open_price: 105.2, high_price: null, low_price: 104.6, close_price: 106.8, volume: 5678 },
    { price_date: '2026-09-04', open_price: 103.5, high_price: 106, low_price: 103, close_price: 105.2, volume: 4567 },
    { price_date: '2026-09-03', open_price: 102.8, high_price: 104.4, low_price: 101.9, close_price: 103.5, volume: 3456 },
    { price_date: '2026-09-02', open_price: 101, high_price: 103.2, low_price: 100.7, close_price: 102.8, volume: 2345 },
    { price_date: '2026-09-01', open_price: 100, high_price: 102.5, low_price: 99.1, close_price: 101, volume: 1200 },
    { price_date: '2026-08-31', open_price: 999.99, high_price: 999.99, low_price: 999.99, close_price: 999.99, volume: 999999 },
];
const marketHistory = {
    stock,
    data: newestFirstRows,
    price_count: newestFirstRows.length,
    has_price_history: true,
    from_date: '2026-08-31',
    to_date: '2026-09-09',
    latest_close: 108.8,
    latest_price_date: '2026-09-09',
};
const expectedOhlcv = '[2026-09-01: O=100.00 H=102.50 L=99.10 C=101.00 V=1,200; 2026-09-02: O=101.00 H=103.20 L=100.70 C=102.80 V=2,345; 2026-09-03: O=102.80 H=104.40 L=101.90 C=103.50 V=3,456; 2026-09-04: O=103.50 H=106.00 L=103.00 C=105.20 V=4,567; 2026-09-07: O=105.20 H=— L=104.60 C=106.80 V=5,678; 2026-09-08: O=106.80 H=108.30 L=106.20 C=107.40 V=6,789; 2026-09-09: O=107.40 H=109.50 L=106.90 C=108.80 V=7,890]';
const sectionHeadings = [
    '1. Current Price & Fundamentals:',
    '2. Shareholding Shift & Smart Money Tracking:',
    '3. Revenue Visibility & Order Book:',
    '4. Operational Efficiency & Governance Risks:',
    '5. Mid-Term Technical Outlook (6 Months - 1 Year):',
    '6. Short-Term Technical Outlook:',
    '7. 7-Day OHLC & Volume Pattern Analysis:',
    '8. Balanced Investment Thesis Summary:',
];
const clipboardDescriptor = Object.getOwnPropertyDescriptor(navigator, 'clipboard');
let clipboardWrite;
let toastEvents;
const recordToast = event => toastEvents.push(event.detail);

const axiosOk = value => ({ data: { data: value } });
const marketApiOk = value => ({ data: value });
const axiosFailure = status => Object.assign(new Error(`Request failed with status code ${status}`), {
    response: { status, data: { success: false, error: { code: 'AI_UNAVAILABLE' } }, headers: {} },
});

function renderTcsInsight() {
    return render(<React.StrictMode><MemoryRouter><div className="card-body">
        <AnalyseStockButton stockId={stock.id} symbol={stock.symbol} name={stock.name} />
    </div></MemoryRouter></React.StrictMode>);
}

function expectCopiedPrompt(prompt) {
    expect(prompt).toContain('Please provide a comprehensive stock analysis for Tata Consultancy Services (TCS).');
    for (const heading of sectionHeadings) expect(prompt).toContain(heading);
        expect(prompt).toContain(`${sectionHeadings[6]} ${expectedOhlcv}. Analyze this specific multi-day data block`);
    expect(prompt).not.toContain('2026-08-31');
    expect(prompt).not.toContain('999.99');
}

beforeEach(() => {
    apiMock.get.mockReset();
    apiMock.post.mockReset();
    toastEvents = [];
    window.addEventListener('portfolio-toast', recordToast);
    clipboardWrite = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: { writeText: clipboardWrite },
    });
});

afterEach(() => {
    cleanup();
    window.removeEventListener('portfolio-toast', recordToast);
    if (clipboardDescriptor) Object.defineProperty(navigator, 'clipboard', clipboardDescriptor);
    else delete navigator.clipboard;
    apiMock.get.mockReset();
    apiMock.post.mockReset();
    vi.restoreAllMocks();
});

describe('AI-08 manual prompt recovery', () => {
    it('keeps cached evidence after refresh failure and recovers after a failed market read', async () => {
        apiMock.post.mockImplementation(async (_url, payload) => {
            if (payload.refresh) throw axiosFailure(503);
            return axiosOk(cachedInsight);
        });
        apiMock.get.mockRejectedValueOnce(axiosFailure(503)).mockResolvedValueOnce(marketApiOk(marketHistory));

        renderTcsInsight();
        fireEvent.click(screen.getByRole('button', { name: 'Open AI Insights' }));
        const surface = await screen.findByRole('dialog', { name: 'AI Insights — TCS' });
        expect(await screen.findByText('Cached company evidence remains available.')).toBeInTheDocument();
        for (const heading of ['Summary', 'Technical / price context', 'Fundamental context', 'Positive signals', 'Risks / watch items', 'What to check next', 'Data limitations']) {
            expect(screen.getByRole('heading', { name: heading })).toBeInTheDocument();
        }
        expect(screen.getByText('OHLCV through 2026-09-09')).toBeInTheDocument();
        expect(screen.getByText('Fundamentals through 2026-06-30')).toBeInTheDocument();
        expect(screen.getByText('Personalized with your active portfolio holding')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Copy AI Prompt' })).not.toBeInTheDocument();

        fireEvent.click(within(surface).getByRole('button', { name: 'Refresh insight' }));
        expect(await screen.findByText('Fresh AI refresh unavailable; showing the latest cached insight.')).toBeInTheDocument();
        expect(screen.getByText('Cached company evidence remains available.')).toBeInTheDocument();
        expect(within(surface).getByRole('button', { name: 'Copy AI Prompt' })).toBeInTheDocument();

        fireEvent.click(within(surface).getByRole('button', { name: 'Copy AI Prompt' }));
        await waitFor(() => expect(apiMock.get).toHaveBeenCalledTimes(1));
        expect(apiMock.get).toHaveBeenNthCalledWith(1, '/stocks/42/market-prices', { skipErrorToast: true });
        expect(toastEvents).toContainEqual({ message: 'Could not build the AI prompt. Please try again.', variant: 'danger' });
        expect(clipboardWrite).not.toHaveBeenCalled();
        expect(screen.getByText('Cached company evidence remains available.')).toBeInTheDocument();

        fireEvent.click(within(surface).getByRole('button', { name: 'Copy AI Prompt' }));
        await waitFor(() => expect(clipboardWrite).toHaveBeenCalledTimes(1));
        expect(apiMock.get).toHaveBeenNthCalledWith(2, '/stocks/42/market-prices', { skipErrorToast: true });
        expectCopiedPrompt(clipboardWrite.mock.calls[0][0]);
        expect(toastEvents).toContainEqual({ message: 'Copied to clipboard.', variant: 'success' });
        expect(screen.getByText('Cached company evidence remains available.')).toBeInTheDocument();

        const insightPosts = apiMock.post.mock.calls.filter(([url]) => url === '/ai/insights/stocks/42');
        expect(insightPosts.length).toBeGreaterThan(0);
        expect(insightPosts.filter(([, payload]) => payload.refresh === false).length).toBeGreaterThan(0);
        expect(insightPosts.filter(([, payload]) => payload.refresh === true)).toHaveLength(1);
        expect(insightPosts.every(([url, payload]) => url === '/ai/insights/stocks/42' && (payload.refresh === false || payload.refresh === true))).toBe(true);
    });

    it('offers a working prompt when no managed insight is available and closes back to Holdings', async () => {
        apiMock.post.mockRejectedValue(axiosFailure(503));
        apiMock.get.mockResolvedValue(marketApiOk(marketHistory));

        renderTcsInsight();
        const open = screen.getByRole('button', { name: 'Open AI Insights' });
        fireEvent.click(open);
        const surface = await screen.findByRole('dialog', { name: 'AI Insights — TCS' });
        expect(await screen.findByText('Managed AI is unavailable. You can copy the AI prompt and try manually.')).toBeInTheDocument();
        expect(within(surface).getByRole('button', { name: 'Copy AI Prompt' })).toBeInTheDocument();
        expect(within(surface).queryByRole('button', { name: 'Copy insight' })).not.toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Summary' })).not.toBeInTheDocument();

        fireEvent.click(within(surface).getByRole('button', { name: 'Copy AI Prompt' }));
        await waitFor(() => expect(clipboardWrite).toHaveBeenCalledTimes(1));
        expect(apiMock.get).toHaveBeenCalledWith('/stocks/42/market-prices', { skipErrorToast: true });
        expectCopiedPrompt(clipboardWrite.mock.calls[0][0]);
        expect(screen.getByText('Managed AI is unavailable. You can copy the AI prompt and try manually.')).toBeInTheDocument();

        expect(apiMock.post.mock.calls.length).toBeGreaterThan(0);
        expect(apiMock.post.mock.calls.every(([url, payload]) => url === '/ai/insights/stocks/42' && payload.refresh === false)).toBe(true);
    });
});
