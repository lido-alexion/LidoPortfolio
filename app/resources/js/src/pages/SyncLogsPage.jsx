import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import TablePagination from '../components/TablePagination';
import { showToast } from '../toast';

const PER_PAGE = 50;

const JOB_OPTIONS = [
    { value: '', label: 'All jobs' },
    { value: 'daily-market-data', label: 'Daily market data' },
    { value: 'stock-master', label: 'Stock master' },
];

const LEVEL_OPTIONS = [
    { value: '', label: 'All levels' },
    { value: 'debug', label: 'debug' },
    { value: 'info', label: 'info' },
    { value: 'warning', label: 'warning' },
    { value: 'error', label: 'error' },
];

function levelBadgeClass(level) {
    switch (level) {
        case 'error':
            return 'bg-danger';
        case 'warning':
            return 'bg-warning text-dark';
        case 'debug':
            return 'bg-secondary';
        default:
            return 'bg-info text-dark';
    }
}

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

function formatContext(context) {
    if (!context || typeof context !== 'object') {
        return '';
    }
    try {
        return JSON.stringify(context);
    } catch {
        return '';
    }
}

function formatRunStats(run) {
    if (run.summary) {
        return run.summary;
    }
    if (run.stocks_processed == null) {
        return '—';
    }
    return `processed=${run.stocks_processed}, failures=${run.failures ?? 0}`;
}

function statusBadgeClass(status) {
    switch (status) {
        case 'success':
            return 'bg-success';
        case 'partial':
            return 'bg-warning text-dark';
        case 'failed':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
}

export default function SyncLogsPage() {
    const [logs, setLogs] = useState([]);
    const [runs, setRuns] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [exporting, setExporting] = useState(false);
    const [loadError, setLoadError] = useState('');
    const [level, setLevel] = useState('');
    const [jobName, setJobName] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');

    const filters = useMemo(() => ({
        level: level || undefined,
        job_name: jobName || undefined,
        search: search || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
    }), [level, jobName, search, dateFrom, dateTo]);

    const load = useCallback(async (pageNum, activeFilters) => {
        setLoading(true);
        setLoadError('');
        try {
            const [logsRes, runsRes] = await Promise.all([
                api.get('/sync-logs', {
                    params: {
                        page: pageNum,
                        per_page: PER_PAGE,
                        ...activeFilters,
                    },
                }),
                api.get('/sync-logs/runs', {
                    params: {
                        limit: 20,
                        job_name: activeFilters.job_name || undefined,
                    },
                }),
            ]);

            const logRows = logsRes.data?.data;
            const runRows = runsRes.data?.data;

            if (!Array.isArray(logRows)) {
                throw new Error('Sync logs API returned an unexpected response. Re-upload routes/api.php and SyncLogController.php.');
            }
            if (!Array.isArray(runRows)) {
                throw new Error('Sync runs API returned an unexpected response. Re-upload routes/api.php and SyncLogController.php.');
            }

            setLogs(logRows);
            setRuns(runRows);
            setPagination({
                current_page: logsRes.data.current_page,
                last_page: logsRes.data.last_page,
                from: logsRes.data.from,
                to: logsRes.data.to,
                total: logsRes.data.total,
            });
        } catch (err) {
            setLogs([]);
            setRuns([]);
            setPagination(null);
            const msg = err?.response?.data?.message
                || err?.message
                || 'Failed to load sync logs';
            setLoadError(msg);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load(page, filters);
    }, [load, page, filters]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setSearch(searchInput.trim());
            setPage(1);
        }, 300);
        return () => window.clearTimeout(timer);
    }, [searchInput]);

    const resetFilters = () => {
        setLevel('');
        setJobName('');
        setSearchInput('');
        setSearch('');
        setDateFrom('');
        setDateTo('');
        setPage(1);
    };

    const exportCsv = async () => {
        setExporting(true);
        try {
            const res = await api.get('/sync-logs/export', {
                params: filters,
                responseType: 'blob',
                skipErrorToast: true,
            });
            const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8' });
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `sync-logs-${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
            showToast('Sync logs exported');
        } catch {
            showToast('Failed to export sync logs', 'danger');
        } finally {
            setExporting(false);
        }
    };

    const emptyMessage = search || level || dateFrom || dateTo
        ? 'No sync log entries match your filters.'
        : runs.length > 0
            ? 'No detailed log lines recorded for these runs. If this persists after the next sync, confirm migration 2026_06_21_000002 is applied on the server.'
            : 'No sync logs recorded yet. Logs appear after daily or stock-master sync runs.';

    const runsWithoutLogLines = runs.some((run) => (run.log_lines ?? 0) === 0);

    return (
        <div className="row g-3">
            <div className="col-12">
                <div className="card">
                    <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span>Sync logs</span>
                        <div className="d-flex flex-wrap gap-2">
                            <button
                                type="button"
                                className="btn btn-sm btn-outline-secondary"
                                onClick={resetFilters}
                            >
                                Clear filters
                            </button>
                            <button
                                type="button"
                                className="btn btn-sm btn-outline-primary"
                                onClick={exportCsv}
                                disabled={exporting}
                            >
                                {exporting ? 'Exporting…' : 'Export CSV'}
                            </button>
                            <Link className="btn btn-sm btn-outline-secondary" to="/settings">
                                ← Back to settings
                            </Link>
                        </div>
                    </div>
                    <div className="card-body">
                        {loadError ? (
                            <div className="alert alert-danger py-2 small">{loadError}</div>
                        ) : null}
                        <div className="row g-2 mb-3">
                            <div className="col-12 col-md-3">
                                <label className="form-label small mb-1" htmlFor="sync-log-level">
                                    Level
                                </label>
                                <select
                                    id="sync-log-level"
                                    className="form-select form-select-sm"
                                    value={level}
                                    onChange={(e) => {
                                        setLevel(e.target.value);
                                        setPage(1);
                                    }}
                                >
                                    {LEVEL_OPTIONS.map((opt) => (
                                        <option key={opt.value || 'all'} value={opt.value}>
                                            {opt.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="col-12 col-md-3">
                                <label className="form-label small mb-1" htmlFor="sync-log-job">
                                    Job
                                </label>
                                <select
                                    id="sync-log-job"
                                    className="form-select form-select-sm"
                                    value={jobName}
                                    onChange={(e) => {
                                        setJobName(e.target.value);
                                        setPage(1);
                                    }}
                                >
                                    {JOB_OPTIONS.map((opt) => (
                                        <option key={opt.value || 'all'} value={opt.value}>
                                            {opt.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="col-12 col-md-3">
                                <label className="form-label small mb-1" htmlFor="sync-log-from">
                                    From date
                                </label>
                                <input
                                    id="sync-log-from"
                                    type="date"
                                    className="form-control form-control-sm"
                                    value={dateFrom}
                                    onChange={(e) => {
                                        setDateFrom(e.target.value);
                                        setPage(1);
                                    }}
                                />
                            </div>
                            <div className="col-12 col-md-3">
                                <label className="form-label small mb-1" htmlFor="sync-log-to">
                                    To date
                                </label>
                                <input
                                    id="sync-log-to"
                                    type="date"
                                    className="form-control form-control-sm"
                                    value={dateTo}
                                    onChange={(e) => {
                                        setDateTo(e.target.value);
                                        setPage(1);
                                    }}
                                />
                            </div>
                            <div className="col-12">
                                <label className="form-label small mb-1" htmlFor="sync-log-search">
                                    Search message or context
                                </label>
                                <input
                                    id="sync-log-search"
                                    type="search"
                                    className="form-control form-control-sm"
                                    placeholder="Search sync log messages"
                                    value={searchInput}
                                    onChange={(e) => setSearchInput(e.target.value)}
                                />
                            </div>
                        </div>

                        <p className="text-muted small">
                            Dates use your configured cron timezone. File logs in
                            {' '}
                            <code>storage/logs/scheduler-*.log</code>
                            {' '}
                            are kept separately.
                        </p>

                        {runs.length > 0 ? (
                            <div className="mb-4">
                                <h2 className="h6 mb-2">Recent runs</h2>
                                {runsWithoutLogLines && logs.length === 0 && !loading ? (
                                    <p className="alert alert-warning py-2 small mb-2">
                                        Run summaries exist but detailed log lines are missing. Apply migration
                                        {' '}
                                        <code>2026_06_21_000002</code>
                                        {' '}
                                        if needed, then run another daily or stock-master sync.
                                    </p>
                                ) : null}
                                <div className="table-responsive">
                                    <table className="table table-sm table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Started</th>
                                                <th>Finished</th>
                                                <th>Status</th>
                                                <th>Job</th>
                                                <th>Result</th>
                                                <th>Log lines</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {runs.map((run) => (
                                                <tr key={run.run_id}>
                                                    <td className="text-nowrap small">
                                                        {formatTimestamp(run.started_at)}
                                                    </td>
                                                    <td className="text-nowrap small">
                                                        {formatTimestamp(run.finished_at)}
                                                    </td>
                                                    <td>
                                                        <span className={`badge ${statusBadgeClass(run.status)}`}>
                                                            {run.status}
                                                        </span>
                                                    </td>
                                                    <td className="small text-nowrap">{run.job_name}</td>
                                                    <td className="small">{formatRunStats(run)}</td>
                                                    <td className="small">{run.log_lines ?? 0}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        ) : null}

                        <h2 className="h6 mb-2">Log entries</h2>

                        <div className="table-responsive">
                            <table className="table table-sm table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Level</th>
                                        <th>Job</th>
                                        <th>Message</th>
                                        <th>Context</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {loading ? (
                                        <tr>
                                            <td colSpan={5} className="text-muted text-center py-4">
                                                Loading…
                                            </td>
                                        </tr>
                                    ) : logs.length === 0 ? (
                                        <tr>
                                            <td colSpan={5} className="text-muted text-center py-4">
                                                {emptyMessage}
                                            </td>
                                        </tr>
                                    ) : (
                                        logs.map((log) => (
                                            <tr key={log.id}>
                                                <td className="text-nowrap small">
                                                    {formatTimestamp(log.logged_at)}
                                                </td>
                                                <td>
                                                    <span className={`badge ${levelBadgeClass(log.level)}`}>
                                                        {log.level}
                                                    </span>
                                                </td>
                                                <td className="small text-nowrap">{log.job_name}</td>
                                                <td className="small">{log.message}</td>
                                                <td className="small text-muted text-break">
                                                    {formatContext(log.context)}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <TablePagination
                            meta={!loading && logs.length > 0 ? pagination : null}
                            onPageChange={setPage}
                        />
                    </div>
                </div>
            </div>
        </div>
    );
}
