import React from 'react';
import { act, afterEach, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { apiMock, axiosError } from './helpers/mockApi';
import AIStrategyPromptBuilder from '../../../resources/js/src/components/strategy/AIStrategyPromptBuilder';

const STRATEGY_PATH = '/ai/insights/strategy';
const result = {
    status: 'ready',
    fingerprint: 'strategy-cache-a'.padEnd(64, 'a'),
    response: {
        strategy_summary: 'Cached advisory design for the selected inputs.',
        target_market_universe: 'NSE shares in the configured universe.',
        entry_logic: 'Review a confirmed range expansion.',
        exit_logic: 'Use the configured score-based exit.',
        allocation_position_sizing: 'Respect the configured maximum positions.',
        risk_controls: 'Review exposure before any separate action.',
        assumptions: 'Daily price history is available.',
        caveats: 'This fixture is not investment advice.',
        rationale: 'The design matches the selected research profile.',
        artifact_compatibility_notes: 'Advisory result only; no strategy artifact was created.',
    },
    data_as_of: { ohlcv_through: '2026-10-16' },
};

const ok = (value) => ({ data: { data: value } });
const clipboardDescriptor = Object.getOwnPropertyDescriptor(navigator, 'clipboard');

afterEach(() => {
    if (clipboardDescriptor) Object.defineProperty(navigator, 'clipboard', clipboardDescriptor);
    else delete navigator.clipboard;
});

function renderBuilder() {
    return render(
        <React.StrictMode>
            <MemoryRouter>
                <AIStrategyPromptBuilder />
            </MemoryRouter>
        </React.StrictMode>,
    );
}

describe('AI-09 Strategy Designer recovery', () => {
    it('retains the matching cached design after a failed explicit regenerate and copies the maintained prompt', async () => {
        const calls = [];
        apiMock.post.mockImplementation(async (url, body) => {
            calls.push({ url, body });
            if (url !== STRATEGY_PATH) throw new Error(`Unexpected API path: ${url}`);
            if (body.lookup_only) return ok(result);
            if (body.refresh === true && body.lookup_only === false) {
                throw axiosError('Managed strategy refresh unavailable', { status: 503 });
            }
            throw new Error('Unexpected implicit strategy generation.');
        });
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });

        renderBuilder();
        fireEvent.click(screen.getByRole('button', { name: 'AI Strategy Designer', exact: true }));

        await screen.findByText('Cached advisory design for the selected inputs.');
        for (const heading of [
            'Strategy summary', 'Target market / universe', 'Entry logic', 'Exit logic',
            'Allocation / position sizing', 'Risk controls', 'Assumptions', 'Caveats',
            'Rationale', 'StoX artifact compatibility',
        ]) {
            expect(screen.getByRole('heading', { name: heading, exact: true })).toBeVisible();
        }
        expect(screen.getByText('OHLCV through 2026-10-16', { exact: true })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Copy result', exact: true })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Create draft strategy', exact: true })).toBeVisible();
        expect(screen.queryByRole('button', { name: 'Copy AI Prompt', exact: true })).not.toBeInTheDocument();
        expect(calls.filter(({ body }) => !body.lookup_only)).toHaveLength(0);

        fireEvent.click(screen.getByRole('button', { name: 'Regenerate', exact: true }));
        await screen.findByText('Fresh AI refresh unavailable; showing the latest cached insight.', { exact: true });
        expect(screen.getByText('Cached advisory design for the selected inputs.', { exact: true })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Copy result', exact: true })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Copy AI Prompt', exact: true }));

        await waitFor(() => expect(writeText).toHaveBeenCalled());
        const copied = writeText.mock.calls[0][0];
        expect(copied).toContain('You are an expert quantitative trading system designer.');
        expect(copied).toContain('INVESTING STYLE\n\nMomentum Investing');
        expect(copied).toContain('MAXIMUM POSITIONS\n\n5');
        expect(copied).toContain('ADDITIONAL CONSTRAINTS\n\nNone');
        expect(copied).not.toMatch(/\{\{\w+\}\}/);
        expect(calls.some(({ url }) => url.endsWith('/draft'))).toBe(false);
        expect(calls.some(({ url, body }) => url === STRATEGY_PATH && body.refresh === true && body.lookup_only === false)).toBe(true);
    });

    it('does not let a late lookup for prior inputs replace the current missing-result state', async () => {
        const deferred = [];
        apiMock.post.mockImplementation((url, body, config = {}) => new Promise((resolve, reject) => {
            deferred.push({ url, body, signal: config.signal, resolve, reject, settled: false });
        }));

        renderBuilder();
        fireEvent.click(screen.getByRole('button', { name: 'AI Strategy Designer', exact: true }));
        await waitFor(() => {
            expect(deferred.some((entry) => entry.body.lookup_only && entry.body.inputs.maximumPositions === 5)).toBe(true);
        });
        const priorInputLookups = deferred.filter((entry) => entry.body.lookup_only && entry.body.inputs.maximumPositions === 5);
        expect(priorInputLookups.length).toBeGreaterThan(0);

        fireEvent.change(screen.getByLabelText('Maximum Positions'), { target: { value: '6' } });
        await waitFor(() => {
            expect(deferred.some((entry) => entry.body.lookup_only && entry.body.inputs.maximumPositions === 6)).toBe(true);
        });
        const currentInputLookups = deferred.filter((entry) => entry.body.lookup_only && entry.body.inputs.maximumPositions === 6);
        expect(priorInputLookups.some((entry) => entry.signal?.aborted)).toBe(true);

        await act(async () => {
            currentInputLookups.forEach((entry) => {
                entry.settled = true;
                entry.resolve(ok({ status: 'missing' }));
            });
            await Promise.resolve();
            await Promise.resolve();
        });
        expect(screen.getByRole('button', { name: 'Generate strategy', exact: true })).toBeVisible();

        await act(async () => {
            priorInputLookups.forEach((entry) => {
                if (!entry.settled) {
                    entry.settled = true;
                    entry.resolve(ok(result));
                }
            });
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(screen.getByRole('button', { name: 'Generate strategy', exact: true })).toBeVisible();
        expect(screen.queryByText('Cached advisory design for the selected inputs.', { exact: true })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Copy AI Prompt', exact: true })).not.toBeInTheDocument();
    });
});
