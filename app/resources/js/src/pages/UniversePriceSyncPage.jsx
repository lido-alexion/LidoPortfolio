import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import FieldHint from '../components/FieldHint';
import { showToast } from '../toast';
import { formatSchedulerTimestamp } from '../utils/schedulerTimestamp';
import { expandGapScanRows, gapRowKey } from '../utils/gapReportUtils';

const SCOPE_OPTIONS = [
    { value: 'all_equities', label: 'All equities (NSE + BSE-only)' },
];
const MAX_BACKFILL_CHAIN_BATCHES = 500;
const MAX_GAP_FILL_CHAIN_BATCHES = 500;

function formatUniverseTimestamp(value, timezone = 'Asia/Kolkata') {
    return formatSchedulerTimestamp(value, timezone);
}

function statusBadgeClass(ok) {
    return ok ? 'bg-success' : 'bg-warning text-dark';
}

function StatusRow({ termId, label, hint, children }) {
    return (
        <>
            <dt className="col-5">
                {hint ? (
                    <FieldHint id={`universe-status-${termId}`} text={hint}>
                        {label}
                    </FieldHint>
                ) : (
                    label
                )}
            </dt>
            <dd className="col-7">{children}</dd>
        </>
    );
}

export default function UniversePriceSyncPage() {
    const [status, setStatus] = useState(null);
    const [scope, setScope] = useState('all_equities');
    const [batchSize, setBatchSize] = useState('');
    const [loading, setLoading] = useState(true);
    const [running, setRunning] = useState(false);
    const [backfillChainRunning, setBackfillChainRunning] = useState(false);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [loadError, setLoadError] = useState('');
    const [gapStatus, setGapStatus] = useState(null);
    const [gapPending, setGapPending] = useState(false);
    const [gapActiveMode, setGapActiveMode] = useState(null);
    const [ignoringGapKey, setIgnoringGapKey] = useState(null);
    const [localIgnoredKeys, setLocalIgnoredKeys] = useState([]);
    const [indexStatus, setIndexStatus] = useState(null);
    const [indexRunning, setIndexRunning] = useState(false);
    const [indexBatchSize, setIndexBatchSize] = useState('');

    const loadStatus = useCallback(async (activeScope) => {
        setLoadError('');
        try {
            const [statusRes, gapRes, indexRes] = await Promise.all([
                api.get('/universe-price-sync/status', { params: { scope: activeScope } }),
                api.get('/universe-price-sync/gaps/status', { params: { scope: activeScope } }),
                api.get('/universe-price-sync/indexes/status'),
            ]);
            setStatus(statusRes.data.data);
            setGapStatus(gapRes.data.data);
            setIndexStatus(indexRes.data.data);
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
        if (!autoRefresh && !gapPending && !gapStatus?.in_progress && !indexStatus?.in_progress) {
            return undefined;
        }
        const intervalMs = (gapPending || gapStatus?.in_progress || indexStatus?.in_progress) ? 3000 : 15000;
        const timer = setInterval(() => {
            loadStatus(scope);
        }, intervalMs);
        return () => clearInterval(timer);
    }, [autoRefresh, gapPending, gapStatus?.in_progress, indexStatus?.in_progress, loadStatus, scope]);

    const runIndexSync = async (payload) => {
        setIndexRunning(true);
        try {
            const { data } = await api.post('/universe-price-sync/indexes/run', payload);
            setIndexStatus(data.data?.status ?? data.data);
            const run = data.data?.run ?? {};
            const detail = run.cycle_completed
                ? 'Full index cycle completed.'
                : `Processed ${run.processed ?? (run.success ? 1 : 0)} index(es).`;
            showToast(`Index sync: ${detail}`, 'success');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Index sync failed.', 'danger');
            if (error?.response?.data?.data?.status) {
                setIndexStatus(error.response.data.data.status);
            } else if (error?.response?.data?.data) {
                setIndexStatus(error.response.data.data);
            }
        } finally {
            setIndexRunning(false);
            loadStatus(scope);
        }
    };

    const fillIndexGaps = async () => {
        setIndexRunning(true);
        try {
            const { data } = await api.post('/universe-price-sync/indexes/fill-gaps', {
                batch: indexBatchSize ? Number(indexBatchSize) : undefined,
            });
            setIndexStatus(data.data?.status ?? data.data);
            const run = data.data?.run ?? {};
            showToast(
                `Index gap fill: processed ${run.processed ?? 0}, stored ${run.stored_rows ?? 0}.`,
                'success',
            );
        } catch (error) {
            showToast(error?.response?.data?.message || 'Index gap fill failed.', 'danger');
        } finally {
            setIndexRunning(false);
            loadStatus(scope);
        }
    };

    const resetIndexCursor = async () => {
        setIndexRunning(true);
        try {
            const { data } = await api.post('/universe-price-sync/indexes/reset-cursor');
            setIndexStatus(data.data);
            showToast('Index sync cursor reset.', 'success');
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to reset index cursor.', 'danger');
        } finally {
            setIndexRunning(false);
        }
    };

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
            const stats = data.data ?? {};
            const backfillNote = stats.backfill_skipped
                ? ' Price backfill skipped — use universe backfill for new symbols.'
                : '';
            showToast(
                `Stock master synced: added ${stats.added ?? 0}, updated ${stats.updated ?? 0}.${backfillNote}`,
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

    const runGapAll = async (mode) => {
        setGapPending(true);
        setGapActiveMode(mode);
        const endpoint = mode === 'scan'
            ? '/universe-price-sync/gaps/scan'
            : '/universe-price-sync/gaps/fill';
        let stoppedForServerLock = false;
        try {
            if (mode === 'scan') {
                const { data } = await api.post(endpoint, {
                    scope,
                    all: true,
                }, { skipErrorToast: true });
                setGapStatus(data.data?.status ?? data.data);
                const run = data.data?.run ?? {};
                if ((run.skipped ?? 0) > 0 && run.reason === 'in_progress') {
                    showToast('Gap task already running on the server.', 'info');
                    stoppedForServerLock = true;
                    return;
                }
                showToast(
                    `Gap scan completed: ${run.with_gaps ?? 0} with gaps / ${run.scanned ?? 0} scanned.`,
                    'success',
                );
            } else {
                let completed = false;
                let attempts = 0;
                const hasFreshScan = Boolean(gapStatus?.last_scan?.scan_completed);

                while (!completed && attempts < MAX_GAP_FILL_CHAIN_BATCHES) {
                    attempts += 1;
                    const { data } = await api.post(endpoint, {
                        scope,
                        all: true,
                        rescan_first: attempts === 1 && !hasFreshScan,
                    }, { skipErrorToast: true });
                    setGapStatus(data.data?.status ?? data.data);
                    const run = data.data?.run ?? {};

                    if ((run.skipped ?? 0) > 0 && run.reason === 'in_progress') {
                        showToast('Gap task already running on the server.', 'info');
                        stoppedForServerLock = true;
                        break;
                    }

                    completed = Boolean(run.completed);
                    if (completed) {
                        const stillGapped = run.still_with_gaps;
                        const failed = run.failed ?? 0;
                        if (stillGapped != null && stillGapped > 0) {
                            showToast(
                                `Gap fill finished: ${stillGapped} symbol(s) still have gaps. `
                                + 'See the failure report below for date ranges and providers tried.',
                                'warning',
                            );
                        } else if (failed > 0) {
                            showToast(
                                `Gap fill completed with ${failed} unresolved symbol(s) in the last chunk.`,
                                'warning',
                            );
                        } else {
                            showToast(
                                `Gap fill completed: ${run.filled ?? 0} resolved in last chunk, `
                                + `${run.stored_rows ?? 0} rows stored.`,
                                'success',
                            );
                        }
                        break;
                    }

                    await new Promise((resolve) => setTimeout(resolve, 800));
                }

                if (!completed && !stoppedForServerLock) {
                    showToast(
                        'Gap fill paused before completion. Click Fill all gaps again to resume from where it stopped.',
                        'warning',
                    );
                }
            }
        } catch (error) {
            if (error?.response?.status === 409) {
                showToast(
                    error?.response?.data?.message || 'Gap task already running on the server.',
                    'info',
                );
                if (error?.response?.data?.data?.status) {
                    setGapStatus(error.response.data.data.status);
                }
            } else {
                const fallback = mode === 'fill'
                    ? 'Gap fill request timed out. Click Fill all gaps again to continue from saved progress.'
                    : 'Gap task failed.';
                showToast(error?.response?.data?.message || fallback, 'danger');
                if (error?.response?.data?.data?.status) {
                    setGapStatus(error.response.data.data.status);
                }
            }
        } finally {
            setGapPending(false);
            setGapActiveMode(null);
            loadStatus(scope);
        }
    };

    const ignoreGapRow = async (row) => {
        if (!row.stock_id || !row.gap_start || !row.gap_end) {
            return;
        }
        const key = gapRowKey(row.stock_id, row.gap_start, row.gap_end);
        setIgnoringGapKey(key);
        try {
            await api.post('/universe-price-sync/gaps/ignore', {
                stock_id: row.stock_id,
                gap_from: row.gap_start,
                gap_to: row.gap_end,
            }, { skipErrorToast: true });
            setLocalIgnoredKeys((current) => (current.includes(key) ? current : [...current, key]));
            showToast(`${row.stock} gap ignored.`, 'success');
            await loadStatus(scope);
        } catch (error) {
            showToast(error?.response?.data?.message || 'Failed to ignore gap.', 'danger');
        } finally {
            setIgnoringGapKey(null);
        }
    };

    const clearGapReports = async () => {
        if (!window.confirm('Clear gap scan results and the last fill failure report?')) {
            return;
        }

        setGapPending(true);
        try {
            const { data } = await api.post('/universe-price-sync/gaps/clear', { scope }, { skipErrorToast: true });
            setGapStatus(data.data?.status ?? data.data);
            showToast('Gap scan and fill reports cleared.', 'success');
        } catch (error) {
            showToast(
                error?.response?.data?.message || 'Could not clear gap reports.',
                'danger',
            );
            if (error?.response?.data?.data?.status) {
                setGapStatus(error.response.data.data.status);
            }
        } finally {
            setGapPending(false);
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
    const processedThrough = status?.processed_through ?? 0;
    const remainingInCycle = status?.remaining_in_cycle ?? 0;
    const universeCount = status?.universe_count ?? 0;
    const cronTimezone = status?.maintenance?.timezone ?? 'Asia/Kolkata';
    const formatStatusTime = useCallback(
        (value) => formatUniverseTimestamp(value, cronTimezone),
        [cronTimezone],
    );
    const coveragePercent = useMemo(() => {
        const total = status?.universe_count ?? 0;
        const withPrices = status?.stocks_with_prices ?? 0;
        if (total <= 0) {
            return 0;
        }
        return Math.round((withPrices / total) * 1000) / 10;
    }, [status]);
    const cycleStatusLabel = useMemo(() => {
        if (!status || universeCount <= 0) {
            return '—';
        }
        if (remainingInCycle <= 0 && (status.cursor_stock_id ?? 0) === 0) {
            return status.last_run?.cycle_completed
                ? 'At universe start — last batch finished a full cycle'
                : 'At universe start — cycle not started or just reset';
        }

        return `In progress — ${remainingInCycle.toLocaleString()} of ${universeCount.toLocaleString()} stocks left`;
    }, [remainingInCycle, status, universeCount]);
    const gapRunning = Boolean(gapPending || gapStatus?.in_progress);
    const gapProgressMode = gapStatus?.in_progress_mode || gapActiveMode;
    const gapFillProgress = gapStatus?.fill_progress;
    const gapScanProgress = gapStatus?.scan_progress;
    const gapSymbols = gapStatus?.last_scan?.scan_completed
        ? (gapStatus.last_scan.symbols_with_gaps ?? [])
        : [];
    const ignoredGapKeys = useMemo(() => {
        const keys = new Set(gapStatus?.ignored_gap_keys ?? []);
        localIgnoredKeys.forEach((key) => keys.add(key));
        return keys;
    }, [gapStatus?.ignored_gap_keys, localIgnoredKeys]);
    const gapScanRows = useMemo(
        () => expandGapScanRows(gapSymbols, ignoredGapKeys),
        [gapSymbols, ignoredGapKeys],
    );
    const hasGapReportData = Boolean(
        gapStatus?.last_scan?.scan_completed
        || (gapStatus?.inventory_stock_count ?? 0) > 0,
    );
    const lastScanLabel = useMemo(() => {
        if (!gapStatus?.last_scan) {
            return '—';
        }
        const scanned = gapStatus.last_scan.scanned ?? 0;
        const total = gapStatus.last_scan.universe_count ?? gapStatus.universe_count ?? scanned;
        const withGaps = gapStatus.last_scan.with_gaps ?? 0;
        if (gapStatus.last_scan.scan_completed) {
            return `${withGaps} with gaps / ${scanned} scanned (full universe)`;
        }
        return `${withGaps} with gaps / ${scanned} scanned (partial — run Scan all gaps)`;
    }, [gapStatus]);

    return (
        <div className="contentPane">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h1 className="h4 mb-1">Universe price sync</h1>
                    <p className="text-muted small mb-0">
                        Bulk OHLCV for NSE equities. Use batches on cPanel — one HTTP request per batch.
                    </p>
                </div>
                <Link to="/settings/global" className="btn btn-outline-secondary btn-sm">
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
                                                    {formatStatusTime(alert.last_triggered_at)}
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
                        <div className="card-header">Universe cycle</div>
                        <div className="card-body">
                            {loading && !status ? (
                                <p className="text-muted mb-0">Loading…</p>
                            ) : (
                                <>
                                    <p className="text-muted small mb-2">
                                        Tracks the rolling cursor pass over all universe stocks.
                                        {' '}
                                        <strong>Last batch completed</strong>
                                        {' '}
                                        (below) is the latest activity time — not the same as
                                        {' '}
                                        <strong>Last full cycle completed</strong>.
                                    </p>
                                    <dl className="row small mb-0">
                                        <StatusRow
                                            termId="enabled"
                                            label="Enabled"
                                            hint="Whether universe price sync is turned on (UNIVERSE_PRICE_SYNC_ENABLED)."
                                        >
                                            <span className={`badge ${statusBadgeClass(status?.enabled)}`}>
                                                {status?.enabled ? 'yes' : 'no'}
                                            </span>
                                        </StatusRow>
                                        <StatusRow
                                            termId="in-progress"
                                            label="Batch running"
                                            hint="Yes while a single batch HTTP request is active on the server (usually under a few minutes). Nightly maintenance runs many short batches across the evening window."
                                        >
                                            <span className={`badge ${status?.in_progress ? 'bg-info text-dark' : 'bg-secondary'}`}>
                                                {status?.in_progress ? 'yes' : 'no'}
                                            </span>
                                        </StatusRow>
                                        <StatusRow
                                            termId="cycle-status"
                                            label="Cycle status"
                                            hint="Whether tonight's cursor pass has finished the full universe. The cursor does not reset at 19:00 — it resumes the next evening until every stock has been processed once."
                                        >
                                            {cycleStatusLabel}
                                        </StatusRow>
                                        <StatusRow
                                            termId="universe"
                                            label="Universe size"
                                            hint="Total active stocks in the selected scope (NSE + BSE-only, ISIN deduped)."
                                        >
                                            {universeCount > 0 ? universeCount.toLocaleString() : '—'}
                                        </StatusRow>
                                        <StatusRow
                                            termId="with-prices"
                                            label="With OHLCV cached"
                                            hint="Stocks that have at least one row in portfolio_stock_prices. Coverage can lag until the first backfill or cursor pass reaches each symbol."
                                        >
                                            {status?.stocks_with_prices ?? '—'}
                                            {universeCount > 0 ? ` (${coveragePercent}%)` : ''}
                                        </StatusRow>
                                        <StatusRow
                                            termId="cursor"
                                            label="Cycle cursor"
                                            hint="Last stock ID processed in the most recent batch. The next nightly batch continues with stocks after this ID in universe order. Not the same as 'stock 4968 of 6971' — IDs are database keys."
                                        >
                                            {status?.cursor_symbol || '—'}
                                            {status?.cursor_stock_id ? ` (#${status.cursor_stock_id})` : ''}
                                        </StatusRow>
                                        <StatusRow
                                            termId="cycle-progress"
                                            label="Cycle progress"
                                            hint="Share of the universe reached in the current cursor pass (stocks with ID ≤ cycle cursor ÷ universe size). This is not progress within one 125-stock batch."
                                        >
                                            {universeCount > 0
                                                ? `${progressPercent}% (${processedThrough.toLocaleString()} / ${universeCount.toLocaleString()})`
                                                : '—'}
                                        </StatusRow>
                                        <StatusRow
                                            termId="remaining"
                                            label="Remaining in cycle"
                                            hint="How many universe stocks are still ahead of the cursor before this pass completes and the cursor returns to the start."
                                        >
                                            {universeCount > 0 ? remainingInCycle.toLocaleString() : '—'}
                                        </StatusRow>
                                        <StatusRow
                                            termId="last-batch"
                                            label="Last batch completed"
                                            hint="When the most recent universe sync batch finished. Use this (or Sync logs) to see whether nightly maintenance is still running — e.g. 23:45 is the last slot in the window."
                                        >
                                            {formatStatusTime(status?.last_run?.completed_at)}
                                        </StatusRow>
                                        <StatusRow
                                            termId="last-cycle"
                                            label="Last full cycle completed"
                                            hint="When a batch last processed through the highest-ID stock and reset the cursor to the start. Can be hours or days earlier than 'Last batch completed' while a new pass is still in progress."
                                        >
                                            {formatStatusTime(status?.last_cycle_completed_at)}
                                        </StatusRow>
                                        <StatusRow
                                            termId="nightly-window"
                                            label="Nightly window"
                                            hint="Automated maintenance runs on minutes divisible by the interval (default every 5 min) between these times in cron_timezone."
                                        >
                                            {status?.maintenance?.window_label ?? '—'}
                                        </StatusRow>
                                        <StatusRow
                                            termId="nightly-capacity"
                                            label="Nightly capacity"
                                            hint="Approximate stocks processed per evening if every slot runs (runs per night × batch size). If universe size exceeds this, one full cursor cycle spans multiple nights."
                                        >
                                            {status?.maintenance
                                                ? `${status.maintenance.nightly_stock_capacity.toLocaleString()} stocks (${status.maintenance.runs_per_night} runs × ${status.maintenance.batch_size})`
                                                : '—'}
                                        </StatusRow>
                                    </dl>
                                </>
                            )}
                            {status?.maintenance
                                && status?.universe_count > status.maintenance.nightly_stock_capacity ? (
                                <p className="alert alert-warning py-2 small mb-0 mt-2">
                                    Universe has
                                    {' '}
                                    {status.universe_count}
                                    {' '}
                                    stocks but nightly maintenance can process about
                                    {' '}
                                    {status.maintenance.nightly_stock_capacity}
                                    .
                                    {' '}
                                    A full cursor cycle needs ~
                                    {status.maintenance.nights_for_full_cycle}
                                    {' '}
                                    night(s). Increase
                                    {' '}
                                    <code>UNIVERSE_PRICE_SYNC_BATCH_SIZE</code>
                                    {' '}
                                    or
                                    {' '}
                                    <code>UNIVERSE_MAINTENANCE_INTERVAL_MINUTES</code>
                                    {' '}
                                    in production
                                    {' '}
                                    <code>.env</code>
                                    {' '}
                                    if prices fall behind.
                                </p>
                            ) : null}
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-4">
                    <div className="card h-100">
                        <div className="card-header">Last batch details</div>
                        <div className="card-body small">
                            {status?.last_run ? (
                                <>
                                    <p className="text-muted mb-2">
                                        Metrics from the most recent completed batch. See
                                        {' '}
                                        <strong>Completed at</strong>
                                        {' '}
                                        for the latest run time.
                                    </p>
                                    <dl className="row mb-0">
                                        <StatusRow
                                            termId="batch-mode"
                                            label="Mode"
                                            hint="daily = incremental lookback (recent days). backfill = full history window for initial cache build."
                                        >
                                            {status.last_run.mode}
                                        </StatusRow>
                                        <StatusRow
                                            termId="batch-processed"
                                            label="Stocks in batch"
                                            hint="How many symbols were attempted in that single HTTP request (up to the configured batch size)."
                                        >
                                            {status.last_run.processed}
                                        </StatusRow>
                                        <StatusRow
                                            termId="batch-failed"
                                            label="Failed"
                                            hint="Symbols in the batch where the provider returned no usable OHLCV."
                                        >
                                            {status.last_run.failed}
                                        </StatusRow>
                                        <StatusRow
                                            termId="batch-stored"
                                            label="Stored rows"
                                            hint="New or updated price rows written to portfolio_stock_prices in that batch."
                                        >
                                            {status.last_run.stored_rows}
                                        </StatusRow>
                                        <StatusRow
                                            termId="batch-cache"
                                            label="Cache hits"
                                            hint="Stocks that already had sufficient cached history so no provider fetch was needed."
                                        >
                                            {status.last_run.cache_hits ?? 0}
                                        </StatusRow>
                                        <StatusRow
                                            termId="batch-rate"
                                            label="Rate limits"
                                            hint="Failures that look like provider throttling (HTTP 403/429, etc.)."
                                        >
                                            {status.last_run.rate_limit_hits ?? 0}
                                        </StatusRow>
                                        <StatusRow
                                            termId="batch-cycle-flag"
                                            label="Finished full cycle"
                                            hint="Yes only if this batch processed through the last stock in the universe and reset the cursor to the start. Most nightly batches answer No while the cursor is mid-pass."
                                        >
                                            {status.last_run.cycle_completed ? 'yes' : 'no'}
                                        </StatusRow>
                                        <StatusRow
                                            termId="batch-cursor-after"
                                            label="Cursor after batch"
                                            hint="Stock ID saved as the cursor when this batch finished — matches Universe cycle → Cycle cursor unless another batch ran since."
                                        >
                                            {status.last_run.cursor_stock_id ?? status.cursor_stock_id ?? '—'}
                                        </StatusRow>
                                        <StatusRow
                                            termId="batch-completed"
                                            label="Completed at"
                                            hint="End time of the most recent universe-price-sync batch. This is the best 'last activity' timestamp."
                                        >
                                            {formatStatusTime(status.last_run.completed_at)}
                                        </StatusRow>
                                        {status.latest_sync_run?.started_at ? (
                                            <StatusRow
                                                termId="batch-sync-log"
                                                label="Sync log entry"
                                                hint="Matching row in portfolio_sync_runs / Settings → Sync logs for audit."
                                            >
                                                {formatStatusTime(status.latest_sync_run.finished_at || status.latest_sync_run.started_at)}
                                                {status.latest_sync_run.status ? ` (${status.latest_sync_run.status})` : ''}
                                            </StatusRow>
                                        ) : null}
                                    </dl>
                                </>
                            ) : (
                                <p className="text-muted mb-0">No batch run recorded yet.</p>
                            )}
                        </div>
                    </div>
                </div>

                <div className="col-12 col-lg-4">
                    <div className="card h-100">
                        <div className="card-header">Configuration</div>
                        <div className="card-body small">
                            <p className="text-muted mb-2">
                                Production values from env / config. Nightly maintenance uses batch size and interval below.
                            </p>
                            <dl className="row mb-0">
                                <StatusRow
                                    termId="cfg-batch"
                                    label="Batch size"
                                    hint="Stocks per HTTP request (UNIVERSE_PRICE_SYNC_BATCH_SIZE). Nightly job runs one batch per scheduled slot."
                                >
                                    {status?.config?.batch_size ?? '—'}
                                </StatusRow>
                                <StatusRow
                                    termId="cfg-interval"
                                    label="Maint. interval"
                                    hint="Minutes between automated maintenance runs during the nightly window (UNIVERSE_MAINTENANCE_INTERVAL_MINUTES)."
                                >
                                    {status?.config?.maintenance_interval_minutes
                                        ?? status?.maintenance?.interval_minutes
                                        ?? '—'}
                                    {' '}
                                    min
                                </StatusRow>
                                <StatusRow
                                    termId="cfg-history"
                                    label="Backfill history"
                                    hint="Days of OHLCV fetched in backfill mode (UNIVERSE_PRICE_SYNC_HISTORY_DAYS)."
                                >
                                    {status?.config?.history_days ?? '—'}
                                    {' '}
                                    days
                                </StatusRow>
                                <StatusRow
                                    termId="cfg-lookback"
                                    label="Daily lookback"
                                    hint="Days checked for gaps in nightly daily mode (UNIVERSE_PRICE_SYNC_DAILY_LOOKBACK_DAYS)."
                                >
                                    {status?.config?.daily_lookback_days ?? '—'}
                                    {' '}
                                    days
                                </StatusRow>
                                <StatusRow
                                    termId="cfg-delay"
                                    label="Delay between stocks"
                                    hint="Pause between provider calls within a batch to reduce rate limits (UNIVERSE_PRICE_SYNC_DELAY_MS)."
                                >
                                    {status?.config?.delay_ms_between_stocks ?? '—'}
                                    {' '}
                                    ms
                                </StatusRow>
                                {status?.maintenance?.nights_for_full_cycle > 1 ? (
                                    <StatusRow
                                        termId="cfg-nights"
                                        label="Est. nights per cycle"
                                        hint="Ceiling of universe size ÷ nightly capacity. Cursor continues across nights until the pass completes."
                                    >
                                        ~
                                        {status.maintenance.nights_for_full_cycle}
                                        {' '}
                                        night(s)
                                    </StatusRow>
                                ) : null}
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
                <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span>Market indexes (OHLCV)</span>
                    <span className={`badge ${indexStatus?.enabled ? 'bg-success' : 'bg-secondary'}`}>
                        {indexStatus?.enabled ? 'enabled' : 'disabled'}
                    </span>
                </div>
                <div className="card-body">
                    <p className="text-muted small">
                        Downloads daily prices for configured indexes (Nifty family, sectors, India VIX, Sensex, BSE 100/200/500).
                        Primary for relative strength remains
                        {' '}
                        <strong>{indexStatus?.primary_symbol ?? 'NIFTY50'}</strong>
                        .
                        Nightly maintenance runs one small index batch per tick; use backfill here or
                        {' '}
                        <code>cpanel-backfill-index-prices.php</code>
                        {' '}
                        for the initial ~1 year history.
                    </p>
                    <div className="row g-3 mb-3">
                        <div className="col-md-4">
                            <dl className="row mb-0 small">
                                <StatusRow termId="idx-count" label="Indexes" hint="Enabled symbols in portfolio.indexes.definitions.">
                                    {indexStatus?.index_count ?? '—'}
                                </StatusRow>
                                <StatusRow termId="idx-cursor" label="Cursor" hint="Last completed index symbol in the batch cursor.">
                                    {indexStatus?.cursor_symbol || '—'}
                                </StatusRow>
                                <StatusRow termId="idx-progress" label="Cycle progress" hint="How far the cursor has walked through the catalog.">
                                    {indexStatus?.processed_through ?? 0}
                                    /
                                    {indexStatus?.index_count ?? 0}
                                    {' '}
                                    (
                                    {indexStatus?.progress_percent ?? 0}
                                    %)
                                </StatusRow>
                                <StatusRow termId="idx-cycle" label="Last full cycle" hint="When the last index in the catalog finished a pass.">
                                    {formatStatusTime(indexStatus?.last_cycle_completed_at)}
                                </StatusRow>
                                <StatusRow termId="idx-batch" label="Batch size" hint="Indexes per request (INDEX_PRICE_SYNC_BATCH_SIZE).">
                                    {indexStatus?.batch_size ?? 3}
                                </StatusRow>
                            </dl>
                        </div>
                        <div className="col-md-8">
                            <div className="row g-2 align-items-end mb-2">
                                <div className="col-auto">
                                    <label className="form-label small mb-0" htmlFor="index-batch">Batch</label>
                                    <input
                                        id="index-batch"
                                        type="number"
                                        className="form-control form-control-sm"
                                        style={{ width: '5rem' }}
                                        min="1"
                                        max="20"
                                        placeholder={String(indexStatus?.batch_size ?? 3)}
                                        value={indexBatchSize}
                                        onChange={(e) => setIndexBatchSize(e.target.value)}
                                        disabled={indexRunning}
                                    />
                                </div>
                                <div className="col d-flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        className="btn btn-primary btn-sm"
                                        disabled={indexRunning || !indexStatus?.enabled}
                                        onClick={() => runIndexSync({
                                            mode: 'daily',
                                            batch: indexBatchSize ? Number(indexBatchSize) : undefined,
                                        })}
                                    >
                                        {indexRunning ? 'Running…' : 'Daily batch'}
                                    </button>
                                    <button
                                        type="button"
                                        className="btn btn-outline-primary btn-sm"
                                        disabled={indexRunning || !indexStatus?.enabled}
                                        onClick={() => runIndexSync({
                                            mode: 'backfill',
                                            batch: indexBatchSize ? Number(indexBatchSize) : undefined,
                                        })}
                                    >
                                        Backfill batch
                                    </button>
                                    <button
                                        type="button"
                                        className="btn btn-outline-success btn-sm"
                                        disabled={indexRunning || !indexStatus?.enabled}
                                        onClick={() => runIndexSync({
                                            mode: 'backfill',
                                            process_all: true,
                                            reset_cursor: true,
                                        })}
                                    >
                                        Full backfill (all)
                                    </button>
                                    <button
                                        type="button"
                                        className="btn btn-outline-warning btn-sm"
                                        disabled={indexRunning || !indexStatus?.enabled}
                                        onClick={fillIndexGaps}
                                    >
                                        Fill index gaps
                                    </button>
                                    <button
                                        type="button"
                                        className="btn btn-outline-secondary btn-sm"
                                        disabled={indexRunning}
                                        onClick={resetIndexCursor}
                                    >
                                        Reset cursor
                                    </button>
                                </div>
                            </div>
                            {indexStatus?.in_progress ? (
                                <div className="alert alert-info py-2 small mb-2" role="status">
                                    Index sync in progress
                                    {indexStatus.in_progress_at ? ` (since ${formatStatusTime(indexStatus.in_progress_at)})` : ''}.
                                </div>
                            ) : null}
                        </div>
                    </div>
                    <div className="table-responsive" style={{ maxHeight: '22rem' }}>
                        <table className="table table-sm table-striped align-middle mb-0">
                            <thead className="table-light sticky-top">
                                <tr>
                                    <th>Symbol</th>
                                    <th>Exch</th>
                                    <th>Rows</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Gaps</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(indexStatus?.indexes ?? []).map((row) => (
                                    <tr key={row.symbol}>
                                        <td>
                                            <span className="fw-semibold">{row.symbol}</span>
                                            <div className="text-muted small">{row.name}</div>
                                        </td>
                                        <td>{row.exchange}</td>
                                        <td>{row.row_count}</td>
                                        <td className="small">{row.price_from || '—'}</td>
                                        <td className="small">{row.price_to || '—'}</td>
                                        <td>
                                            <span className={`badge ${row.has_gaps ? 'bg-warning text-dark' : 'bg-success'}`}>
                                                {row.has_gaps ? row.gap_count : 'ok'}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                                {(indexStatus?.indexes ?? []).length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="text-muted small">No index status loaded yet.</td>
                                    </tr>
                                ) : null}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div className="card mb-3">
                <div className="card-header">Price history gap checker</div>
                <div className="card-body">
                    <p className="text-muted small">
                        Scans cached OHLCV through the last completed equity session (
                        {gapStatus?.required_through_session ?? 'prior weekday'}
                        ) — today&apos;s bar is not required until after nightly sync.
                        Flags missing edge ranges in the last
                        {' '}
                        {gapStatus?.history_window_days ?? '—'}
                        {' '}
                        days and internal holes longer than
                        {' '}
                        {gapStatus?.max_internal_gap_days ?? 7}
                        {' '}
                        calendar days between stored prices (including missing history at the start or end of the window).
                        Fills gaps via providers for universe stocks and NIFTY50.
                        {' '}
                        <strong>Scan all gaps</strong>
                        {' '}
                        runs one fast DB-only pass over the entire universe and lists every symbol with gaps.
                        {' '}
                        <strong>Fill all gaps</strong>
                        {' '}
                        uses the latest scan inventory and fills in small server chunks (default 15 stocks/request) so cPanel HTTP limits are not hit. If interrupted, click again to resume.
                        {' '}
                        <strong>Automated:</strong>
                        {' '}
                        nightly maintenance still runs one cursor-based gap-fill batch per tick (
                        {status?.maintenance?.interval_minutes ?? 5}
                        {' '}
                        min,
                        {' '}
                        {status?.maintenance?.window_label ?? '19:00–23:45'}
                        ).
                    </p>
                    {gapRunning && (
                        <div className="alert alert-info py-2 small mb-2" role="status" aria-live="polite">
                            {gapProgressMode === 'fill' ? 'Gap fill' : 'Gap scan'}
                            {' '}
                            in progress
                            {gapProgressMode === 'fill' && gapFillProgress?.progress_percent != null
                                ? ` — ${gapFillProgress.progress_percent}% filled (${gapFillProgress.processed_total ?? 0}/${gapFillProgress.total_gap_stocks ?? 0})`
                                : gapProgressMode === 'scan' && gapScanProgress?.progress_percent != null
                                    ? ` — ${gapScanProgress.progress_percent}% scanned`
                                    : gapProgressMode === 'fill' && gapStatus?.inventory_stock_count > 0
                                        ? ` — preparing next chunk…`
                                        : ''}
                            …
                        </div>
                    )}
                    <dl className="row small mb-3">
                        <dt className="col-sm-4">NIFTY50 gaps</dt>
                        <dd className="col-sm-8">
                            <span className={`badge ${gapStatus?.benchmark?.has_gaps ? 'bg-warning text-dark' : 'bg-success'}`}>
                                {gapStatus?.benchmark?.has_gaps
                                    ? `${gapStatus.benchmark.gap_count} range(s)`
                                    : 'none'}
                            </span>
                            {gapStatus?.benchmark?.has_gaps && gapStatus.benchmark.ranges?.length > 0 ? (
                                <div className="text-muted mt-1">
                                    {formatGapRangeList(gapStatus.benchmark.ranges)}
                                </div>
                            ) : null}
                            <span className="text-muted ms-1">(live check)</span>
                        </dd>
                        <dt className="col-sm-4">Last scan</dt>
                        <dd className="col-sm-8">{lastScanLabel}</dd>
                        <dt className="col-sm-4">Last fill</dt>
                        <dd className="col-sm-8">
                            {gapStatus?.last_fill
                                ? (
                                    <>
                                        chunk filled {gapStatus.last_fill.filled ?? 0}, chunk failed{' '}
                                        {gapStatus.last_fill.failed ?? 0}, stored{' '}
                                        {gapStatus.last_fill.stored_rows ?? 0}
                                        {gapStatus.last_fill.still_with_gaps != null ? (
                                            <>
                                                {' '}
                                                · after full run:
                                                {' '}
                                                {gapStatus.last_fill.still_with_gaps}
                                                {' '}
                                                still gapped
                                                {gapStatus.last_fill_failure_report?.resolved != null ? (
                                                    <>
                                                        {' '}
                                                        (
                                                        {gapStatus.last_fill_failure_report.resolved}
                                                        {' '}
                                                        resolved,
                                                        {' '}
                                                        {gapStatus.last_fill_failure_report.unresolved
                                                            ?? gapStatus.last_fill.still_with_gaps}
                                                        {' '}
                                                        unresolved)
                                                    </>
                                                ) : null}
                                            </>
                                        ) : null}
                                    </>
                                )
                                : '—'}
                        </dd>
                        <dt className="col-sm-4">Nightly gap cursor</dt>
                        <dd className="col-sm-8">
                            {gapStatus?.cursor_symbol || '—'}
                            {gapStatus?.cursor_stock_id ? ` (#${gapStatus.cursor_stock_id})` : ''}
                            {' · '}
                            {gapStatus?.progress_percent ?? 0}
                            %
                            <span className="text-muted ms-1">(maintenance batches)</span>
                        </dd>
                    </dl>
                    <div className="d-flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="btn btn-outline-secondary btn-sm"
                            disabled={running || gapRunning || !status?.enabled}
                            title="Scan the entire universe for gaps (DB-only, one server request)."
                            onClick={() => runGapAll('scan')}
                        >
                            {gapRunning && gapProgressMode === 'scan' ? 'Scanning…' : 'Scan all gaps'}
                        </button>
                        <button
                            type="button"
                            className="btn btn-outline-primary btn-sm"
                            disabled={running || gapRunning || !status?.enabled}
                            title="Fill gapped symbols from the latest scan via providers (chunked)."
                            onClick={() => runGapAll('fill')}
                        >
                            {gapRunning && gapProgressMode === 'fill' ? 'Filling…' : 'Fill all gaps'}
                        </button>
                        <button
                            type="button"
                            className="btn btn-outline-secondary btn-sm"
                            disabled={running || gapRunning || !hasGapReportData}
                            title="Clear stored gap scan results and the last fill failure report."
                            onClick={clearGapReports}
                        >
                            Clear scan &amp; reports
                        </button>
                        <Link
                            to="/settings/universe-price-sync/gap-failures"
                            className="btn btn-outline-secondary btn-sm"
                        >
                            Fill failures
                        </Link>
                        <Link
                            to="/settings/universe-price-sync/ignored-gaps"
                            className="btn btn-outline-secondary btn-sm"
                        >
                            Ignored gaps
                            {(gapStatus?.ignored_gap_count ?? 0) > 0 ? ` (${gapStatus.ignored_gap_count})` : ''}
                        </Link>
                    </div>
                    {gapScanRows.length > 0 && !gapRunning && (
                        <div className="mt-3 border rounded">
                            <div className="card-header py-2 small fw-semibold">
                                Gap scan report (
                                {gapScanRows.length}
                                {' '}
                                rows)
                            </div>
                            <div className="table-responsive" style={{ maxHeight: '360px' }}>
                                <table className="table table-sm table-striped mb-0 small">
                                    <thead className="sticky-top">
                                        <tr>
                                            <th>Stock</th>
                                            <th>Exchange</th>
                                            <th>Gap start</th>
                                            <th>Gap end</th>
                                            <th>Gap days</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {gapScanRows.map((row) => {
                                            const rowKey = row.gap_key ?? `${row.stock}-${row.gap_start}-${row.gap_end}`;
                                            const isIgnored = row.ignored;
                                            return (
                                                <tr key={rowKey} className={isIgnored ? 'text-muted' : undefined}>
                                                    <td className="text-nowrap">{row.stock}</td>
                                                    <td>{row.exchange}</td>
                                                    <td>{row.gap_start}</td>
                                                    <td>{row.gap_end}</td>
                                                    <td>{row.gap_days}</td>
                                                    <td>
                                                        {isIgnored ? (
                                                            <span className="badge bg-secondary">Ignored</span>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="btn btn-outline-secondary btn-sm"
                                                                disabled={ignoringGapKey === rowKey}
                                                                onClick={() => ignoreGapRow(row)}
                                                            >
                                                                {ignoringGapKey === rowKey ? 'Saving…' : 'Ignore'}
                                                            </button>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>
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
                            <strong>Fill all gaps</strong>: fetches missing OHLCV from providers for symbols found in the
                            last scan. A symbol counts as resolved only when gaps are gone afterward — provider failures
                            stay on the list (check <strong>Last fill → still gapped</strong>).
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
                                        <td>{formatStatusTime(row.logged_at)}</td>
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
