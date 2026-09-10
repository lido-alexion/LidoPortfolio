import React, { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api';
import NumberInput from '../components/NumberInput';
import { showToast } from '../toast';
import { backtestDetailPath } from '../navigation/routes';
import {
    BACKTEST_DURATION_NOTICE,
    backtestStatusBadgeClass,
    duplicateBacktestPayload,
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
                <p className="text-muted small mb-0 mt-2">{BACKTEST_DURATION_NOTICE}</p>
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
    const [selectedIds, setSelectedIds] = useState([]);
    const [comparison, setComparison] = useState(null);

    const [form, setForm] = useState({
        name: '',
        range_key: '1y',
        initial_capital: '1000000',
        notes: '',
        tags: '',
        price_method: 'next_open',
        adverse_slippage_percent: '0',
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

    const onCancel = async (run) => {
        if (!window.confirm(`Cancel backtest “${run.name || `#${run.id}`}” at its latest durable checkpoint?`)) {
            return;
        }
        try {
            await api.post(`/v1/backtests/${run.id}/cancel`);
            showToast('Backtest cancelled', 'success');
            await load();
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Cancellation failed', 'danger');
        }
    };

    const compareSelected = async () => {
        try {
            const response = await api.post('/v1/backtests/compare', { run_ids: selectedIds });
            setComparison(response.data?.data || null);
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Comparison failed', 'danger');
        }
    };

    const toggleComparison = (id) => setSelectedIds((current) => current.includes(id)
        ? current.filter((value) => value !== id)
        : current.length < 5 ? [...current, id] : current);

    const finishStartedRun = async (result, { closeModal = false } = {}) => {
        const run = result.run;
        if (!run?.id) {
            throw new Error('Backtest did not return a run id.');
        }
        if (run.status === 'failed') {
            showToast(run.error_message || 'Backtest failed', 'danger');
        } else if (result.completed || run.status === 'completed') {
            showToast('Backtest completed', 'success');
        } else {
            showToast('Backtest is still running in the background.', 'warning');
        }
        if (closeModal) {
            setShowModal(false);
        }
        await load();
        navigate(backtestDetailPath(run.id));
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
                price_method: form.price_method,
                adverse_slippage_percent: Number(form.adverse_slippage_percent || 0),
                session_token: getOrCreateBacktestSessionToken(),
            };
            const result = await startBacktest(payload, setActiveRun);
            await finishStartedRun(result, { closeModal: true });
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Failed to start backtest', 'danger');
        } finally {
            setStarting(false);
            setActiveRun(null);
        }
    };

    const onDuplicate = async (run) => {
        let payload;
        try {
            payload = duplicateBacktestPayload(run, getOrCreateBacktestSessionToken());
        } catch (e) {
            showToast(e.message || 'Cannot duplicate this backtest', 'danger');
            return;
        }
        setStarting(true);
        setActiveRun(null);
        try {
            const result = await startBacktest(payload, setActiveRun);
            await finishStartedRun(result);
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Failed to duplicate backtest', 'danger');
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
                    <div className="d-flex justify-content-end mb-2"><button type="button" className="btn btn-outline-primary btn-sm" disabled={selectedIds.length < 2} onClick={compareSelected}>Compare selected ({selectedIds.length})</button></div>
                    <table className="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th aria-label="Select for comparison" />
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
                                    <td><input type="checkbox" aria-label={`Compare Backtest ${run.id}`} checked={selectedIds.includes(run.id)} disabled={!selectedIds.includes(run.id) && selectedIds.length >= 5} onChange={() => toggleComparison(run.id)} /></td>
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
                                                disabled={starting}
                                                title="Start a new simulation with this run’s dates, capital, notes, and tags using the current Strategy"
                                                onClick={() => onDuplicate(run)}
                                            >
                                                Duplicate
                                            </button>
                                            {isBacktestInProgress(run) && (
                                                <button
                                                    type="button"
                                                    className="btn btn-outline-warning btn-sm"
                                                    onClick={() => onCancel(run)}
                                                >
                                                    Cancel
                                                </button>
                                            )}
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
                    {comparison && <div className={`alert ${comparison.compatible ? 'alert-info' : 'alert-warning'}`} data-testid="backtest-comparison">
                        <div className="d-flex justify-content-between"><strong>{comparison.compatible ? 'Compatible Backtest comparison' : 'Backtest compatibility differs'}</strong><button type="button" className="btn-close" aria-label="Close comparison" onClick={() => setComparison(null)} /></div>
                        {comparison.assumption_differences?.length ? <div className="small">Different assumptions: {comparison.assumption_differences.map((item) => item.replaceAll('_', ' ')).join(', ')}</div> : null}
                        <div className="row g-2 mt-1">{comparison.runs?.map((run) => <div className="col-md" key={run.id}><div className="border rounded bg-white p-2 small"><strong>{run.name || `Backtest #${run.id}`}</strong><br />Return: {run.statistics?.return_pct ?? 'Unavailable'}%<br />Drawdown: {run.statistics?.maximum_drawdown ?? 'Unavailable'}%<br />Charges: {run.statistics?.simulated_charges ?? 'Unavailable'}<br />Trades: {run.statistics?.total_trades ?? 'Unavailable'}</div></div>)}</div>
                        <div className="small mt-2">{comparison.disclosure}</div>
                    </div>}
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
                                    <div className="alert alert-warning py-2 px-3 mb-0 small" role="status">
                                        {BACKTEST_DURATION_NOTICE}
                                    </div>
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
                                    <div className="row g-2">
                                        <div className="col-sm-8">
                                            <label className="form-label small mb-1" htmlFor="bt-price-method">Execution price</label>
                                            <select id="bt-price-method" className="form-select form-select-sm" value={form.price_method} onChange={(e) => setForm({ ...form, price_method: e.target.value })} disabled={starting}>
                                                <option value="next_open">Next eligible session open</option>
                                                <option value="next_close">Next eligible session close</option>
                                                <option value="ohlc_average">Next eligible session OHLC average</option>
                                                <option value="high_low_midpoint">Next eligible session high/low midpoint</option>
                                            </select>
                                        </div>
                                        <div className="col-sm-4">
                                            <label className="form-label small mb-1" htmlFor="bt-slippage">Adverse slippage %</label>
                                            <NumberInput id="bt-slippage" value={form.adverse_slippage_percent} onChange={(e) => setForm({ ...form, adverse_slippage_percent: e.target.value })} min={0} max={100} step={0.01} compact disabled={starting} />
                                        </div>
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
