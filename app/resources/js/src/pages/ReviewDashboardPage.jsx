import React, { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ROUTES } from '../navigation/routes';
import api, { getApiErrorMessage } from '../api';
import { showToast } from '../toast';
import { formatInrCompactWhole } from '../utils/tableFormat';
import { tosData, tosList } from '../utils/tosEnvelope';
import { usePortfolio } from '../context/PortfolioContext';
import { getActivePortfolioId } from '../portfolio/activePortfolioStorage';
import { PORTFOLIO_CHANGED } from '../utils/portfolioEvents';

function fmtPct(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return `${Number(v).toFixed(2)}%`;
}

function fmtNum(v) {
    if (v == null || Number.isNaN(Number(v))) return '—';
    return Number(v).toFixed(2);
}

const IN_FLIGHT_BROKER_STATUSES = new Set(['submitted', 'open', 'partial', 'unknown']);
const FINAL_NO_FILL_BROKER_STATUSES = new Set(['rejected', 'cancelled', 'canceled']);

function brokerStatusOf(order) {
    return String(order.broker_status || '').toLowerCase();
}

export default function ReviewDashboardPage() {
    const { activePortfolio, activePortfolioId } = usePortfolio();
    const [portfolioEpoch, setPortfolioEpoch] = useState(0);
    const [ownedData, setOwnedData] = useState(null);
    const [ownedLoading, setOwnedLoading] = useState(true);
    const [ownedError, setOwnedError] = useState(null);
    const [revision, setRevision] = useState(0);
    const [reconciling, setReconciling] = useState(null);
    const identity = useRef({ scope: null, epoch: 0, mounted: false });
    const readSequence = useRef(0);
    const reconcileSequence = useRef(0);
    const committedScope = activePortfolio && String(activePortfolio.id) === String(activePortfolioId)
        ? String(activePortfolioId) : null;
    useLayoutEffect(() => {
        identity.current.mounted = true;
        return () => {
            identity.current.mounted = false;
            identity.current.epoch += 1;
            readSequence.current += 1;
            reconcileSequence.current += 1;
        };
    }, []);
    useLayoutEffect(() => {
        if (identity.current.scope === committedScope) return;
        identity.current.scope = committedScope;
        identity.current.epoch += 1;
        readSequence.current += 1;
        reconcileSequence.current += 1;
        setOwnedData(null);
        setOwnedError(null);
        setOwnedLoading(true);
        setReconciling(null);
    }, [committedScope]);
    useLayoutEffect(() => {
        const onPortfolioChanged = () => {
            identity.current.epoch += 1;
            setPortfolioEpoch(identity.current.epoch);
            readSequence.current += 1;
            reconcileSequence.current += 1;
            setOwnedData(null);
            setOwnedError(null);
            setOwnedLoading(true);
            setReconciling(null);
            setRevision((value) => value + 1);
        };
        window.addEventListener(PORTFOLIO_CHANGED, onPortfolioChanged);
        return () => window.removeEventListener(PORTFOLIO_CHANGED, onPortfolioChanged);
    }, []);
    const isCurrent = useCallback((owner) => identity.current.mounted
        && owner.scope != null
        && owner.scope === identity.current.scope
        && getActivePortfolioId() === owner.scope
        && owner.epoch === identity.current.epoch
        && (owner.reconcileOperation == null || owner.reconcileOperation === reconcileSequence.current), []);
    const runOwnedMutation = async (request, owner, { successMessage, errorFallback }) => {
        if (!isCurrent(owner)) return { ok: false };
        try {
            const response = await request();
            if (successMessage && isCurrent(owner)) showToast(successMessage, 'success');
            return { ok: true, data: response };
        } catch (e) {
            if (isCurrent(owner)) showToast(getApiErrorMessage(e, errorFallback), 'danger');
            return { ok: false, error: e };
        }
    };
    const load = useCallback(async () => {
        const owner = { scope: committedScope, epoch: identity.current.epoch };
        if (!isCurrent(owner)) return null;
        const sequence = ++readSequence.current;
        setOwnedLoading(true);
        setOwnedError(null);
        try {
            const [d, o] = await Promise.all([
                api.get('/v1/review/dashboard', { skipErrorToast: true }),
                api.get('/v1/orders', { skipErrorToast: true }),
            ]);
            const result = {
                dash: tosData(d),
                orders: tosList(o),
            };
            if (isCurrent(owner) && sequence === readSequence.current) setOwnedData({ ...result, scope: owner.scope, epoch: owner.epoch, sequence });
            return isCurrent(owner) && sequence === readSequence.current ? result : null;
        } catch (e) {
            if (isCurrent(owner) && sequence === readSequence.current) {
                setOwnedError({ scope: owner.scope, epoch: owner.epoch, sequence, error: e });
                showToast(getApiErrorMessage(e, 'Failed to load review dashboard'), 'danger');
            }
            return null;
        } finally {
            if (isCurrent(owner) && sequence === readSequence.current) setOwnedLoading(false);
        }
    }, [committedScope, isCurrent]);
    useEffect(() => {
        if (committedScope) load();
    }, [committedScope, portfolioEpoch, revision, load]);
    const visibleData = ownedData?.scope === committedScope
        && ownedData?.epoch === identity.current.epoch
        && getActivePortfolioId() === committedScope;
    const data = visibleData ? ownedData : null;
    const loading = !committedScope || ownedLoading;
    const error = ownedError?.scope === committedScope
        && ownedError?.epoch === identity.current.epoch
        && getActivePortfolioId() === committedScope ? ownedError.error : null;
    const dash = data?.dash ?? null;
    const orders = data?.orders ?? [];
    const renderedOwner = { scope: committedScope, epoch: identity.current.epoch };

    const executePending = async (orderId, rowOwner) => {
        const owner = { ...rowOwner };
        if (!isCurrent(owner)) return;
        const price = window.prompt('Execution price');
        if (!price || !isCurrent(owner)) return;
        const { ok } = await runOwnedMutation(async () => {
            await api.post(`/v1/orders/${orderId}/execute`, { price: Number(price) }, { skipErrorToast: true });
        }, owner, { successMessage: 'Transaction added', errorFallback: 'Execute failed' });
        if (ok && isCurrent(owner)) {
            await load();
        }
    };

    const cancelPending = async (orderId, rowOwner) => {
        const owner = { ...rowOwner };
        if (!isCurrent(owner)) return;
        const { ok, data: response } = await runOwnedMutation(async () => (
            await api.post(`/v1/orders/${orderId}/cancel`, null, { skipErrorToast: true })
        ), owner, { errorFallback: 'Cancel failed' });
        if (ok && isCurrent(owner)) {
            const outcome = response?.data?.data?.cancellation_status;
            showToast(outcome === 'pending' ? 'Cancellation requested; waiting for Kite confirmation.' : 'Order cancellation confirmed.', 'success');
            await load();
        }
    };

    const reconcileOrder = async (orderId, rowOwner) => {
        const owner = { ...rowOwner };
        if (!isCurrent(owner)) return;
        const operation = ++reconcileSequence.current;
        owner.reconcileOperation = operation;
        setReconciling({ id: orderId, operation });
        try {
            const { ok, data: response } = await runOwnedMutation(async () => (
                await api.post(`/v1/orders/${orderId}/reconcile`, null, { skipErrorToast: true })
            ), owner, { errorFallback: 'Order reconciliation failed' });
            if (ok && isCurrent(owner)) {
                const status = response?.data?.data?.broker_status || 'unknown';
                const inFlight = ['submitted', 'open', 'partial'].includes(status);
                showToast(
                    status === 'unknown'
                        ? 'Broker status remains unknown. Do not retry this order yet.'
                        : inFlight
                            ? `Latest broker status: ${status}. Do not submit a duplicate while it is in flight.`
                            : `Broker confirms ${status}. Check the filled quantity before retrying.`,
                    status === 'unknown' ? 'warning' : 'info',
                );
                await load();
            }
        } finally {
            if (isCurrent(owner) && reconcileSequence.current === operation) setReconciling(null);
        }
    };

    const counts = dash?.actionable_counts || dash?.recommendation_counts || {};
    const infoCounts = dash?.informational_counts || {};
    const portfolio = dash?.portfolio || {};
    const outcomes = dash?.outcomes || [];
    const infoOutcomes = dash?.informational_outcomes || [];

    return (
        <div className="container-fluid py-3">
            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Review</h1>
                    <p className="text-muted small mb-0">
                        Live observational dashboard for recommendations, insights, and recent decisions.
                        Stored ReviewEngine reports are on Reports — those numbers are not this live snapshot.
                    </p>
                </div>
                <div className="d-flex gap-2">
                    <Link className="btn btn-outline-secondary btn-sm" to={ROUTES.REVIEW_REPORTS}>Reports</Link>
                    <Link className="btn btn-outline-secondary btn-sm" to="/recommendations">Recommendations</Link>
                    <Link className="btn btn-outline-secondary btn-sm" to="/notification-history">Notifications</Link>
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={load} disabled={loading}>Refresh</button>
                </div>
            </div>

            {error ? <div className="alert alert-danger" role="alert">Review dashboard could not be loaded. Use Refresh to retry.</div> : loading || !dash ? (
                <p className="text-muted">Loading…</p>
            ) : (
                <>
                    <h2 className="h6 text-muted text-uppercase mb-2">Trade recommendations (actionable)</h2>
                    <div className="row g-3 mb-4">
                        <div className="col-md-3">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">Portfolio value</div>
                                <div className="fs-5">{formatInrCompactWhole(portfolio.portfolio_value)}</div>
                            </div>
                        </div>
                        <div className="col-md-3">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">XIRR</div>
                                <div className="fs-5">{fmtPct(portfolio.xirr != null ? Number(portfolio.xirr) * 100 : null)}</div>
                            </div>
                        </div>
                        <div className="col-md-3">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">Accepted / Rejected</div>
                                <div className="fs-5">
                                    {counts.accepted ?? 0}
                                    {' / '}
                                    {counts.rejected ?? 0}
                                </div>
                            </div>
                        </div>
                        <div className="col-md-3">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">Executed / Pending review</div>
                                <div className="fs-5">
                                    {counts.executed ?? 0}
                                    {' / '}
                                    {counts.pending_review ?? 0}
                                </div>
                            </div>
                        </div>
                    </div>

                    <h2 className="h6 text-muted text-uppercase mb-2">Market insights (HOLD / WATCH)</h2>
                    <div className="row g-3 mb-4">
                        <div className="col-md-4">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">Published</div>
                                <div className="fs-5">{infoCounts.published ?? 0}</div>
                            </div>
                        </div>
                        <div className="col-md-4">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">Expired</div>
                                <div className="fs-5">{infoCounts.expired ?? 0}</div>
                            </div>
                        </div>
                        <div className="col-md-4">
                            <div className="border rounded p-3 h-100">
                                <div className="text-muted small">Total insights</div>
                                <div className="fs-5">{infoCounts.total ?? 0}</div>
                            </div>
                        </div>
                    </div>

                    <h2 className="h5">Actionable outcomes</h2>
                    <p className="small text-muted">BUY/SELL reference price at generation vs latest close (SELL flips sign so favourable moves are positive).</p>
                    <div className="table-responsive mb-4">
                        <table className="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Symbol</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Ref</th>
                                    <th>Current</th>
                                    <th>P/L</th>
                                    <th>P/L %</th>
                                </tr>
                            </thead>
                            <tbody>
                                {outcomes.length === 0 ? (
                                    <tr><td colSpan={7} className="text-muted">No actionable outcomes yet.</td></tr>
                                ) : outcomes.map((o) => (
                                    <tr key={o.recommendation_id}>
                                        <td><strong>{o.symbol}</strong></td>
                                        <td>{o.recommendation_type}</td>
                                        <td>{o.status}</td>
                                        <td>{fmtNum(o.reference_price)}</td>
                                        <td>{fmtNum(o.current_price)}</td>
                                        <td className={o.gain_loss > 0 ? 'text-success' : o.gain_loss < 0 ? 'text-danger' : ''}>{fmtNum(o.gain_loss)}</td>
                                        <td className={o.gain_loss_pct > 0 ? 'text-success' : o.gain_loss_pct < 0 ? 'text-danger' : ''}>{fmtPct(o.gain_loss_pct)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <h2 className="h5">Insight outcomes</h2>
                    <p className="small text-muted">HOLD/WATCH price movement vs reference (informational only).</p>
                    <div className="table-responsive mb-4">
                        <table className="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Symbol</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Ref</th>
                                    <th>Current</th>
                                    <th>P/L</th>
                                    <th>P/L %</th>
                                </tr>
                            </thead>
                            <tbody>
                                {infoOutcomes.length === 0 ? (
                                    <tr><td colSpan={7} className="text-muted">No insight outcomes yet.</td></tr>
                                ) : infoOutcomes.map((o) => (
                                    <tr key={o.recommendation_id}>
                                        <td><strong>{o.symbol}</strong></td>
                                        <td>{o.recommendation_type}</td>
                                        <td>{o.status}</td>
                                        <td>{fmtNum(o.reference_price)}</td>
                                        <td>{fmtNum(o.current_price)}</td>
                                        <td className={o.gain_loss > 0 ? 'text-success' : o.gain_loss < 0 ? 'text-danger' : ''}>{fmtNum(o.gain_loss)}</td>
                                        <td className={o.gain_loss_pct > 0 ? 'text-success' : o.gain_loss_pct < 0 ? 'text-danger' : ''}>{fmtPct(o.gain_loss_pct)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <h2 className="h5">Orders</h2>
                    <div className="table-responsive mb-4">
                        <table className="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Symbol</th>
                                    <th>Side</th>
                                    <th>Qty</th>
                                    <th>Status</th>
                                    <th>Broker status</th>
                                    <th>Broker note</th>
                                    <th className="text-end">Filled</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {orders.length === 0 ? (
                                    <tr><td colSpan={9} className="text-muted">No orders.</td></tr>
                                ) : orders.map((o) => (
                                    <tr key={o.id}>
                                        <td>{o.id}</td>
                                        <td>{o.security?.symbol || o.symbol || '—'}</td>
                                        <td>{o.side}</td>
                                        <td>{o.quantity}</td>
                                        <td>{o.status}</td>
                                        <td>{o.broker_status || '—'}</td>
                                        <td>{o.broker_error_message || '—'}</td>
                                        <td className="text-end">{o.filled_quantity ?? '—'}</td>
                                        <td className="text-nowrap">
                                            {o.status === 'pending' && IN_FLIGHT_BROKER_STATUSES.has(brokerStatusOf(o)) && (
                                                <button
                                                    type="button"
                                                    className="btn btn-link btn-sm px-0 me-2"
                                                    disabled={reconciling?.id === o.id}
                                                    onClick={() => reconcileOrder(o.id, renderedOwner)}
                                                >
                                                    {reconciling?.id === o.id ? 'Reconciling…' : 'Reconcile'}
                                                </button>
                                            )}
                                            {o.status === 'pending' && (
                                                <>
                                                    {!IN_FLIGHT_BROKER_STATUSES.has(brokerStatusOf(o)) && !FINAL_NO_FILL_BROKER_STATUSES.has(brokerStatusOf(o)) && (
                                                        <button type="button" className="btn btn-link btn-sm px-0 me-2" onClick={() => executePending(o.id, renderedOwner)}>Add transaction</button>
                                                    )}
                                                    <button type="button" className="btn btn-link btn-sm px-0 text-danger" onClick={() => cancelPending(o.id, renderedOwner)}>Cancel</button>
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <h2 className="h5">Recent review decisions</h2>
                    <ul className="small">
                        {(dash.recent_reviews || []).length === 0 && <li className="text-muted">None yet.</li>}
                        {(dash.recent_reviews || []).map((r) => (
                            <li key={r.id}>
                                {r.symbol}
                                {' '}
                                —
                                {' '}
                                <strong>{r.decision}</strong>
                                {' '}
                                by
                                {' '}
                                {r.user || 'user'}
                                {' '}
                                {r.created_at ? new Date(r.created_at).toLocaleString() : ''}
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </div>
    );
}
