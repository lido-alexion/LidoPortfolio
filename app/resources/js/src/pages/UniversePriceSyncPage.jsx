import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';

const SCOPE_OPTIONS = [
    { value: 'all_nse', label: 'All NSE equities' },
    { value: 'nifty500', label: 'NIFTY 500' },
];

function formatTimestamp(value) {
    if (!value) {
        return '—';
    }
    try {
        return new Date(value).toLocaleString();
    } catch {
        return value;
    }
}

function statusBadgeClass(ok) {
    return ok ? 'bg-success' : 'bg-warning text-dark';
}

export default function UniversePriceSyncPage() {
    const [status, setStatus] = useState(null);
    const [scope, setScope] = useState('all_nse');
    const [batchSize, setBatchSize] = useState('');
    const [loading, setLoading] = useState(true);
    const [running, setRunning] = useState(false);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [loadError, setLoadError] = useState('');

    const loadStatus = useCallback(async (activeScope) => {
        setLoadError('');
        try {
            const { data } = await api.get('/universe-price-sync/status', {
                params: { scope: activeScope },
            });
            setStatus(data.data);
        } catch (error) {
            setLoadError(error?.response?.data?.message || 'Failed to load status.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadStatus(scope);
    }, [loadStatus, scope]);

    useEffect(() => {
        if (!autoRefresh || running) {
            return undefined;
        }
        const timer = setInterval(() => {
            loadStatus(scope);
        }, 15000);
        return () => clearInterval(timer);
    }, [autoRefresh, running, loadStatus, scope]);

    const runSync = async (payload) => {
        setRunning(true);
        try {
            const { data } = await api.post('/universe-price-sync/run', {
                scope,
                ...payload,
            });
            setStatus(data.data?.status ?? data.data);
            const detail = data.data?.run?.cycle_completed
                ? 'Full universe cycle completed.'
                : `Processed ${data.data?.run?.processed ?? 0} stock(s).`;
            showToast(`Batch completed: ${detail}`, 'success');
        } catch (error) {
            const message = error?.response?.data?.message || 'Sync request failed.';
            showToast(message, 'danger');
            if (error?.response?.data?.data) {
                setStatus(error.response.data.data);
            }
        } finally {
            setRunning(false);
            loadStatus(scope);
        }
    };

    const syncStockMaster = async () => {
        setRunning(true);
        try {
            const { data } = await api.post('/universe-price-sync/stock-master');
            showToast(
                `Stock master synced: added ${data.data?.added ?? 0}, updated ${data.data?.updated ?? 0}.`,
                'success',
            );
            await loadStatus(scope);
        } catch (error) {
            showToast(
                error?.response?.data?.message || 'Stock master sync failed.',
                'danger',
            );
        } finally {
            setRunning(false);
        }
    };

    const rateLimits = status?.rate_limits;
    const likelyRateLimited = Boolean(rateLimits?.likely_rate_limited);
    const progressPercent = status?.progress_percent ?? 0;
    const coveragePercent = useMemo(() => {
        const total = status?.universe_count ?? 0;
        const withPrices = status?.stocks_with_prices ?? 0;
        if (total <= 0) {
            return 0;
        }
        return Math.round((withPrices / total) * 1000) / 10;
    }, [status]);

    return (
        <div className="contentPane">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h1 className="h4 mb-1">Universe price sync</h1>
                    <p className="text-muted small mb-0">
                        Bulk OHLCV for NSE equities. Use batches on cPanel — one HTTP request per batch.
                    </p>
                </div>
                <Link to="/settings" className="btn btn-outline-secondary btn-sm">
                    Back to settings
                </Link>
            </div>

            {loadError && (
                <div className="alert alert-danger" role="alert">{loadError}</div>
            )}

            {likelyRateLimited && (
                <div className="alert alert-warning" role="alert">
                    Provider rate limits may be active. Increase delay in
                    {' '}
                    <code>UNIVERSE_PRICE_SYNC_DELAY_MS</code>
                    , use smaller batches, or wait before running again.
                    {' '}
                    Last run rate-limit signals:
                    {' '}
                    {rateLimits?.last_run_hits ?? 0}
                </div>
            )}

            <div className="row g-3 mb-3">
                <div className="col-12 col-lg-4">
                    <div className="card h-100">
                        <div className="card-header">Status</div>
                        <div className="card-body">
                            {loading && !status ? (
                                <p className="text-muted mb-0">Loading…</p>
                            ) : (
                                <dl className="row small mb-0">
                                    <dt className="col-5">Enabled</dt>
                                    <dd className="col-7">
                                        <span className={`badge ${statusBadgeClass(status?.enabled)}`}>
                                            {status?.enabled ? 'yes' : 'no'}
                                        </span>
                                    </dd>
                                    <dt className="col-5">In progress</dt>
                                    <dd className="col-7">
                                        <span className={`badge ${status?.in_progress ? 'bg-info text-dark' : 'bg-secondary'}`}>
                                            {status?.in_progress ? 'yes' : 'no'}
                                        </span>
                                    </dd>
                                    <dt className="col-5">Universe</dt>
                                    <dd className="col-7">{status?.universe_count ?? '—'}</dd>
                                    <dt className="col-5">With prices</dt>
                                    <dd className="col-7">
                                        {status?.stocks_with_prices ?? '—'}
                                        {' '}
                                        (
                                        {coveragePercent}
                                        %)
                                    </dd>
                                    <dt className="col-5">Cursor</dt>
                                    <dd className="col-7">
                                        {status?.cursor_symbol || '—'}
                                        {status?.cursor_stock_id ? ` (#${status.cursor_stock_id})` : ''}
                                    </dd>
                                    <dt className="col-5">Batch progress</dt>
                                    <dd className="col-7">
                                        {progressPercent}
                                        %
                                    </dd>
                                    <dt className="col-5">Last cycle</dt>
                                    <dd className="col-7">{formatTimestamp(status?.last_cycle_completed_at)}</dd>
                                </dl>
                            )}
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-4">
                    <div className="card h-100">
                        <div className="card-header">Last batch</div>
                        <div className="card-body small">
                            {status?.last_run ? (
                                <dl className="row mb-0">
                                    <dt className="col-5">Mode</dt>
                                    <dd className="col-7">{status.last_run.mode}</dd>
                                    <dt className="col-5">Processed</dt>
                                    <dd className="col-7">{status.last_run.processed}</dd>
                                    <dt className="col-5">Failed</dt>
                                    <dd className="col-7">{status.last_run.failed}</dd>
                                    <dt className="col-5">Stored rows</dt>
                                    <dd className="col-7">{status.last_run.stored_rows}</dd>
                                    <dt className="col-5">Rate limits</dt>
                                    <dd className="col-7">{status.last_run.rate_limit_hits ?? 0}</dd>
                                    <dt className="col-5">At</dt>
                                    <dd className="col-7">{formatTimestamp(status.last_run.completed_at)}</dd>
                                </dl>
                            ) : (
                                <p className="text-muted mb-0">No batch run recorded yet.</p>
                            )}
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-4">
                    <div className="card h-100">
                        <div className="card-header">Config (env)</div>
                        <div className="card-body small">
                            <dl className="row mb-0">
                                <dt className="col-6">History days</dt>
                                <dd className="col-6">{status?.config?.history_days ?? '—'}</dd>
                                <dt className="col-6">Daily lookback</dt>
                                <dd className="col-6">{status?.config?.daily_lookback_days ?? '—'}</dd>
                                <dt className="col-6">Delay (ms)</dt>
                                <dd className="col-6">{status?.config?.delay_ms_between_stocks ?? '—'}</dd>
                                <dt className="col-6">Default batch</dt>
                                <dd className="col-6">{status?.config?.batch_size ?? '—'}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div className="card mb-3">
                <div className="card-header">Run controls</div>
                <div className="card-body">
                    <div className="row g-3 align-items-end">
                        <div className="col-12 col-md-3">
                            <label className="form-label" htmlFor="universe-scope">Scope</label>
                            <select
                                id="universe-scope"
                                className="form-select"
                                value={scope}
                                onChange={(e) => setScope(e.target.value)}
                                disabled={running}
                            >
                                {SCOPE_OPTIONS.map((opt) => (
                                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                                ))}
                            </select>
                        </div>
                        <div className="col-12 col-md-2">
                            <label className="form-label" htmlFor="universe-batch">Batch size</label>
                            <input
                                id="universe-batch"
                                type="number"
                                className="form-control"
                                min="1"
                                max="200"
                                placeholder={status?.config?.batch_size ?? '75'}
                                value={batchSize}
                                onChange={(e) => setBatchSize(e.target.value)}
                                disabled={running}
                            />
                        </div>
                        <div className="col-12 col-md-7">
                            <div className="d-flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    className="btn btn-primary btn-sm"
                                    disabled={running || !status?.enabled}
                                    onClick={() => runSync({
                                        mode: 'daily',
                                        batch: batchSize ? Number(batchSize) : undefined,
                                    })}
                                >
                                    {running ? 'Running…' : 'Run daily batch'}
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-outline-primary btn-sm"
                                    disabled={running || !status?.enabled}
                                    onClick={() => runSync({
                                        mode: 'backfill',
                                        batch: batchSize ? Number(batchSize) : undefined,
                                    })}
                                >
                                    Run backfill batch
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-outline-secondary btn-sm"
                                    disabled={running || !status?.enabled}
                                    onClick={() => runSync({
                                        mode: 'backfill',
                                        reset_cursor: true,
                                        batch: batchSize ? Number(batchSize) : undefined,
                                    })}
                                >
                                    Reset cursor + backfill batch
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-outline-warning btn-sm"
                                    disabled={running}
                                    onClick={syncStockMaster}
                                >
                                    Sync stock master
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-outline-secondary btn-sm"
                                    disabled={loading || running}
                                    onClick={() => loadStatus(scope)}
                                >
                                    Refresh status
                                </button>
                            </div>
                        </div>
                    </div>
                    <p className="text-muted small mt-3 mb-2">
                        For initial history on cPanel, click
                        {' '}
                        <strong>Run backfill batch</strong>
                        {' '}
                        repeatedly until batch progress reaches 100% and “Last cycle” updates.
                        Avoid
                        {' '}
                        <em>process all</em>
                        {' '}
                        in one request — it may time out.
                    </p>
                    <div className="form-check">
                        <input
                            id="universe-auto-refresh"
                            type="checkbox"
                            className="form-check-input"
                            checked={autoRefresh}
                            onChange={(e) => setAutoRefresh(e.target.checked)}
                        />
                        <label className="form-check-label small" htmlFor="universe-auto-refresh">
                            Auto-refresh status every 15 seconds
                        </label>
                    </div>
                </div>
            </div>

            {status?.last_run?.errors?.length > 0 && (
                <div className="card mb-3">
                    <div className="card-header">Last batch errors</div>
                    <ul className="list-group list-group-flush small">
                        {status.last_run.errors.map((err) => (
                            <li key={err} className="list-group-item">{err}</li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="card">
                <div className="card-header d-flex justify-content-between align-items-center">
                    <span>Recent provider issues</span>
                    <Link to="/settings/sync-logs" className="btn btn-outline-secondary btn-sm">
                        All sync logs
                    </Link>
                </div>
                <div className="table-responsive">
                    <table className="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Level</th>
                                <th>Symbol</th>
                                <th>Message</th>
                                <th>Rate limit?</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(rateLimits?.recent_issues ?? []).length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="text-muted">No recent warnings or errors.</td>
                                </tr>
                            ) : (
                                rateLimits.recent_issues.map((row) => (
                                    <tr key={`${row.logged_at}-${row.message}-${row.symbol}`}>
                                        <td>{formatTimestamp(row.logged_at)}</td>
                                        <td>{row.level}</td>
                                        <td>{row.symbol || '—'}</td>
                                        <td>{row.message}</td>
                                        <td>{row.likely_rate_limit ? 'yes' : '—'}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
