import api from '../api';

export const BACKTEST_SESSION_KEY = 'lido_strategy_backtest_session';

/** Shown in New Backtest modal and while a run is in progress. */
export const BACKTEST_DURATION_NOTICE =
    'This operation can take several minutes depending on the backtest period. Please keep this page open until it finishes — leaving or closing the tab may interrupt progress.';

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

export class DuplicateBacktestInputError extends Error {
    constructor(message) {
        super(message);
        this.name = 'DuplicateBacktestInputError';
    }
}

function isoDateOnly(value) {
    if (value == null || value === '') {
        return '';
    }
    return String(value).slice(0, 10);
}

/**
 * Build POST /v1/backtests payload to start a NEW simulation from a history row's
 * stored inputs (period dates, capital, notes, tags). Omits strategy_version_id so
 * the engine resolves the current Strategy via ensureActive. Does not copy name,
 * trades, statistics, or snapshots.
 *
 * @param {object} run
 * @param {string} sessionToken
 * @returns {{
 *   from_date: string,
 *   to_date: string,
 *   initial_capital: number,
 *   tags: string[],
 *   session_token: string,
 *   range_key?: string,
 *   notes?: string,
 * }}
 */
export function duplicateBacktestPayload(run, sessionToken) {
    const fromDate = isoDateOnly(run?.from_date);
    const toDate = isoDateOnly(run?.to_date);
    if (!fromDate || !toDate) {
        throw new DuplicateBacktestInputError(
            'Cannot duplicate this backtest: the original run is missing period dates.',
        );
    }

    const capital = Number(run?.initial_capital);
    if (!Number.isFinite(capital) || capital < 1000) {
        throw new DuplicateBacktestInputError(
            'Cannot duplicate this backtest: the original run is missing a valid initial capital.',
        );
    }

    const token = String(sessionToken || '').trim();
    if (!token) {
        throw new DuplicateBacktestInputError(
            'Cannot duplicate this backtest: a session token is required.',
        );
    }

    const tags = Array.isArray(run?.tags)
        ? run.tags
            .filter((tag) => typeof tag === 'string' && tag.trim() !== '')
            .map((tag) => tag.trim())
        : [];

    const payload = {
        from_date: fromDate,
        to_date: toDate,
        initial_capital: capital,
        tags,
        session_token: token,
    };

    if (run?.range_key) {
        payload.range_key = String(run.range_key);
    }

    if (run?.notes != null) {
        payload.notes = String(run.notes);
    }

    return payload;
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
