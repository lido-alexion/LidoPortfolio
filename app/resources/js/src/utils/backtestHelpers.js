import api from '../api';

export const BACKTEST_SESSION_KEY = 'lido_strategy_backtest_session';

const MAX_CONTINUE_ITERATIONS = 2000;

export function getOrCreateBacktestSessionToken() {
    let token = localStorage.getItem(BACKTEST_SESSION_KEY);
    if (!token) {
        token = typeof crypto !== 'undefined' && crypto.randomUUID
            ? crypto.randomUUID()
            : `bt-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
        localStorage.setItem(BACKTEST_SESSION_KEY, token);
    }
    return token;
}

export function parseTagsInput(raw) {
    return String(raw || '')
        .split(',')
        .map((t) => t.trim())
        .filter(Boolean);
}

export function formatBacktestStage(stage) {
    const labels = {
        PREPARING: 'Preparing eligibility',
        SIMULATING_DAYS: 'Simulating trading days',
        GENERATING_STATISTICS: 'Generating statistics',
        GENERATING_REPORT: 'Generating report',
        COMPLETED: 'Completed',
        FAILED: 'Failed',
    };
    return labels[stage] || stage || '—';
}

export function backtestStatusBadgeClass(status) {
    switch (status) {
        case 'completed':
            return 'text-bg-success';
        case 'running':
            return 'text-bg-primary';
        case 'preparing':
            return 'text-bg-info';
        case 'failed':
            return 'text-bg-danger';
        default:
            return 'text-bg-secondary';
    }
}

export function isBacktestInProgress(run) {
    if (!run) return false;
    return run.status === 'preparing' || run.status === 'running';
}

/**
 * Resume a chunked backtest until completed, failed, or iteration cap.
 * @param {number} runId
 * @param {(run: object) => void} [onProgress]
 * @returns {Promise<{ run: object, continued: boolean, completed: boolean }>}
 */
export async function continueBacktestUntilDone(runId, onProgress) {
    let result = { run: null, continued: true, completed: false };
    let iterations = 0;

    while (result.continued && iterations < MAX_CONTINUE_ITERATIONS) {
        const res = await api.post(`/v1/backtests/${runId}/continue`);
        result = res.data?.data || result;
        if (result.run) {
            onProgress?.(result.run);
        }
        if (result.completed || result.run?.status === 'completed') {
            return { ...result, completed: true, continued: false };
        }
        if (result.run?.status === 'failed') {
            return { ...result, continued: false, completed: false };
        }
        iterations += 1;
    }

    if (iterations >= MAX_CONTINUE_ITERATIONS) {
        throw new Error('Backtest resume timed out — open the run to continue polling.');
    }

    return result;
}

/**
 * Start a new backtest and poll until done.
 */
export async function startBacktest(payload, onProgress) {
    const res = await api.post('/v1/backtests', payload);
    let result = res.data?.data || {};
    if (result.run) {
        onProgress?.(result.run);
    }
    if (result.completed || result.run?.status === 'completed') {
        return result;
    }
    if (result.run?.status === 'failed') {
        return result;
    }
    if (result.continued && result.run?.id) {
        const resumed = await continueBacktestUntilDone(result.run.id, onProgress);
        return { ...result, ...resumed, run: resumed.run || result.run };
    }
    return result;
}
