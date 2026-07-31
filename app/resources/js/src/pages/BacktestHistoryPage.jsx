import React, { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api';
import NumberInput from '../components/NumberInput';
import { showToast } from '../toast';
import { backtestDetailPath } from '../navigation/routes';
import {
    backtestStatusBadgeClass,
    formatBacktestStage,
    getOrCreateBacktestSessionToken,
    isBacktestInProgress,
    parseTagsInput,
    startBacktest,
} from '../utils/backtestHelpers';
import { formatSignedPercent2 } from '../utils/tableFormat';
import { formatTransactionDateDisplay } from '../utils/transactionDate';

function fmtPeriod(run) {
    if (!run?.from_date || !run?.to_date) return '—';
    return `${run.from_date} → ${run.to_date}`;
}

function fmtReturn(run) {
    const pct = run?.statistics?.return_pct;
    if (pct == null || Number.isNaN(Number(pct))) return '—';
    return formatSignedPercent2(Number(pct));
}

function BacktestProgressPanel({ run }) {
    if (!run || !isBacktestInProgress(run)) {
        return null;
    }

    const showEligibility = run.stage === 'PREPARING' || run.status === 'preparing';

    return (
        <div className="card backtest-progress-banner mb-3">
            <div className="card-body py-3">
                <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <div className="fw-semibold">{formatBacktestStage(run.stage)}</div>
                        <div className="text-muted small">
                            {run.current_date ? `Current date: ${run.current_date}` : 'Starting…'}
                        </div>
                    </div>
                    <div className="text-end">
                        <div className="fw-semibold">{Number(run.progress_pct || 0).toFixed(1)}%</div>
                        <div className="text-muted small">
                            {run.processed_days ?? 0}
                            {' / '}
                            {run.total_days ?? 0}
                            {' days'}
                        </div>
                    </div>
                </div>
                <div className="progress mb-2" style={{ height: '0.5rem' }}>
                    <div
                        className="progress-bar progress-bar-striped progress-bar-animated"
                        role="progressbar"
                        style={{ width: `${Math.min(100, Math.max(0, Number(run.progress_pct || 0)))}%` }}
                        aria-valuenow={run.progress_pct || 0}
                        aria-valuemin={0}
                        aria-valuemax={100}
                    />
                </div>
                {showEligibility && (
                    <div className="text-muted small">
                        Eligibility
                        {run.eligibility_phase ? `: ${run.eligibility_phase}` : ''}
                        {' · '}
                        {Number(run.eligibility_progress || 0).toFixed(1)}%
                    </div>
                )}
            </div>
        </div>
    );
}

export default function BacktestHistoryPage() {
    const navigate = useNavigate();
    const [runs, setRuns] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [showModal, setShowModal] = useState(false);
    const [starting, setStarting] = useState(false);
    const [activeRun, setActiveRun] = useState(null);

    const [form, setForm] = useState({
        name: '',
        range_key: '1y',
        initial_capital: '1000000',
        notes: '',
        tags: '',
    });

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const [listRes, metaRes] = await Promise.all([
                api.get('/v1/backtests'),
                api.get('/v1/backtests/meta'),
            ]);
            setRuns(listRes.data?.data?.runs || []);
            setMeta(metaRes.data?.data || null);
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Failed to load backtests', 'danger');
            setRuns([]);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (meta?.ranges?.length && !meta.ranges.some((r) => r.id === form.range_key)) {
            setForm((prev) => ({ ...prev, range_key: meta.ranges[0].id }));
        }
    }, [meta, form.range_key]);

    const onDelete = async (run) => {
        if (!window.confirm(`Delete backtest “${run.name || `#${run.id}`}”? This cannot be undone.`)) {
            return;
        }
        try {
            await api.delete(`/v1/backtests/${run.id}`);
            showToast('Backtest deleted', 'success');
            await load();
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Delete failed', 'danger');
        }
    };

    const onStart = async (event) => {
        event.preventDefault();
        setStarting(true);
        setActiveRun(null);
        try {
            const payload = {
                name: form.name.trim() || undefined,
                range_key: form.range_key,
                initial_capital: Number(form.initial_capital),
                notes: form.notes.trim() || undefined,
                tags: parseTagsInput(form.tags),
                session_token: getOrCreateBacktestSessionToken(),
            };
            const result = await startBacktest(payload, setActiveRun);
            const run = result.run;
            if (!run?.id) {
                throw new Error('Backtest did not return a run id.');
            }
            if (run.status === 'failed') {
                showToast(run.error_message || 'Backtest failed', 'danger');
                setShowModal(false);
                await load();
                navigate(backtestDetailPath(run.id));
                return;
            }
            if (result.completed || run.status === 'completed') {
                showToast('Backtest completed', 'success');
                setShowModal(false);
                await load();
                navigate(backtestDetailPath(run.id));
                return;
            }
            showToast('Backtest is still running — open the run to resume.', 'warning');
            setShowModal(false);
            await load();
            navigate(backtestDetailPath(run.id));
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Failed to start backtest', 'danger');
        } finally {
            setStarting(false);
            setActiveRun(null);
        }
    };

    const ranges = meta?.ranges || [];

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <p className="text-muted small mb-0">
                        Paper-trade your active Strategy over historical dates. Results include portfolio growth,
                        trades, and full statistics — separate from live Recommendations.
                    </p>
                </div>
                <div className="d-flex flex-wrap gap-2">
                    <Link to="/strategy" className="btn btn-outline-secondary btn-sm">Strategy editor</Link>
                    <button type="button" className="btn btn-primary btn-sm" onClick={() => setShowModal(true)}>
                        New Backtest
                    </button>
                </div>
            </div>

            {activeRun && <BacktestProgressPanel run={activeRun} />}

            {loading ? (
                <p className="text-muted mb-0">Loading backtests…</p>
            ) : runs.length === 0 ? (
                <div className="card">
                    <div className="card-body text-muted">
                        No backtests yet. Click <strong>New Backtest</strong> to simulate your active Strategy.
                    </div>
                </div>
            ) : (
                <div className="table-responsive">
                    <table className="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Run Name</th>
                                <th>Strategy</th>
                                <th>Period</th>
                                <th className="text-end">Return %</th>
                                <th>Execution Date</th>
                                <th>Status</th>
                                <th className="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {runs.map((run) => (
                                <tr key={run.id}>
                                    <td>
                                        <Link to={backtestDetailPath(run.id)} className="text-decoration-none fw-semibold">
                                            {run.name || `Backtest #${run.id}`}
                                        </Link>
                                    </td>
                                    <td>{run.strategy_name || '—'}</td>
                                    <td className="small">{fmtPeriod(run)}</td>
                                    <td className="text-end">{fmtReturn(run)}</td>
                                    <td className="small">
                                        {formatTransactionDateDisplay(run.completed_at || run.created_at)}
                                    </td>
                                    <td>
                                        <span className={`badge ${backtestStatusBadgeClass(run.status)}`}>
                                            {run.status}
                                        </span>
                                    </td>
                                    <td className="text-end">
                                        <div className="d-inline-flex gap-1">
                                            <Link
                                                to={backtestDetailPath(run.id)}
                                                className="btn btn-outline-primary btn-sm"
                                            >
                                                Open
                                            </Link>
                                            <button
                                                type="button"
                                                className="btn btn-outline-secondary btn-sm"
                                                disabled
                                                title="Coming soon"
                                            >
                                                Duplicate
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-outline-danger btn-sm"
                                                onClick={() => onDelete(run)}
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {showModal && (
                <div className="modal d-block" tabIndex={-1} role="dialog" style={{ backgroundColor: 'rgba(0,0,0,0.45)' }}>
                    <div className="modal-dialog modal-dialog-centered">
                        <div className="modal-content">
                            <form onSubmit={onStart}>
                                <div className="modal-header">
                                    <h2 className="modal-title h5 mb-0">New Strategy Backtest</h2>
                                    <button
                                        type="button"
                                        className="btn-close"
                                        aria-label="Close"
                                        disabled={starting}
                                        onClick={() => setShowModal(false)}
                                    />
                                </div>
                                <div className="modal-body d-grid gap-3">
                                    <div>
                                        <label className="form-label small mb-1" htmlFor="bt-name">Name</label>
                                        <input
                                            id="bt-name"
                                            className="form-control form-control-sm"
                                            value={form.name}
                                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                                            placeholder="Optional label"
                                            disabled={starting}
                                        />
                                    </div>
                                    <div>
                                        <label className="form-label small mb-1" htmlFor="bt-range">Period</label>
                                        <select
                                            id="bt-range"
                                            className="form-select form-select-sm"
                                            value={form.range_key}
                                            onChange={(e) => setForm({ ...form, range_key: e.target.value })}
                                            disabled={starting}
                                        >
                                            {ranges.map((r) => (
                                                <option key={r.id} value={r.id}>{r.label}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="form-label small mb-1" htmlFor="bt-capital">Initial capital</label>
                                        <NumberInput
                                            id="bt-capital"
                                            value={form.initial_capital}
                                            onChange={(e) => setForm({ ...form, initial_capital: e.target.value })}
                                            min={1000}
                                            step={10000}
                                            compact
                                            disabled={starting}
                                        />
                                    </div>
                                    <div>
                                        <label className="form-label small mb-1" htmlFor="bt-notes">Notes</label>
                                        <textarea
                                            id="bt-notes"
                                            className="form-control form-control-sm"
                                            rows={2}
                                            value={form.notes}
                                            onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                            disabled={starting}
                                        />
                                    </div>
                                    <div>
                                        <label className="form-label small mb-1" htmlFor="bt-tags">Tags</label>
                                        <input
                                            id="bt-tags"
                                            className="form-control form-control-sm"
                                            value={form.tags}
                                            onChange={(e) => setForm({ ...form, tags: e.target.value })}
                                            placeholder="comma-separated"
                                            disabled={starting}
                                        />
                                    </div>
                                    {starting && activeRun && <BacktestProgressPanel run={activeRun} />}
                                </div>
                                <div className="modal-footer">
                                    <button
                                        type="button"
                                        className="btn btn-outline-secondary btn-sm"
                                        onClick={() => setShowModal(false)}
                                        disabled={starting}
                                    >
                                        Cancel
                                    </button>
                                    <button type="submit" className="btn btn-primary btn-sm" disabled={starting}>
                                        {starting ? 'Running…' : 'Start'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
