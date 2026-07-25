import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { showToast } from '../toast';

const ACTIONABLE = new Set(['OPEN_POSITION', 'INCREASE_POSITION', 'REDUCE_POSITION', 'EXIT_POSITION', 'BUY', 'SELL']);

function typeBadgeClass(action) {
    switch (String(action || '').toUpperCase()) {
        case 'OPEN_POSITION':
        case 'INCREASE_POSITION':
        case 'BUY':
            return 'text-bg-success';
        case 'REDUCE_POSITION':
        case 'EXIT_POSITION':
        case 'SELL':
            return 'text-bg-danger';
        case 'HOLD_POSITION':
        case 'HOLD':
            return 'text-bg-secondary';
        case 'WATCH':
            return 'text-bg-warning';
        default:
            return 'text-bg-light';
    }
}

function statusBadge(status) {
    switch (status) {
        case 'accepted': return 'text-bg-success';
        case 'rejected': return 'text-bg-danger';
        case 'deferred': return 'text-bg-warning';
        case 'executed': return 'text-bg-primary';
        case 'pending_review': return 'text-bg-info';
        case 'published': return 'text-bg-secondary';
        default: return 'text-bg-secondary';
    }
}

function formatPct(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return `${Math.round(Number(v) * 100)}%`;
}

function formatAlloc(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return `${Number(v).toFixed(2)}%`;
}

function isActionableRec(r) {
    const action = String(r.portfolio_action || r.recommendation_type || '').toUpperCase();
    return r.category === 'actionable' || ACTIONABLE.has(action);
}

function displayLabel(r) {
    return r.ui_label || r.recommendation_type || '—';
}

function RecTable({ rows, actionLabel, onOpen }) {
    if (rows.length === 0) {
        return <p className="text-muted small mb-0">None.</p>;
    }

    return (
        <div className="table-responsive">
            <table className="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Symbol</th>
                        <th>Opinion</th>
                        <th>Status</th>
                        <th>Alloc</th>
                        <th>Confidence</th>
                        <th>Generated</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.id}>
                            <td>
                                <span className={`badge ${typeBadgeClass(r.portfolio_action || r.recommendation_type)}`}>
                                    {displayLabel(r)}
                                </span>
                            </td>
                            <td>
                                <strong>{r.symbol}</strong>
                                {r.name ? <div className="small text-muted">{r.name}</div> : null}
                            </td>
                            <td className="small">
                                {r.market_opinion?.direction || '—'}
                                {r.market_opinion?.strength ? (
                                    <div className="text-muted">{r.market_opinion.strength}</div>
                                ) : null}
                            </td>
                            <td><span className={`badge ${statusBadge(r.status)}`}>{r.status}</span></td>
                            <td className="small">
                                {formatAlloc(r.current_allocation_pct)}
                                {' → '}
                                {formatAlloc(r.suggested_allocation_pct ?? r.target_allocation_pct)}
                            </td>
                            <td>{formatPct(r.confidence)}</td>
                            <td className="small">{r.generated_at ? new Date(r.generated_at).toLocaleString() : '—'}</td>
                            <td>
                                <button type="button" className="btn btn-link btn-sm px-0" onClick={() => onOpen(r.id)}>
                                    {actionLabel}
                                </button>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
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

    const tradeRecs = useMemo(() => recs.filter(isActionableRec), [recs]);
    const insights = useMemo(() => recs.filter((r) => !isActionableRec(r)), [recs]);

    const openDetail = async (id) => {
        try {
            const { data } = await api.get(`/v1/recommendations/${id}`);
            const detail = data?.data || null;
            setSelected(detail);
            setNotes('');
            const planQty = detail?.execution_plan?.suggested_quantity
                ?? detail?.execution_plan?.suggested_shares_to_sell;
            const wholeQty = planQty != null && !Number.isNaN(Number(planQty))
                ? String(Math.max(0, Math.round(Number(planQty))))
                : '1';
            setOrderQty(wholeQty === '0' ? '1' : wholeQty);
            setOrderPrice(detail?.reference_price != null ? String(detail.reference_price) : '');
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

    const reopen = async () => {
        if (!selected) return;
        setBusyId(selected.id);
        try {
            await api.post(`/v1/recommendations/${selected.id}/reopen`, {
                notes: notes || null,
            });
            showToast('Reopened for review', 'success');
            await load();
            const { data } = await api.get(`/v1/recommendations/${selected.id}`);
            setSelected(data?.data || null);
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e?.response?.data?.message || e.message || 'Reopen failed', 'danger');
        } finally {
            setBusyId(null);
        }
    };

    const decide = async (decision) => {
        if (!selected) return;
        setBusyId(selected.id);
        try {
            await api.post(`/v1/recommendations/${selected.id}/review`, {
                decision,
                notes: notes || null,
            });
            showToast(`Marked ${decision}`, 'success');
            await load();
            if (decision === 'rejected' || decision === 'deferred') {
                setSelected(null);
            } else {
                const { data } = await api.get(`/v1/recommendations/${selected.id}`);
                setSelected(data?.data || null);
            }
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e?.response?.data?.message || e.message || 'Review failed', 'danger');
        } finally {
            setBusyId(null);
        }
    };

    const createOrder = async (executeNow) => {
        if (!selected) return;
        setBusyId(selected.id);
        try {
            const side = selected.order_side
                || (['EXIT_POSITION', 'REDUCE_POSITION', 'SELL'].includes(String(selected.portfolio_action || selected.recommendation_type).toUpperCase())
                    ? 'sell'
                    : 'buy');
            const quantity = Math.max(0, Math.round(Number(orderQty)));
            if (quantity < 1) {
                showToast('Quantity must be at least 1 whole share', 'danger');
                return;
            }
            const payload = {
                security_id: selected.security_id,
                recommendation_id: selected.id,
                side,
                quantity,
                execute_now: executeNow,
                notes: notes || undefined,
            };
            if (executeNow) {
                payload.price = Number(orderPrice);
            } else if (orderPrice) {
                payload.limit_price = Number(orderPrice);
            }
            await api.post('/v1/orders', payload);
            showToast(executeNow ? 'Transaction added' : 'Pending order created', 'success');
            await openDetail(selected.id);
            await load();
        } catch (e) {
            showToast(e?.response?.data?.error?.message || e.message || 'Order failed', 'danger');
        } finally {
            setBusyId(null);
        }
    };

    const selectedActionable = selected && isActionableRec(selected);
    const opinion = selected?.market_opinion;
    const plan = selected?.execution_plan;

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Recommendations</h1>
                    <p className="text-muted small mb-0">
                        Market opinion is independent of your book; portfolio decisions respect holdings and target allocation.
                    </p>
                </div>
                <div className="d-flex flex-wrap gap-2 align-items-center">
                    <div className="form-check form-switch mb-0">
                        <input className="form-check-input" type="checkbox" id="showAllRecs" checked={showAll} onChange={(e) => setShowAll(e.target.checked)} />
                        <label className="form-check-label small" htmlFor="showAllRecs">Show all history</label>
                    </div>
                    <Link className="btn btn-outline-secondary btn-sm" to="/candidates">Discovery</Link>
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

            {loading ? (
                <p className="text-muted">Loading…</p>
            ) : (
                <>
                    <section className="mb-4">
                        <h2 className="h5 mb-1">Trade recommendations</h2>
                        <p className="small text-muted mb-2">Open / Buy More / Sell Partial / Sell All — review before recording a trade.</p>
                        {tradeRecs.length === 0 ? (
                            <div className="border rounded p-3 text-muted small">
                                No trade recommendations. Run the decision pipeline when market data is ready.
                            </div>
                        ) : (
                            <RecTable rows={tradeRecs} actionLabel="Review" onOpen={openDetail} />
                        )}
                    </section>

                    <section className="mb-2">
                        <h2 className="h5 mb-1">Market insights</h2>
                        <p className="small text-muted mb-2">Hold and Watch — informational only; no approval or orders.</p>
                        {insights.length === 0 ? (
                            <div className="border rounded p-3 text-muted small">No insights right now.</div>
                        ) : (
                            <RecTable rows={insights} actionLabel="View details" onOpen={openDetail} />
                        )}
                    </section>
                </>
            )}

            {selected && (
                <div className="modal show d-block lido-tos-review-modal" style={{ background: 'rgba(0,0,0,.45)' }} role="dialog" aria-modal="true">
                    <div className="modal-dialog modal-lg modal-dialog-scrollable">
                        <div className="modal-content">
                            <div className="modal-header">
                                <h5 className="modal-title">
                                    {displayLabel(selected)}
                                    {' '}
                                    {selected.symbol}
                                    {!selectedActionable ? (
                                        <span className="badge text-bg-secondary ms-2">Insight</span>
                                    ) : null}
                                </h5>
                                <button type="button" className="btn-close" onClick={() => setSelected(null)} aria-label="Close" />
                            </div>
                            <div className="modal-body">
                                <p className="small text-muted mb-2">
                                    Status
                                    {' '}
                                    <span className={`badge ${statusBadge(selected.status)}`}>{selected.status}</span>
                                    {' · '}
                                    Confidence
                                    {' '}
                                    {formatPct(selected.confidence)}
                                    {' · '}
                                    Ref ₹
                                    {selected.reference_price ?? '—'}
                                </p>

                                <h6>Market opinion</h6>
                                <p className="small mb-2">
                                    {opinion?.direction || '—'}
                                    {' · '}
                                    {opinion?.strength || '—'}
                                    {' · '}
                                    Confidence
                                    {' '}
                                    {formatPct(opinion?.confidence ?? selected.confidence)}
                                </p>

                                <h6>Portfolio decision</h6>
                                <p className="small mb-1">
                                    <span className={`badge ${typeBadgeClass(selected.portfolio_action || selected.recommendation_type)}`}>
                                        {displayLabel(selected)}
                                    </span>
                                    <span className="text-muted ms-2">{selected.portfolio_action || selected.recommendation_type}</span>
                                </p>
                                <p className="small text-muted mb-2">
                                    Current
                                    {' '}
                                    {formatAlloc(selected.current_allocation_pct)}
                                    {' · Target '}
                                    {formatAlloc(selected.target_allocation_pct)}
                                    {' · Suggested '}
                                    {formatAlloc(selected.suggested_allocation_pct)}
                                </p>
                                {selected.reasoning ? <p className="small">{selected.reasoning}</p> : null}

                                {plan && (
                                    <>
                                        <h6>Execution plan</h6>
                                        <pre className="small lido-tos-review-pre p-2 rounded" style={{ whiteSpace: 'pre-wrap' }}>
                                            {JSON.stringify(plan, null, 2)}
                                        </pre>
                                    </>
                                )}

                                <h6>Evidence</h6>
                                <ul className="small">
                                    {(selected.evidence?.passed_rules || opinion?.evidence?.passed_rules || []).map((x) => (
                                        <li key={`p-${x}`}>✓ {x}</li>
                                    ))}
                                    {(selected.failed_checks || []).map((x) => <li key={`f-${x}`}>✗ {x}</li>)}
                                </ul>
                                <pre className="small lido-tos-review-pre p-2 rounded" style={{ whiteSpace: 'pre-wrap' }}>
                                    {JSON.stringify(selected.evidence?.indicators || opinion?.evidence?.indicators || {}, null, 2)}
                                </pre>

                                {selected.can_reopen && selectedActionable && (
                                    <div className="mb-3">
                                        <button
                                            type="button"
                                            className="btn btn-outline-secondary btn-sm"
                                            disabled={busyId === selected.id}
                                            onClick={reopen}
                                        >
                                            Undo decision — reopen for review
                                        </button>
                                        <p className="form-text mb-0 mt-1">
                                            Clears Accept / Reject / Defer and returns to pending review. Pending orders are cancelled. Use Show all history to find rejected items.
                                        </p>
                                    </div>
                                )}

                                {selected.can_review && selectedActionable && (
                                    <>
                                        <label className="form-label" htmlFor="tos-review-notes">Review notes (optional)</label>
                                        <textarea
                                            id="tos-review-notes"
                                            className="form-control form-control-sm mb-2"
                                            rows={2}
                                            value={notes}
                                            onChange={(e) => setNotes(e.target.value)}
                                        />
                                        <div className="d-flex flex-wrap gap-2 mb-1">
                                            <button type="button" className="btn btn-success btn-sm" disabled={busyId === selected.id} onClick={() => decide('accepted')}>Accept</button>
                                            <button type="button" className="btn btn-outline-warning btn-sm" disabled={busyId === selected.id} onClick={() => decide('deferred')}>Defer</button>
                                            <button type="button" className="btn btn-outline-danger btn-sm" disabled={busyId === selected.id} onClick={() => decide('rejected')}>Reject</button>
                                        </div>
                                    </>
                                )}

                                {selected.can_create_order && selectedActionable && (
                                    <div className="lido-tos-review-order-box rounded p-2 mb-2">
                                        <h6 className="mb-2">Record trade</h6>
                                        <div className="row g-2 align-items-end">
                                            <div className="col-4">
                                                <label className="form-label mb-0" htmlFor="tos-order-qty">Quantity</label>
                                                <input id="tos-order-qty" className="form-control form-control-sm" value={orderQty} onChange={(e) => setOrderQty(e.target.value)} />
                                            </div>
                                            <div className="col-4">
                                                <label className="form-label mb-0" htmlFor="tos-order-price">Price</label>
                                                <input id="tos-order-price" className="form-control form-control-sm" value={orderPrice} onChange={(e) => setOrderPrice(e.target.value)} />
                                            </div>
                                            <div className="col-4 d-flex gap-1 flex-wrap">
                                                <button type="button" className="btn btn-outline-secondary btn-sm" disabled={busyId === selected.id} onClick={() => createOrder(false)}>Pending</button>
                                                <button type="button" className="btn btn-primary btn-sm" disabled={busyId === selected.id} onClick={() => createOrder(true)}>Add transaction</button>
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
                                                    {' by '}
                                                    {rev.user || 'user'}
                                                    {' at '}
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
