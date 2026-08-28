import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import React from 'react';
import { MemoryRouter } from 'react-router-dom';
import BacktestHistoryPage from '../../../resources/js/src/pages/BacktestHistoryPage.jsx';
import {
    DuplicateBacktestInputError,
    duplicateBacktestPayload,
} from '../../../resources/js/src/utils/backtestHelpers.js';
import { apiMock } from './helpers/mockApi.js';
import { apiEnvelope, axiosOk } from './fixtures/tosApi.js';

const ORIGINAL_RUN = {
    id: 11,
    name: 'Original Frozen Run',
    from_date: '2024-01-02',
    to_date: '2024-01-03',
    range_key: '6m',
    initial_capital: 2500000,
    notes: 'keep these notes',
    tags: ['swing', 'v4'],
    strategy_id: 1,
    strategy_version_id: 99,
    strategy_name: 'Stale Snapshot Strategy',
    status: 'completed',
    statistics: { return_pct: 42.5, copied_marker: true },
};

const META = {
    ranges: [
        { id: '1y', label: '1 year' },
        { id: '6m', label: '6 months' },
        { id: '15d', label: '15 days' },
    ],
};

function pathOf(url) {
    return String(url || '').split('?')[0];
}

function installBacktestHandlers({
    runs = [ORIGINAL_RUN],
    createdRun = {
        id: 12,
        name: 'Current Live Strategy · 2024-01-02 → 2024-01-03',
        status: 'completed',
        from_date: '2024-01-02',
        to_date: '2024-01-03',
        initial_capital: 2500000,
        notes: 'keep these notes',
        tags: ['swing', 'v4'],
        strategy_version_id: 1,
    },
} = {}) {
    apiMock.get.mockImplementation(async (url) => {
        const path = pathOf(url);
        if (path === '/v1/backtests') {
            return axiosOk(apiEnvelope({ runs }));
        }
        if (path === '/v1/backtests/meta') {
            return axiosOk(apiEnvelope(META));
        }
        throw new Error(`unexpected GET ${path}`);
    });
    apiMock.post.mockImplementation(async (url) => {
        const path = pathOf(url);
        if (path === '/v1/backtests') {
            return axiosOk(apiEnvelope({
                run: createdRun,
                completed: true,
                continued: false,
            }));
        }
        throw new Error(`unexpected POST ${path}`);
    });
    apiMock.delete.mockImplementation(async () => axiosOk(apiEnvelope({ deleted: true, id: 11 })));
}

function renderHistory() {
    return render(
        <MemoryRouter initialEntries={['/backtests']}>
            <BacktestHistoryPage />
        </MemoryRouter>,
    );
}

describe('duplicateBacktestPayload (V4-FEAT-014)', () => {
    it('copies period, capital, notes, and tags and omits strategy snapshot fields', () => {
        const payload = duplicateBacktestPayload(ORIGINAL_RUN, 'session-token-1');
        expect(payload).toEqual({
            from_date: '2024-01-02',
            to_date: '2024-01-03',
            range_key: '6m',
            initial_capital: 2500000,
            notes: 'keep these notes',
            tags: ['swing', 'v4'],
            session_token: 'session-token-1',
        });
        expect(payload).not.toHaveProperty('strategy_version_id');
        expect(payload).not.toHaveProperty('strategy_id');
        expect(payload).not.toHaveProperty('name');
        expect(payload).not.toHaveProperty('statistics');
        expect(payload).not.toHaveProperty('id');
    });

    it('stops when period dates are missing', () => {
        expect(() => duplicateBacktestPayload(
            { ...ORIGINAL_RUN, from_date: null },
            'session-token-1',
        )).toThrow(DuplicateBacktestInputError);
    });

    it('stops when initial capital is missing', () => {
        expect(() => duplicateBacktestPayload(
            { ...ORIGINAL_RUN, initial_capital: null },
            'session-token-1',
        )).toThrow(DuplicateBacktestInputError);
    });
});

describe('Backtest history Duplicate (V4-FEAT-014)', () => {
    it('enables Duplicate and POSTs stored inputs without strategy_version_id', async () => {
        installBacktestHandlers();
        renderHistory();

        expect(await screen.findByRole('button', { name: 'Duplicate' })).toBeEnabled();
        expect(screen.getByRole('link', { name: 'Open' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Delete' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'New Backtest' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Duplicate' }));

        await waitFor(() => {
            expect(apiMock.post).toHaveBeenCalled();
        });
        const createCall = apiMock.post.mock.calls.find((call) => call[0] === '/v1/backtests');
        expect(createCall[1]).toMatchObject({
            from_date: '2024-01-02',
            to_date: '2024-01-03',
            range_key: '6m',
            initial_capital: 2500000,
            notes: 'keep these notes',
            tags: ['swing', 'v4'],
        });
        expect(createCall[1].session_token).toEqual(expect.any(String));
        expect(createCall[1]).not.toHaveProperty('strategy_version_id');
        expect(createCall[1]).not.toHaveProperty('name');
        expect(createCall[1]).not.toHaveProperty('statistics');
    });

    it('does not POST when the original run is missing period dates', async () => {
        installBacktestHandlers({
            runs: [{ ...ORIGINAL_RUN, from_date: null, to_date: null }],
        });
        renderHistory();
        fireEvent.click(await screen.findByRole('button', { name: 'Duplicate' }));
        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Duplicate' })).toBeEnabled();
        });
        expect(apiMock.post).not.toHaveBeenCalled();
    });

    it('keeps New Backtest posting range_key without copying a prior run', async () => {
        installBacktestHandlers();
        renderHistory();
        fireEvent.click(await screen.findByRole('button', { name: 'New Backtest' }));
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Fresh run' } });
        fireEvent.submit(screen.getByRole('button', { name: 'Start' }).closest('form'));

        await waitFor(() => {
            expect(apiMock.post).toHaveBeenCalled();
        });
        const createCall = apiMock.post.mock.calls.find((call) => call[0] === '/v1/backtests');
        expect(createCall[1]).toMatchObject({
            name: 'Fresh run',
            range_key: '1y',
            initial_capital: 1000000,
        });
        expect(createCall[1]).not.toHaveProperty('from_date');
        expect(createCall[1]).not.toHaveProperty('to_date');
        expect(createCall[1]).not.toHaveProperty('strategy_version_id');
    });

    it('keeps Delete on the original row', async () => {
        installBacktestHandlers();
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        renderHistory();
        fireEvent.click(await screen.findByRole('button', { name: 'Delete' }));
        await waitFor(() => {
            expect(apiMock.delete).toHaveBeenCalledWith('/v1/backtests/11');
        });
    });
});
