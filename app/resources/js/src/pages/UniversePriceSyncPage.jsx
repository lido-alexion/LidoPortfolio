import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';

const SCOPE_OPTIONS = [
    { value: 'all_nse', label: 'All NSE equities' },
];
const MAX_BACKFILL_CHAIN_BATCHES = 500;
const MAX_GAP_CHAIN_BATCHES = 500;

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
    const [backfillChainRunning, setBackfillChainRunning] = useState(false);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [loadError, setLoadError] = useState('');
    const [gapStatus, setGapStatus] = useState(null);
    const [gapRunning, setGapRunning] = useState(false);
    const [gapCycleSymbols, setGapCycleSymbols] = useState([]);

    const loadStatus = useCallback(async (activeScope) => {
        setLoadError('');
        try {
            const [statusRes, gapRes] = await Promise.all([
                api.get('/universe-price-sync/status', { params: { scope: activeScope } }),
                api.get('/universe-price-sync/gaps/status', { params: { scope: activeScope } }),
            ]);
            setStatus(statusRes.data.data);
            setGapStatus(gapRes.data.data);
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
        if (!autoRefresh) {
            return undefined;
        }
        const timer = setInterval(() => {
            loadStatus(scope);
        }, 15000);
        return () => clearInterval(timer);
    }, [autoRefresh, loadStatus, scope]);

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

    const runBackfillCycle = async () => {
        setBackfillChainRunning(true);
        const chosenBatchSize = batchSize ? Number(batchSize) : undefined;
        const delayMs = Math.max(1000, Number(status?.config?.delay_ms_between_stocks ?? 400) + 1000);

        try {
            let completed = false;
            let batchNo = 0;

            while (!completed && batchNo < MAX_BACKFILL_CHAIN_BATCHES) {
                batchNo += 1;
                // One API request per batch keeps it cPanel-safe.
                const { data } = await api.post('/universe-price-sync/run', {
                    scope,
                    mode: 'backfill',
                    batch: chosenBatchSize,
                });
                const nextStatus = data.data?.status ?? data.data;
                const run = data.data?.run ?? {};
                setStatus(nextStatus);
                completed = Boolean(run.cycle_completed);

                if (completed) {
                    showToast(`Backfill cycle completed in ${batchNo} batch(es).`, 'success');
                    break;
                }

                if (batchNo % 5 === 0) {
                    showToast(`Backfill chaining: completed ${batchNo} batch(es)…`, 'info');
                }

                await new Promise((resolve) => setTimeout(resolve, delayMs));
            }

            if (!completed) {
                showToast(
                    `Stopped after ${MAX_BACKFILL_CHAIN_BATCHES} batches for safety. Click again to continue.`,
                    'warning',
                );
            }
        } catch (error) {
            const message = error?.response?.data?.message || 'Backfill chaining failed.';
            showToast(message, 'danger');
        } finally {
            setBackfillChainRunning(false);
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

    const runGapAction = async (endpoint, successLabel, extra = {}) => {
        setGapRunning(true);
        try {
            const { data } = await api.post(endpoint, {
                scope,
                batch: batchSize ? Number(batchSize) : undefined,
                ...extra,
            });
            setGapStatus(data.data?.status ?? data.data);
            const run = data.data?.run ?? {};
            showToast(
                `${successLabel}: scanned ${run.scanned ?? 0}, with gaps ${run.with_gaps ?? 0}${
                    run.filled !== undefined ? `, filled ${run.filled}` : ''
                }.`,
                'success',
            );
        } catch (error) {
            showToast(error?.response?.data?.message || 'Gap task failed.', 'danger');
            if (error?.response?.data?.data?.status) {
                setGapStatus(error.response.data.data.status);
            }
        } finally {
            setGapRunning(false);
            loadStatus(scope);
        }
    };

    const runFullGapCycle = async (mode) => {
        setGapRunning(true);
        setGapCycleSymbols([]);
        const chosenBatchSize = batchSize ? Number(batchSize) : undefined;
        // Gap endpoints are throttled; keep a safe interval between requests.
        const delayMs = mode === 'scan' ? 5500 : 6000;
        const endpoint = mode === 'scan'
            ? '/universe-price-sync/gaps/scan'
            : '/universe-price-sync/gaps/fill';
        try {
            let completed = false;
            let batchNo = 0;

            while (!completed && batchNo < MAX_GAP_CHAIN_BATCHES) {
                batchNo += 1;
                const { data } = await api.post(endpoint, {
                    scope,
                    batch: chosenBatchSize,
                });
                setGapStatus(data.data?.status ?? data.data);
                const run = data.data?.run ?? {};
                const batchSymbols = Array.isArray(run.symbols_with_gaps) ? run.symbols_with_gaps : [];
                if (batchSymbols.length > 0) {
                    setGapCycleSymbols((prev) => {
                        const map = new Map(prev.map((row) => [row.symbol, row]));
                        batchSymbols.forEach((row) => {
                            if (row?.symbol) {
                                map.set(row.symbol, row);
                            }
                        });
                        return Array.from(map.values()).slice(0, 100);
                    });
                }
                completed = Boolean(run.cycle_completed);

                if (completed) {
                    showToast(
                        mode === 'scan'
                            ? `Gap scan completed across universe in ${batchNo} batch(es).`
                            : `Gap fill completed across universe in ${batchNo} batch(es).`,
                        'success',
                    );
                    break;
                }

                if (batchNo % 5 === 0) {
                    showToast(
                        mode === 'scan'
                            ? `Gap scan chaining: ${batchNo} batch(es) done…`
                            : `Gap fill chaining: ${batchNo} batch(es) done…`,
                        'info',
                    );
                }

                await new Promise((resolve) => setTimeout(resolve, delayMs));
            }

            if (!completed) {
                showToast(
                    `Stopped after ${MAX_GAP_CHAIN_BATCHES} batches for safety. Click again to continue.`,
                    'warning',
                );
            }
        } catch (error) {
            showToast(
                error?.response?.data?.message || `Gap ${mode} cycle failed.`,
                'danger',
            );
        } finally {
            setGapRunning(false);
            loadStatus(scope);
        }
    };

    const rateLimits = status?.rate_limits;
    const likelyRateLimited = Boolean(rateLimits?.likely_rate_limited);
    const operationalAlerts = status?.operational_alerts?.active ?? [];
    const unacknowledgedAlertCount = status?.operational_alerts?.unacknowledged_count ?? 0;
    const adminTelegramRecipients = status?.operational_alerts?.admin_telegram_recipients ?? 0;

    const acknowledgeAlert = async (alertKey) => {
        try {
            const { data } = await api.post('/operational-alerts/acknowledge', {
                key: alertKey,
            });
            if (data.data) {
                setStatus((prev) => (prev ? {
                    ...prev,
                    operational_alerts: {
                        active: data.data.active ?? [],
                        unacknowledged_count: data.data.unacknowledged_count ?? 0,
                        admin_telegram_recipients: data.data.admin_telegram_recipients ?? 0,
                    },
                } : prev));
            }
            showToast('Alert dismissed until it clears or re-triggers.', 'info');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Could not dismiss alert.', 'danger');
        }
    };

    const alertSeverityClass = (severity) => {
        if (severity === 'critical') {
            return 'alert-danger';
        }
        return 'alert-warning';
    };
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

            {operationalAlerts.length > 0 && (
                <div className="card mb-3 border-warning">
                    <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span>
                            Operational alerts
                            {unacknowledgedAlertCount > 0 && (
                                <span className="badge bg-danger ms-2">{unacknowledgedAlertCount}</span>
                            )}
                        </span>
                        <Link to="/settings/admin-alerts" className="btn btn-sm btn-outline-warning">
                            View all admin alerts
                        </Link>
                        <span className="text-muted small">
                            Telegram recipients (admins):
                            {' '}
                            {adminTelegramRecipients}
                        </span>
                    </div>
                    <div className="card-body p-0">
                        <ul className="list-group list-group-flush">
                            {operationalAlerts.map((alert) => (
                                <li
                                    key={alert.key}
                                    className={`list-group-item ${alert.acknowledged ? 'opacity-75' : ''}`}
                                >
                                    <div className={`alert mb-0 ${alertSeverityClass(alert.severity)}`}>
                                        <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                            <div>
                                                <strong>
                                                    [{alert.severity}]
                                                    {' '}
                                                    {alert.title}
                                                </strong>
                                                <div className="small mt-1">{alert.message}</div>
                                                <div className="text-muted small mt-1">
                                                    Last seen:
                                                    {' '}
                                                    {formatTimestamp(alert.last_triggered_at)}
                                                    {alert.acknowledged && ' · dismissed'}
                                                </div>
                                            </div>
                                            {!alert.acknowledged && (
                                                <button
                                                    type="button"
                                                    className="btn btn-sm btn-outline-secondary"
                                                    onClick={() => acknowledgeAlert(alert.key)}
                                                >
                                                    Dismiss
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}

            {likelyRateLimited && operationalAlerts.every((a) => a.key !== 'provider_rate_limit') && (
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
                                    title="Run one incremental daily sync batch for the selected scope."
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
                                    disabled={running || backfillChainRunning || !status?.enabled}
                                    title="Run one historical backfill batch for the selected scope."
                                    onClick={() => runSync({
                                        mode: 'backfill',
                                        batch: batchSize ? Number(batchSize) : undefined,
                                    })}
                                >
                                    Run backfill batch
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-outline-success btn-sm"
                                    disabled={running || backfillChainRunning || !status?.enabled}
                                    title="Automatically run backfill batches one after another until the cycle completes."
                                    onClick={runBackfillCycle}
                                >
                                    {backfillChainRunning ? 'Running full backfill…' : 'Run full backfill cycle'}
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-outline-secondary btn-sm"
                                    disabled={running || backfillChainRunning || !status?.enabled}
                                    title="Reset universe cursor to the beginning, then run one backfill batch."
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
                                    disabled={running || backfillChainRunning}
                                    title="Refresh stock master symbols from source."
                                    onClick={syncStockMaster}
                                >
                                    Sync stock master
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-outline-secondary btn-sm"
                                    disabled={loading || running || backfillChainRunning}
                                    title="Reload universe and gap status immediately."
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

            <div className="card mb-3">
                <div className="card-header">Price history gap checker</div>
                <div className="card-body">
                    <p className="text-muted small">
                        Detects missing ranges and internal gaps (over
                        {' '}
                        {gapStatus?.max_internal_gap_days ?? 7}
                        {' '}
                        days) in the last
                        {' '}
                        {gapStatus?.history_window_days ?? '—'}
                        {' '}
                        days. Fills gaps via providers for universe stocks and NIFTY50.
                        Scheduled automatically every 30 minutes after market close.
                    </p>
                    <dl className="row small mb-3">
                        <dt className="col-sm-4">NIFTY50 gaps</dt>
                        <dd className="col-sm-8">
                            <span className={`badge ${gapStatus?.benchmark?.has_gaps ? 'bg-warning text-dark' : 'bg-success'}`}>
                                {gapStatus?.benchmark?.has_gaps
                                    ? `${gapStatus.benchmark.gap_count} range(s)`
                                    : 'none'}
                            </span>
                        </dd>
                        <dt className="col-sm-4">Last scan</dt>
                        <dd className="col-sm-8">
                            {gapStatus?.last_scan
                                ? `${gapStatus.last_scan.with_gaps ?? 0} with gaps / ${gapStatus.last_scan.scanned ?? 0} scanned`
                                : '—'}
                        </dd>
                        <dt className="col-sm-4">Last fill</dt>
                        <dd className="col-sm-8">
                            {gapStatus?.last_fill
                                ? `filled ${gapStatus.last_fill.filled ?? 0}, failed ${gapStatus.last_fill.failed ?? 0}, stored ${gapStatus.last_fill.stored_rows ?? 0}`
                                : '—'}
                        </dd>
                        <dt className="col-sm-4">Gap cursor</dt>
                        <dd className="col-sm-8">
                            {gapStatus?.cursor_symbol || '—'}
                            {gapStatus?.cursor_stock_id ? ` (#${gapStatus.cursor_stock_id})` : ''}
                            {' · '}
                            {gapStatus?.progress_percent ?? 0}
                            %
                        </dd>
                    </dl>
                    <div className="d-flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="btn btn-outline-secondary btn-sm"
                            disabled={running || gapRunning || !status?.enabled}
                            title="Scan all universe batches for gaps (no provider fetch), in one chained run."
                            onClick={() => runFullGapCycle('scan')}
                        >
                            {gapRunning ? 'Running…' : 'Scan all gaps'}
                        </button>
                        <button
                            type="button"
                            className="btn btn-outline-primary btn-sm"
                            disabled={running || gapRunning || !status?.enabled}
                            title="Scan and fill gaps across all universe batches in one chained run."
                            onClick={() => runFullGapCycle('fill')}
                        >
                            {gapRunning ? 'Running…' : 'Fill all gaps'}
                        </button>
                    </div>
                    {(gapRunning ? gapCycleSymbols.length > 0 : gapStatus?.last_scan?.symbols_with_gaps?.length > 0) && (
                        <ul className="small text-muted mt-3 mb-0">
                            {(gapRunning ? gapCycleSymbols : gapStatus.last_scan.symbols_with_gaps).slice(0, 12).map((row) => (
                                <li key={row.symbol}>
                                    {row.symbol}
                                    {' '}
                                    (
                                    {row.gap_count}
                                    {' '}
                                    range
                                    {row.gap_count === 1 ? '' : 's'}
                                    )
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>

            <div className="card mb-3">
                <div className="card-header">How this works</div>
                <div className="card-body small">
                    <ul className="mb-0">
                        <li>
                            <strong>Stock master sync (weekly)</strong>: refreshes NSE symbol master, adds/updates rows,
                            deactivates symbols missing from the latest source, and immediately backfills price history for
                            newly added NSE symbols (gap-aware, full history window).
                        </li>
                        <li>
                            <strong>Universe daily batch</strong>: checks recent window first, then fetches only missing ranges.
                        </li>
                        <li>
                            <strong>Backfill batch</strong>: checks full history window first, then fetches only gaps.
                        </li>
                        <li>
                            <strong>Newly added symbols</strong>: backfilled right after stock master sync (manual or weekly).
                            Universe daily/backfill still covers anything missed or added outside that path.
                        </li>
                        <li>
                            Existing OHLCV rows are upserted by <code>stock_id + price_date</code> (no duplicates).
                        </li>
                    </ul>
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
