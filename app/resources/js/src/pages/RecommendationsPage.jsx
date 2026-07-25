import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';

function typeBadgeClass(type) {
    switch (String(type || '').toUpperCase()) {
        case 'BUY': return 'text-bg-success';
        case 'SELL': return 'text-bg-danger';
        case 'HOLD': return 'text-bg-secondary';
        case 'WATCH': return 'text-bg-warning';
        default: return 'text-bg-light';
    }
}

function statusBadge(status) {
    switch (status) {
        case 'accepted': return 'text-bg-success';
        case 'rejected': return 'text-bg-danger';
        case 'deferred': return 'text-bg-warning';
        case 'executed': return 'text-bg-primary';
        case 'pending_review': return 'text-bg-info';
        default: return 'text-bg-secondary';
    }
}

function formatPct(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return `${Math.round(Number(v) * 100)}%`;
}

export default function RecommendationsPage() {
    const [recs, setRecs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [running, setRunning] = useState(false);
    const [pipelineMeta, setPipelineMeta] = useState(null);
    const [selected, setSelected] = useState(null);
    const [notes, setNotes] = useState('');
    const [orderQty, setOrderQty] = useState('1');
    const [orderPrice, setOrderPrice] = useState('');
    const [busyId, setBusyId] = useState(null);
    const [showAll, setShowAll] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await api.get('/v1/recommendations', {
                params: showAll ? { all: 1 } : { open: 1 },
            });
            setRecs(Array.isArray(data?.data) ? data.data : []);
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Failed to load recommendations', 'danger');
        } finally {
            setLoading(false);
        }
    }, [showAll]);

    useEffect(() => { load(); }, [load]);

    const openDetail = async (id) => {
        try {
            const { data } = await api.get(`/v1/recommendations/${id}`);
            setSelected(data?.data || null);
            setNotes('');
            setOrderQty('1');
            setOrderPrice(data?.data?.reference_price != null ? String(data.data.reference_price) : '');
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Failed to load detail', 'danger');
        }
    };

    const runPipeline = async () => {
        setRunning(true);
        setPipelineMeta(null);
        try {
            const { data } = await api.post('/v1/pipeline/run', null, { params: { notify: 1, review: 1 } });
            setPipelineMeta(data?.data?.stages || null);
            showToast('Decision pipeline completed', 'success');
            await load();
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Pipeline failed', 'danger');
        } finally {
            setRunning(false);
        }
    };

    const decide = async (decision) => {
        if (!selected) return;
        setBusyId(selected.id);
        try {
            const { data } = await api.post(`/v1/recommendations/${selected.id}/review`, {
                decision,
                notes: notes || null,
            });
            setSelected(data?.data || null);
            showToast(`Marked ${decision}`, 'success');
            await load();
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Review failed', 'danger');
        } finally {
            setBusyId(null);
        }
    };

    const createOrder = async (executeNow) => {
        if (!selected) return;
        setBusyId(selected.id);
        try {
            const side = String(selected.recommendation_type).toUpperCase() === 'SELL' ? 'sell' : 'buy';
            const payload = {
                security_id: selected.security_id,
                recommendation_id: selected.id,
                side,
                quantity: Number(orderQty),
                execute_now: executeNow,
                notes: notes || undefined,
            };
            if (executeNow) {
                payload.price = Number(orderPrice);
            } else if (orderPrice) {
                payload.limit_price = Number(orderPrice);
            }
            await api.post('/v1/orders', payload);
            showToast(executeNow ? 'Order executed' : 'Pending order created', 'success');
            await openDetail(selected.id);
            await load();
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Order failed', 'danger');
        } finally {
            setBusyId(null);
        }
    };

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Recommendations</h1>
                    <p className="text-muted small mb-0">
                        Review decisions before execution. Accept → create order → record fill.
                    </p>
                </div>
                <div className="d-flex flex-wrap gap-2 align-items-center">
                    <div className="form-check form-switch mb-0">
                        <input className="form-check-input" type="checkbox" id="showAllRecs" checked={showAll} onChange={(e) => setShowAll(e.target.checked)} />
                        <label className="form-check-label small" htmlFor="showAllRecs">Show all</label>
                    </div>
                    <Link className="btn btn-outline-secondary btn-sm" to="/candidates">Candidates</Link>
                    <Link className="btn btn-outline-secondary btn-sm" to="/evaluations">Evaluations</Link>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load} disabled={loading || running}>Refresh</button>
                    <button type="button" className="btn btn-primary btn-sm" onClick={runPipeline} disabled={running}>
                        {running ? 'Running pipeline…' : 'Run decision pipeline'}
                    </button>
                </div>
            </div>

            {pipelineMeta && (
                <div className="alert alert-info small py-2">
                    Pipeline: candidates {pipelineMeta.discovery?.candidates ?? '—'}
                    {' · '}
                    evaluations {pipelineMeta.evaluation?.results ?? '—'}
                    {' · '}
                    recommendations {pipelineMeta.recommendation?.count ?? '—'}
                </div>
            )}

            {loading ? <p className="text-muted">Loading…</p> : recs.length === 0 ? (
                <div className="border rounded p-4 text-muted">
                    No open recommendations. Run the decision pipeline after market data and holdings/watchlist are ready.
                </div>
            ) : (
                <div className="table-responsive">
                    <table className="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Symbol</th>
                                <th>Status</th>
                                <th>Score</th>
                                <th>Confidence</th>
                                <th>Risk</th>
                                <th>Generated</th>
                                <th>Expires</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {recs.map((r) => (
                                <tr key={r.id}>
                                    <td><span className={`badge ${typeBadgeClass(r.recommendation_type)}`}>{r.recommendation_type}</span></td>
                                    <td>
                                        <strong>{r.symbol}</strong>
                                        {r.name ? <div className="small text-muted">{r.name}</div> : null}
                                    </td>
                                    <td><span className={`badge ${statusBadge(r.status)}`}>{r.status}</span></td>
                                    <td>{r.score != null ? Number(r.score).toFixed(1) : '—'}</td>
                                    <td>{formatPct(r.confidence)}</td>
                                    <td className="text-capitalize">{r.risk_level}</td>
                                    <td className="small">{r.generated_at ? new Date(r.generated_at).toLocaleString() : '—'}</td>
                                    <td className="small">{r.expires_at ? new Date(r.expires_at).toLocaleString() : '—'}</td>
                                    <td>
                                        <button type="button" className="btn btn-link btn-sm px-0" onClick={() => openDetail(r.id)}>
                                            Review
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {selected && (
                <div className="modal show d-block" style={{ background: 'rgba(0,0,0,.45)' }}>
                    <div className="modal-dialog modal-lg modal-dialog-scrollable">
                        <div className="modal-content">
                            <div className="modal-header">
                                <h5 className="modal-title">
                                    {selected.recommendation_type}
                                    {' '}
                                    {selected.symbol}
                                </h5>
                                <button type="button" className="btn-close" onClick={() => setSelected(null)} aria-label="Close" />
                            </div>
                            <div className="modal-body">
                                <p className="small text-muted mb-2">
                                    Status
                                    {' '}
                                    <span className={`badge ${statusBadge(selected.status)}`}>{selected.status}</span>
                                    {' · '}
                                    Score
                                    {' '}
                                    {selected.score ?? '—'}
                                    {' · '}
                                    Confidence
                                    {' '}
                                    {formatPct(selected.confidence)}
                                    {' · '}
                                    Ref ₹
                                    {selected.reference_price ?? '—'}
                                </p>
                                <p className="small">
                                    Generated
                                    {' '}
                                    {selected.generated_at ? new Date(selected.generated_at).toLocaleString() : '—'}
                                    {' · '}
                                    Expires
                                    {' '}
                                    {selected.expires_at ? new Date(selected.expires_at).toLocaleString() : '—'}
                                </p>

                                <h6>Evidence</h6>
                                <ul className="small">
                                    {(selected.evidence?.passed_rules || []).map((x) => <li key={`p-${x}`}>✓ {x}</li>)}
                                    {(selected.failed_checks || []).map((x) => <li key={`f-${x}`}>✗ {x}</li>)}
                                </ul>
                                <pre className="small bg-body-tertiary p-2 rounded" style={{ whiteSpace: 'pre-wrap' }}>
                                    {JSON.stringify(selected.evidence?.indicators || {}, null, 2)}
                                </pre>

                                {selected.can_review && (
                                    <>
                                        <label className="form-label small mb-1">Review notes (optional)</label>
                                        <textarea className="form-control form-control-sm mb-2" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
                                        <div className="d-flex flex-wrap gap-2 mb-3">
                                            <button type="button" className="btn btn-success btn-sm" disabled={busyId === selected.id} onClick={() => decide('accepted')}>Accept</button>
                                            <button type="button" className="btn btn-outline-warning btn-sm" disabled={busyId === selected.id} onClick={() => decide('deferred')}>Defer</button>
                                            <button type="button" className="btn btn-outline-danger btn-sm" disabled={busyId === selected.id} onClick={() => decide('rejected')}>Reject</button>
                                        </div>
                                    </>
                                )}

                                {selected.can_create_order && (
                                    <div className="border rounded p-2 mb-2">
                                        <h6 className="mb-2">Record execution</h6>
                                        <div className="row g-2 align-items-end">
                                            <div className="col-4">
                                                <label className="form-label small mb-0">Qty</label>
                                                <input className="form-control form-control-sm" value={orderQty} onChange={(e) => setOrderQty(e.target.value)} />
                                            </div>
                                            <div className="col-4">
                                                <label className="form-label small mb-0">Price</label>
                                                <input className="form-control form-control-sm" value={orderPrice} onChange={(e) => setOrderPrice(e.target.value)} />
                                            </div>
                                            <div className="col-4 d-flex gap-1">
                                                <button type="button" className="btn btn-outline-secondary btn-sm" disabled={busyId === selected.id} onClick={() => createOrder(false)}>Pending</button>
                                                <button type="button" className="btn btn-primary btn-sm" disabled={busyId === selected.id} onClick={() => createOrder(true)}>Execute</button>
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {(selected.reviews || []).length > 0 && (
                                    <>
                                        <h6>Review history</h6>
                                        <ul className="small">
                                            {selected.reviews.map((rev) => (
                                                <li key={rev.id}>
                                                    {rev.decision}
                                                    {' '}
                                                    by
                                                    {' '}
                                                    {rev.user || 'user'}
                                                    {' '}
                                                    at
                                                    {' '}
                                                    {rev.created_at ? new Date(rev.created_at).toLocaleString() : ''}
                                                    {rev.notes ? ` — ${rev.notes}` : ''}
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                )}
                            </div>
                            <div className="modal-footer">
                                <Link className="btn btn-outline-secondary btn-sm" to="/review">Review dashboard</Link>
                                <button type="button" className="btn btn-primary btn-sm" onClick={() => setSelected(null)}>Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
