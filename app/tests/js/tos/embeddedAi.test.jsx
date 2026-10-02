import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi } from 'vitest';
import { apiMock } from './helpers/mockApi';
import AnalyseStockButton from '../../../resources/js/src/components/AnalyseStockButton';
import ManagedStrategyDesigner from '../../../resources/js/src/components/strategy/ManagedStrategyDesigner';
import { DEFAULT_STRATEGY_PROMPT_INPUTS } from '../../../resources/js/src/strategyPrompt/defaults';

const result = { status: 'ready', fingerprint: 'f'.repeat(64), response: { summary: 'Measured evidence', data_limitations: 'No recent fundamentals.' }, data_as_of: { ohlcv_through: '2026-10-01', holding_personalized: true } };
const ok = value => ({ data: { data: value } });
function stock(presentation = 'dense') { return render(<MemoryRouter><div className="card-body"><AnalyseStockButton stockId={42} symbol="TCS" presentation={presentation} /></div></MemoryRouter>); }

describe('AI-003 shared stock capability', () => {
    it('opens immediately, renders modal, copies insight and navigates without prompt leakage', async () => {
        let finish;
        apiMock.post.mockImplementation(() => new Promise(resolve => { finish = resolve; }));
        const copy = vi.fn().mockResolvedValue();
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: copy } });
        stock();
        expect(apiMock.post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Open AI Insights' }));
        expect(screen.getByRole('dialog', { name: 'AI Insights — TCS' })).toBeVisible();
        expect(screen.getByText('Generating AI insight…')).toBeVisible();
        finish(ok(result));
        await screen.findByText('Measured evidence');
        expect(screen.getByText('Personalized with your active portfolio holding')).toBeVisible();
        expect(screen.queryByText('Copy AI Prompt')).not.toBeInTheDocument();
        fireEvent.click(screen.getByText('Copy insight'));
        await waitFor(() => expect(copy).toHaveBeenCalledWith(expect.stringContaining('OHLCV through 2026-10-01')));
        expect(screen.getByRole('link', { name: 'Open stock details' })).toHaveAttribute('href', '/holdings/42/prices');
        expect(apiMock.post.mock.calls[0][0]).toBe('/ai/insights/stocks/42');
    });
    it('uses inline and wide pane presentations, preserves cache on failed refresh and offers fallback', async () => {
        apiMock.post.mockResolvedValueOnce(ok(result)).mockResolvedValueOnce(ok({ ...result, degraded: true }));
        apiMock.get.mockResolvedValue({ data: { data: [] } });
        const view = stock('inline');
        fireEvent.click(screen.getByRole('button', { name: 'Open AI Insights' }));
        await screen.findByText('Measured evidence');
        expect(document.querySelector('.stox-insight-inline')).toBeTruthy();
        fireEvent.click(screen.getByText('Refresh insight'));
        await screen.findByText('Copy AI Prompt');
        expect(screen.getByText('Measured evidence')).toBeVisible();
        expect(apiMock.post.mock.calls[1][1]).toEqual({ refresh: true });
        fireEvent.click(screen.getByText('Copy AI Prompt'));
        await waitFor(() => expect(apiMock.get).toHaveBeenCalled());
        view.unmount();
        window.matchMedia.mockReturnValue({ matches: true, addEventListener: vi.fn(), removeEventListener: vi.fn() });
        apiMock.post.mockResolvedValue(ok(result)); stock();
        fireEvent.click(screen.getByRole('button', { name: 'Open AI Insights' }));
        await screen.findByText('Measured evidence');
        expect(document.querySelector('.stox-insight-pane')).toBeTruthy();
        expect(document.body).toHaveClass('stox-insight-pane-open');
        fireEvent(window, new CustomEvent('portfolio-changed', { detail: { portfolioId: 2 } }));
        expect(screen.queryByText('Measured evidence')).not.toBeInTheDocument();
    });
    it('discards failed partial results and still exposes recovery without breaking the button', async () => {
        apiMock.post.mockRejectedValue(new Error('stream interrupted'));
        stock(); fireEvent.click(screen.getByRole('button', { name: 'Open AI Insights' }));
        await screen.findByText('Copy AI Prompt');
        expect(screen.queryByText('Copy insight')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Close AI Insights' }));
        expect(screen.getByRole('button', { name: 'Open AI Insights' })).toBeEnabled();
    });
});

describe('Managed Strategy Designer', () => {
    it('an immediate explicit generation is not cancelled by the pending cache lookup', async () => {
        apiMock.post.mockResolvedValue(ok({ ...result, response: { strategy_summary: 'Explicit design' } }));
        render(<ManagedStrategyDesigner inputs={DEFAULT_STRATEGY_PROMPT_INPUTS} fallback={() => 'prompt'} />);
        fireEvent.click(screen.getByText('Generate strategy'));
        await screen.findByText('Explicit design');
        await new Promise(resolve => setTimeout(resolve, 250));
        expect(apiMock.post).toHaveBeenCalledTimes(1);
        expect(apiMock.post.mock.calls[0][1].lookup_only).toBe(false);
    });
    it('restores matching cache, hides stale results on input change and enters existing approval flow only explicitly', async () => {
        const strategy = { ...result, response: { strategy_summary: 'Advisory design', draft_envelope: { name: 'Draft' } } };
        apiMock.post.mockImplementation(async (url, body) => url.endsWith('/draft') ? ok({ id: 'run1', status: 'awaiting_approval', preview: [], objective: 'Create draft', plan_hash: 'a'.repeat(64) }) : ok(body.inputs.maximumPositions === 5 ? strategy : { status: 'missing' }));
        const fallback = vi.fn(() => 'manual prompt');
        const view = render(<ManagedStrategyDesigner inputs={DEFAULT_STRATEGY_PROMPT_INPUTS} fallback={fallback} />);
        await screen.findByText('Advisory design');
        expect(apiMock.post.mock.calls.every(([, body]) => body.lookup_only)).toBe(true);
        expect(screen.queryByText('Copy AI Prompt')).not.toBeInTheDocument();
        fireEvent.click(screen.getByText('Create draft strategy'));
        await screen.findByText('Approve changes');
        expect(apiMock.post).toHaveBeenCalledWith('/ai/insights/strategy/draft', { fingerprint: result.fingerprint }, expect.anything());
        expect(apiMock.post.mock.calls.some(([url]) => url.endsWith('/approve'))).toBe(false);
        view.rerender(<ManagedStrategyDesigner inputs={{ ...DEFAULT_STRATEGY_PROMPT_INPUTS, maximumPositions: 6 }} fallback={fallback} />);
        expect(screen.queryByText('Advisory design')).not.toBeInTheDocument();
        expect(screen.queryByText('Approve changes')).not.toBeInTheDocument();
        await screen.findByText('Generate strategy');
    });
});
