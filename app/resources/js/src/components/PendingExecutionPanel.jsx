import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';

import api from '../api';
import { showToast } from '../toast';

const CANCEL_REASONS = [
    { value: 'price_moved', label: 'Price moved significantly' },
    { value: 'market_conditions', label: 'Market conditions changed' },
    { value: 'funds_unavailable', label: 'Funds unavailable' },
    { value: 'broker_rejected', label: 'Broker rejected order' },
    { value: 'executed_outside_system', label: 'Executed outside system' },
    { value: 'no_longer_valid', label: 'Recommendation no longer valid' },
    { value: 'other', label: 'Other' },
];

function money(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 });
}

function fmtDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

/**
 * Pending Execution queue — approved recommendations awaiting a ledger fill.
 */
export default function PendingExecutionPanel({ onExecuteStarted }) {
    const navigate = useNavigate();
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [busyId, setBusyId] = useState(null);
    const [cancelId, setCancelId] = useState(null);
    const [cancelReason, setCancelReason] = useState('other');
    const [cash, setCash] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await api.get('/v1/recommendations/pending-execution');
            setRows(Array.isArray(data?.data) ? data.data : []);
            setCash(data?.meta?.cash || null);
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Failed to load pending execution', 'danger');
            setRows([]);
            setCash(null);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return rows;
        return rows.filter((r) => {
            const hay = `${r.symbol || ''} ${r.name || ''} ${r.ui_label || ''} ${r.portfolio_action || ''}`.toLowerCase();
            return hay.includes(q);
        });
    }, [rows, search]);

    const executeManually = (rec) => {
        const side = rec.order_side || 'buy';
        const qty = rec.suggested_quantity != null
            ? Math.max(1, Math.round(Number(rec.suggested_quantity)))
            : '';
        const price = rec.reference_price ?? rec.current_market_price ?? '';
        onExecuteStarted?.();
        navigate('/transactions', {
            state: {
                executeRecommendation: {
                    recommendation_id: rec.id,
                    stock_id: rec.security_id,
                    symbol: rec.symbol,
                    name: rec.name,
                    type: side,
                    quantity: qty,
                    price: price !== '' && price != null ? String(price) : '',
                    notes: `From recommendation #${rec.id} (${rec.ui_label || rec.portfolio_action})`,
                },
            },
        });
    };

    const cancelExecution = async (id) => {
        setBusyId(id);
        try {
            await api.post(`/v1/recommendations/${id}/cancel-execution`, {
                reason: cancelReason || 'other',
            });
            showToast('Execution cancelled', 'success');
            setCancelId(null);
            await load();
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Cancel failed', 'danger');
        } finally {
            setBusyId(null);
        }
    };

    return (
        <div className="card">
            <div className="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span>Pending Execution</span>
                <div className="d-flex gap-2 align-items-center">
                    <input
                        type="search"
                        className="form-control form-control-sm"
                        style={{ minWidth: 180 }}
                        placeholder="Search symbol…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load} disabled={loading}>
                        Refresh
                    </button>
                </div>
            </div>
            {cash && (
                <div className="px-3 pt-3 small text-muted d-flex flex-wrap gap-3">
                    <span>Cash balance: <strong className="text-body">{money(cash.cash_balance)}</strong></span>
                    <span>Reserved: <strong className="text-body">{money(cash.reserved_cash)}</strong></span>
                    <span>Available: <strong className="text-body">{money(cash.available_investable_cash)}</strong></span>
                </div>
            )}
            <div className="card-body p-0">
                {loading ? (
                    <p className="p-3 text-muted mb-0">Loading…</p>
                ) : filtered.length === 0 ? (
                    <p className="p-3 text-muted mb-0">
                        No recommendations awaiting execution.
                        {' '}
                        Approve trades on
                        {' '}
                        <Link to="/recommendations">Recommendations</Link>
                        .
                    </p>
                ) : (
                    <div className="table-responsive">
                        <table className="table table-sm table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Stock</th>
                                    <th>Action</th>
                                    <th className="text-end">Qty</th>
                                    <th className="text-end">Sugg. amt</th>
                                    <th className="text-end">Reserved</th>
                                    <th className="text-end">Sugg. price</th>
                                    <th className="text-end">Market</th>
                                    <th>Approved</th>
                                    <th>Age</th>
                                    <th className="text-end">Conf.</th>
                                    <th>Status</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {filtered.map((r) => (
                                    <tr key={r.id}>
                                        <td>
                                            <strong>{r.symbol}</strong>
                                            <div className="small text-muted text-truncate" style={{ maxWidth: 140 }}>{r.name}</div>
                                        </td>
                                        <td>{r.ui_label || r.portfolio_action}</td>
                                        <td className="text-end">{r.suggested_quantity != null ? Math.round(Number(r.suggested_quantity)) : '—'}</td>
                                        <td className="text-end">{money(r.suggested_investment_amount)}</td>
                                        <td className="text-end">{money(r.reserved_amount)}</td>
                                        <td className="text-end">{money(r.reference_price)}</td>
                                        <td className="text-end">{money(r.current_market_price)}</td>
                                        <td className="small">{fmtDate(r.approved_at)}</td>
                                        <td>{r.recommendation_age_days != null ? `${r.recommendation_age_days}d` : '—'}</td>
                                        <td className="text-end">{r.confidence != null ? Number(r.confidence).toFixed(0) : '—'}</td>
                                        <td><span className="badge text-bg-warning">{r.execution_status || 'pending'}</span></td>
                                        <td className="text-nowrap">
                                            <button
                                                type="button"
                                                className="btn btn-primary btn-sm me-1"
                                                disabled={busyId === r.id}
                                                onClick={() => executeManually(r)}
                                            >
                                                Execute manually
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-outline-danger btn-sm me-1"
                                                disabled={busyId === r.id}
                                                onClick={() => {
                                                    setCancelId(r.id);
                                                    setCancelReason('other');
                                                }}
                                            >
                                                Cancel
                                            </button>
                                            <Link className="btn btn-outline-secondary btn-sm" to="/recommendations" state={{ openRecommendationId: r.id }}>
                                                View
                                            </Link>
                                            {cancelId === r.id && (
                                                <div className="mt-2 p-2 border rounded bg-body-tertiary">
                                                    <label className="form-label small mb-1" htmlFor={`cancel-reason-${r.id}`}>Reason</label>
                                                    <select
                                                        id={`cancel-reason-${r.id}`}
                                                        className="form-select form-select-sm mb-2"
                                                        value={cancelReason}
                                                        onChange={(e) => setCancelReason(e.target.value)}
                                                    >
                                                        {CANCEL_REASONS.map((opt) => (
                                                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                                                        ))}
                                                    </select>
                                                    <div className="d-flex gap-1">
                                                        <button type="button" className="btn btn-danger btn-sm" onClick={() => cancelExecution(r.id)}>Confirm cancel</button>
                                                        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => setCancelId(null)}>Back</button>
                                                    </div>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}
